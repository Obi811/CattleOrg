<?php
/**
 * /api/v1/inseminations
 * -----------------------------------------------------------------------------
 * GET  -> Besamungen des Betriebs (optional ?cattle_id=)
 * POST -> neue Besamung anlegen
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET', 'POST']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiRequirePermission($farm, 'cattle.read');
    [$page, $perPage, $offset] = apiPagination();

    $where = ['i.farm_id = :f'];
    $params = [':f' => $farmId];
    if (!empty($_GET['cattle_id'])) { $where[] = 'i.cattle_id = :c'; $params[':c'] = (int) $_GET['cattle_id']; }
    $whereSql = implode(' AND ', $where);

    $cnt = $db->prepare("SELECT COUNT(*) FROM inseminations i WHERE $whereSql");
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();

    $sql = "SELECT i.id, i.cattle_id, c.ear_tag, i.date, i.bull_name, i.type
            FROM inseminations i JOIN cattle c ON c.id = i.cattle_id
            WHERE $whereSql ORDER BY i.date DESC, i.id DESC LIMIT :lim OFFSET :off";
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

// POST
apiRequirePermission($farm, 'inseminations.write');
apiRequireWrite($farm);

$input = jsonInput();
$cattleId = (int) ($input['cattle_id'] ?? 0);
$date = (string) ($input['date'] ?? '');
$type = (string) ($input['type'] ?? '');

$c = $db->prepare('SELECT id FROM cattle WHERE id = :id AND farm_id = :f');
$c->execute([':id' => $cattleId, ':f' => $farmId]);
if (!$c->fetch()) { jsonError('NOT_FOUND', 'Tier nicht gefunden.', 404); }
if ($date === '') { jsonError('VALIDATION', 'Datum (date) ist erforderlich.', 422); }
if (!in_array($type, ['besamt', 'gestiert'], true)) { jsonError('VALIDATION', 'Ungueltige Art (besamt|gestiert).', 422); }

$ins = $db->prepare('INSERT INTO inseminations (farm_id, cattle_id, date, bull_name, type) VALUES (:f, :c, :d, :b, :t)');
$ins->execute([':f' => $farmId, ':c' => $cattleId, ':d' => $date, ':b' => $input['bull_name'] ?? null, ':t' => $type]);

jsonSuccess(['id' => (int) $db->lastInsertId()], null, 201);
