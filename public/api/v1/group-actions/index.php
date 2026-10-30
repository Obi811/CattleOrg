<?php
/**
 * /api/v1/group-actions
 * -----------------------------------------------------------------------------
 * GET  -> Tiere einer Box (aktive Tiere mit offenem Stallaufenthalt in dieser
 *         Box). Query: ?stall=BoxA
 * POST -> Gruppenaktion pro Box fuer mehrere Tiere auf einmal:
 *         { "action": "move|inactivate|treat|plan",
 *           "cattle_ids": [1,2,3], ... }
 *
 *   action=move      -> target_stall (umziehen), date optional
 *   action=inactivate-> date optional, grund optional (abgang + Inaktiv)
 *   action=treat     -> medication, type, dosage?, notes?, date? (Behandlung je Tier)
 *   action=plan      -> medication, type, dosage?, notes?, planned_date (geplante Beh.)
 *
 * Umziehen/Inaktivieren: cattle.write. Behandeln/Planen: treatments.write.
 * Demo-Betrieb: 403 DEMO_READONLY.
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../../includes/planned_treatments.php';

apiRequireMethod(['GET', 'POST']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

// Hilfsfunktion: aktive Tiere mit offenem Stallaufenthalt in dieser Box
function apiLoadAnimalsInStall(PDO $db, int $farmId, string $stall): array
{
    $stmt = $db->prepare(
        'SELECT c.id, c.ear_tag, c.rasse, c.geschlecht
           FROM cattle c
           JOIN stall_history sh ON sh.cattle_id = c.id AND sh.farm_id = c.farm_id
                AND sh.end_date IS NULL AND sh.stall_name = :s
          WHERE c.farm_id = :f AND c.status = "Aktiv"
          ORDER BY c.ear_tag'
    );
    $stmt->execute([':s' => $stall, ':f' => $farmId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// GET -> Tiere einer Box
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiRequirePermission($farm, 'cattle.read');
    $stall = trim((string) ($_GET['stall'] ?? ''));
    if ($stall === '') {
        jsonError('VALIDATION', 'Query-Parameter stall ist erforderlich.', 422);
    }
    $animals = apiLoadAnimalsInStall($db, $farmId, $stall);
    jsonSuccess($animals, ['stall' => $stall, 'total' => count($animals)]);
}

// ---------------------------------------------------------------------------
// POST -> Gruppenaktion
// ---------------------------------------------------------------------------
apiRequireWrite($farm);

$input  = jsonInput();
$action = (string) ($input['action'] ?? '');
$cattleIds = array_values(array_unique(array_map('intval', (array) ($input['cattle_ids'] ?? []))));
$cattleIds = array_filter($cattleIds, static fn ($v) => $v > 0);

if (!in_array($action, ['move', 'inactivate', 'treat', 'plan'], true)) {
    jsonError('VALIDATION', 'Feld action muss move, inactivate, treat oder plan sein.', 422);
}
if (!$cattleIds) {
    jsonError('VALIDATION', 'Feld cattle_ids (Liste von Tier-IDs) ist erforderlich.', 422);
}

// Tiere streng betriebsbezogen & aktiv pruefen
$place = implode(',', array_fill(0, count($cattleIds), '?'));
$chk = $db->prepare("SELECT id FROM cattle WHERE farm_id = ? AND id IN ($place)");
$chk->execute(array_merge([$farmId], $cattleIds));
$valid = array_map('intval', array_column($chk->fetchAll(), 'id'));
if (count($valid) !== count($cattleIds)) {
    jsonError('VALIDATION', 'Mindestens eine Tier-ID gehoert nicht zu diesem Betrieb.', 422);
}

$uid = (int) $apiUser['user_id'];
$done = 0;

try {
    $db->beginTransaction();

    if ($action === 'move') {
        apiRequirePermission($farm, 'cattle.write');
        $target = trim((string) ($input['target_stall'] ?? ''));
        $date   = trim((string) ($input['date'] ?? '')) ?: date('Y-m-d');
        if ($target === '') {
            $db->rollBack();
            jsonError('VALIDATION', 'Feld target_stall ist erforderlich.', 422);
        }
        $close = $db->prepare('UPDATE stall_history SET end_date = :d WHERE cattle_id = :c AND farm_id = :f AND end_date IS NULL');
        $ins   = $db->prepare('INSERT INTO stall_history (farm_id, cattle_id, stall_name, start_date) VALUES (:f, :c, :s, :d)');
        foreach ($valid as $cid) {
            $close->execute([':d' => $date, ':c' => $cid, ':f' => $farmId]);
            $ins->execute([':f' => $farmId, ':c' => $cid, ':s' => $target, ':d' => $date]);
            $done++;
        }
    } elseif ($action === 'inactivate') {
        apiRequirePermission($farm, 'cattle.write');
        $date  = trim((string) ($input['date'] ?? '')) ?: date('Y-m-d');
        $grund = trim((string) ($input['grund'] ?? ''));
        $upd   = $db->prepare('UPDATE cattle SET abgang = :d, status = :s, abgang_grund = :g WHERE id = :id AND farm_id = :f');
        $close = $db->prepare('UPDATE stall_history SET end_date = :d WHERE cattle_id = :c AND farm_id = :f AND end_date IS NULL');
        foreach ($valid as $cid) {
            $upd->execute([':d' => $date, ':s' => 'Inaktiv', ':g' => $grund !== '' ? $grund : null, ':id' => $cid, ':f' => $farmId]);
            $close->execute([':d' => $date, ':c' => $cid, ':f' => $farmId]);
            $done++;
        }
    } elseif ($action === 'treat') {
        apiRequirePermission($farm, 'treatments.write');
        $med    = trim((string) ($input['medication'] ?? ''));
        $type   = (string) ($input['type'] ?? 'Sonstige');
        $dosage = isset($input['dosage']) ? trim((string) $input['dosage']) : '';
        $notes  = isset($input['notes']) ? trim((string) $input['notes']) : '';
        $date   = trim((string) ($input['date'] ?? '')) ?: date('Y-m-d');
        $ms = $db->prepare('SELECT COUNT(*) FROM medications WHERE name = :n');
        $ms->execute([':n' => $med]);
        if ($med === '' || (int) $ms->fetchColumn() === 0) {
            $db->rollBack();
            jsonError('VALIDATION', 'Bitte ein gueltiges Medikament aus dem Katalog waehlen.', 422);
        }
        if (!in_array($type, treatmentTypes(), true)) { $type = 'Sonstige'; }
        $ins = $db->prepare('INSERT INTO treatments (farm_id, cattle_id, treatment_date, medication, dosage, type, notes) VALUES (:f, :c, :d, :m, :dos, :t, :n)');
        foreach ($valid as $cid) {
            $ins->execute([':f' => $farmId, ':c' => $cid, ':d' => $date, ':m' => $med,
                ':dos' => $dosage !== '' ? $dosage : null, ':t' => $type, ':n' => $notes !== '' ? $notes : null]);
            $done++;
        }
    } else { // plan
        apiRequirePermission($farm, 'treatments.write');
        $med    = trim((string) ($input['medication'] ?? ''));
        $type   = (string) ($input['type'] ?? 'Sonstige');
        $dosage = isset($input['dosage']) ? trim((string) $input['dosage']) : null;
        $notes  = isset($input['notes']) ? trim((string) $input['notes']) : null;
        $pdate  = trim((string) ($input['planned_date'] ?? ''));
        foreach ($valid as $cid) {
            $err = null;
            $pid = createPlannedTreatment($db, $farmId, $cid, $pdate, $med, $type, $dosage, $notes, $uid, $err);
            if ($pid === null) {
                $db->rollBack();
                jsonError('VALIDATION', $err ?? 'Planen fehlgeschlagen.', 422);
            }
            $done++;
        }
    }

    $db->commit();
} catch (Throwable $ex) {
    if ($db->inTransaction()) { $db->rollBack(); }
    jsonError('SERVER_ERROR', 'Gruppenaktion fehlgeschlagen.', 500);
}

jsonSuccess(['action' => $action, 'processed' => $done, 'cattle_ids' => array_values($valid)]);
