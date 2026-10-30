<?php
/**
 * /api/v1/planned-treatments
 * -----------------------------------------------------------------------------
 * GET    -> Liste geplanter Behandlungen (Filter: cattle_id, status, from, to;
 *           paginiert). Einzeln via ?id=
 * POST   -> neue geplante Behandlung anlegen
 * PUT    -> geplante Behandlung bearbeiten (?id=, nur solange status=geplant)
 * DELETE -> geplante Behandlung loeschen (?id=)
 *
 * Erfordert Bearer-Token + Betriebskontext (X-Farm-Id).
 * Lesen: treatments.read   Schreiben: treatments.write (+ nicht Demo).
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../../includes/planned_treatments.php';

apiRequireMethod(['GET', 'POST', 'PUT', 'DELETE']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// GET -> Liste / Einzeln
// ---------------------------------------------------------------------------
if ($method === 'GET') {
    apiRequirePermission($farm, 'treatments.read');

    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare(
            'SELECT pt.*, c.ear_tag
               FROM planned_treatments pt
               JOIN cattle c ON c.id = pt.cattle_id AND c.farm_id = pt.farm_id
              WHERE pt.id = :id AND pt.farm_id = :f'
        );
        $stmt->execute([':id' => $id, ':f' => $farmId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonError('NOT_FOUND', 'Geplante Behandlung nicht gefunden.', 404);
        }
        jsonSuccess($row);
    }

    [$page, $perPage, $offset] = apiPagination();

    $where  = ['pt.farm_id = :f'];
    $params = [':f' => $farmId];

    $cattleId = (int) ($_GET['cattle_id'] ?? 0);
    if ($cattleId > 0) { $where[] = 'pt.cattle_id = :c'; $params[':c'] = $cattleId; }

    $status = (string) ($_GET['status'] ?? '');
    if (in_array($status, ['geplant', 'erledigt', 'verworfen'], true)) {
        $where[] = 'pt.status = :s'; $params[':s'] = $status;
    }

    $from = trim((string) ($_GET['from'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'pt.planned_date >= :from'; $params[':from'] = $from;
    }
    $to = trim((string) ($_GET['to'] ?? ''));
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'pt.planned_date <= :to'; $params[':to'] = $to;
    }

    $whereSql = implode(' AND ', $where);

    $cnt = $db->prepare("SELECT COUNT(*) FROM planned_treatments pt WHERE $whereSql");
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();

    $sql = "SELECT pt.id, pt.cattle_id, c.ear_tag, pt.planned_date, pt.medication,
                   pt.dosage, pt.type, pt.notes, pt.status, pt.treatment_id,
                   pt.created_at, pt.done_at
              FROM planned_treatments pt
              JOIN cattle c ON c.id = pt.cattle_id AND c.farm_id = pt.farm_id
             WHERE $whereSql
             ORDER BY pt.planned_date ASC, c.ear_tag
             LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    jsonSuccess($stmt->fetchAll(), [
        'page' => $page, 'per_page' => $perPage, 'total' => $total,
        'total_pages' => (int) ceil($total / max(1, $perPage)),
    ]);
}

// ---------------------------------------------------------------------------
// POST -> anlegen
// ---------------------------------------------------------------------------
if ($method === 'POST') {
    apiRequirePermission($farm, 'treatments.write');
    apiRequireWrite($farm);

    $input    = jsonInput();
    $cattleId = (int) ($input['cattle_id'] ?? 0);
    $date     = trim((string) ($input['planned_date'] ?? ''));
    $med      = trim((string) ($input['medication'] ?? ''));
    $type     = (string) ($input['type'] ?? 'Sonstige');
    $dosage   = isset($input['dosage']) ? (string) $input['dosage'] : null;
    $notes    = isset($input['notes']) ? (string) $input['notes'] : null;

    $error = null;
    $newId = createPlannedTreatment($db, $farmId, $cattleId, $date, $med, $type, $dosage, $notes, (int) $apiUser['user_id'], $error);
    if ($newId === null) {
        jsonError('VALIDATION', $error ?? 'Anlegen fehlgeschlagen.', 422);
    }
    $sel = $db->prepare('SELECT * FROM planned_treatments WHERE id = :id AND farm_id = :f');
    $sel->execute([':id' => $newId, ':f' => $farmId]);
    jsonSuccess($sel->fetch(), null, 201);
}

// ---------------------------------------------------------------------------
// PUT -> bearbeiten (nur solange geplant)
// ---------------------------------------------------------------------------
if ($method === 'PUT') {
    apiRequirePermission($farm, 'treatments.write');
    apiRequireWrite($farm);

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonError('VALIDATION', 'Query-Parameter id ist erforderlich.', 422);
    }
    $cur = $db->prepare("SELECT * FROM planned_treatments WHERE id = :id AND farm_id = :f");
    $cur->execute([':id' => $id, ':f' => $farmId]);
    $plan = $cur->fetch();
    if (!$plan) {
        jsonError('NOT_FOUND', 'Geplante Behandlung nicht gefunden.', 404);
    }
    if ($plan['status'] !== 'geplant') {
        jsonError('CONFLICT', 'Nur offene (geplante) Behandlungen koennen bearbeitet werden.', 409);
    }

    $input = jsonInput();
    $date  = trim((string) ($input['planned_date'] ?? $plan['planned_date']));
    $med   = trim((string) ($input['medication'] ?? $plan['medication']));
    $type  = (string) ($input['type'] ?? $plan['type']);
    $dosage = array_key_exists('dosage', $input) ? (string) $input['dosage'] : (string) ($plan['dosage'] ?? '');
    $notes  = array_key_exists('notes', $input) ? (string) $input['notes'] : (string) ($plan['notes'] ?? '');

    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonError('VALIDATION', 'Bitte ein gueltiges geplantes Datum (JJJJ-MM-TT) angeben.', 422);
    }
    $ms = $db->prepare('SELECT COUNT(*) FROM medications WHERE name = :n');
    $ms->execute([':n' => $med]);
    if ($med === '' || (int) $ms->fetchColumn() === 0) {
        jsonError('VALIDATION', 'Bitte ein gueltiges Medikament aus dem Katalog waehlen.', 422);
    }
    if (!in_array($type, treatmentTypes(), true)) { $type = 'Sonstige'; }

    $upd = $db->prepare(
        'UPDATE planned_treatments
            SET planned_date = :d, medication = :m, dosage = :dos, type = :t, notes = :n,
                updated_at = NOW()
          WHERE id = :id AND farm_id = :f'
    );
    $upd->execute([
        ':d' => $date, ':m' => $med,
        ':dos' => $dosage !== '' ? $dosage : null,
        ':t' => $type, ':n' => $notes !== '' ? $notes : null,
        ':id' => $id, ':f' => $farmId,
    ]);
    $sel = $db->prepare('SELECT * FROM planned_treatments WHERE id = :id AND farm_id = :f');
    $sel->execute([':id' => $id, ':f' => $farmId]);
    jsonSuccess($sel->fetch());
}

// ---------------------------------------------------------------------------
// DELETE -> loeschen
// ---------------------------------------------------------------------------
apiRequirePermission($farm, 'treatments.write');
apiRequireWrite($farm);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonError('VALIDATION', 'Query-Parameter id ist erforderlich.', 422);
}
$ok = deletePlannedTreatment($db, $farmId, $id);
if (!$ok) {
    jsonError('NOT_FOUND', 'Geplante Behandlung nicht gefunden.', 404);
}
jsonSuccess(['deleted' => true, 'id' => $id]);
