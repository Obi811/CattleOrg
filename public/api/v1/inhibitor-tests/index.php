<?php
/**
 * /api/v1/inhibitor-tests  (Hemmstoffproben)
 * -----------------------------------------------------------------------------
 * Verwaltung der Hemmstoffproben (Tabelle hemmstoff_probes), strikt
 * betriebsbezogen (farm_id) und mit Berechtigungspruefung.
 *
 *  GET  /api/v1/inhibitor-tests            -> Liste (paginiert)
 *       Filter: ?cattle_id=, ?status=(frei|nicht frei|versendet)
 *       Berechtigung: cattle.read
 *  POST /api/v1/inhibitor-tests            -> neue Probe als "versendet" anlegen
 *       Body: { cattle_id*, nummer*, probe_date? (Standard heute) }
 *       Berechtigung: treatments.write, kein Demo
 *  PUT  /api/v1/inhibitor-tests?id=        -> Status setzen
 *       Body: { status: "frei" | "nicht frei" }
 *       Berechtigung: treatments.write, kein Demo
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET', 'POST', 'PUT']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

// ---------------------------------------------------------------------------
// GET: Liste
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiRequirePermission($farm, 'cattle.read');
    [$page, $perPage, $offset] = apiPagination();

    $where  = ['hp.farm_id = :f'];
    $params = [':f' => $farmId];
    if (!empty($_GET['cattle_id'])) {
        $where[] = 'hp.cattle_id = :c';
        $params[':c'] = (int) $_GET['cattle_id'];
    }
    $status = $_GET['status'] ?? null;
    if (in_array($status, ['frei', 'nicht frei', 'versendet'], true)) {
        $where[] = 'hp.status = :s';
        $params[':s'] = $status;
    }
    $whereSql = implode(' AND ', $where);

    $cnt = $db->prepare("SELECT COUNT(*) FROM hemmstoff_probes hp WHERE $whereSql");
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();

    $sql = "SELECT hp.id, hp.cattle_id, c.ear_tag, hp.probe_date, hp.nummer, hp.status, hp.created_at
            FROM hemmstoff_probes hp
            JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
            WHERE $whereSql
            ORDER BY hp.probe_date DESC, hp.id DESC
            LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    jsonSuccess($stmt->fetchAll(), [
        'page' => $page, 'per_page' => $perPage, 'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// PUT: Status aktualisieren
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    apiRequirePermission($farm, 'treatments.write');
    apiRequireWrite($farm);

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonError('VALIDATION', 'Query-Parameter "id" ist erforderlich.', 422);
    }
    $input  = jsonInput();
    $status = (string) ($input['status'] ?? '');
    if (!in_array($status, ['frei', 'nicht frei'], true)) {
        jsonError('VALIDATION', 'Feld "status" muss "frei" oder "nicht frei" sein.', 422);
    }

    // Probe betriebsbezogen pruefen
    $chk = $db->prepare('SELECT id FROM hemmstoff_probes WHERE id = :id AND farm_id = :f');
    $chk->execute([':id' => $id, ':f' => $farmId]);
    if (!$chk->fetch()) {
        jsonError('NOT_FOUND', 'Hemmstoffprobe nicht gefunden.', 404);
    }

    $upd = $db->prepare('UPDATE hemmstoff_probes SET status = :s WHERE id = :id AND farm_id = :f');
    $upd->execute([':s' => $status, ':id' => $id, ':f' => $farmId]);

    $sel = $db->prepare(
        'SELECT hp.id, hp.cattle_id, c.ear_tag, hp.probe_date, hp.nummer, hp.status, hp.created_at
         FROM hemmstoff_probes hp JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
         WHERE hp.id = :id AND hp.farm_id = :f'
    );
    $sel->execute([':id' => $id, ':f' => $farmId]);
    jsonSuccess($sel->fetch());
}

// ---------------------------------------------------------------------------
// POST: neue Probe (versendet)
// ---------------------------------------------------------------------------
apiRequirePermission($farm, 'treatments.write');
apiRequireWrite($farm);

$input    = jsonInput();
$cattleId = (int) ($input['cattle_id'] ?? 0);
$nummer   = trim((string) ($input['nummer'] ?? ''));
$pdate    = trim((string) ($input['probe_date'] ?? '')) ?: date('Y-m-d');

if ($cattleId <= 0 || $nummer === '') {
    jsonError('VALIDATION', 'cattle_id und nummer sind erforderlich.', 422);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdate)) {
    jsonError('VALIDATION', 'probe_date muss das Format YYYY-MM-DD haben.', 422);
}

// Tier betriebsbezogen pruefen
$c = $db->prepare('SELECT id FROM cattle WHERE id = :id AND farm_id = :f');
$c->execute([':id' => $cattleId, ':f' => $farmId]);
if (!$c->fetch()) {
    jsonError('NOT_FOUND', 'Tier nicht gefunden.', 404);
}

$ins = $db->prepare(
    'INSERT INTO hemmstoff_probes (farm_id, cattle_id, probe_date, status, nummer)
     VALUES (:f, :c, :d, :s, :n)'
);
$ins->execute([':f' => $farmId, ':c' => $cattleId, ':d' => $pdate, ':s' => 'versendet', ':n' => $nummer]);
$newId = (int) $db->lastInsertId();

$sel = $db->prepare(
    'SELECT hp.id, hp.cattle_id, c.ear_tag, hp.probe_date, hp.nummer, hp.status, hp.created_at
     FROM hemmstoff_probes hp JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
     WHERE hp.id = :id AND hp.farm_id = :f'
);
$sel->execute([':id' => $newId, ':f' => $farmId]);

jsonSuccess($sel->fetch(), null, 201);
