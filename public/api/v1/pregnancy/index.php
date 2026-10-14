<?php
/**
 * /api/v1/pregnancy
 * -----------------------------------------------------------------------------
 * GET  -> Traechtigkeitskontrollen des Betriebs (optional ?cattle_id=)
 * POST -> neue Kontrolle anlegen
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET', 'POST']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiRequirePermission($farm, 'cattle.read');
    [$page, $perPage, $offset] = apiPagination();

    $where = ['p.farm_id = :f'];
    $params = [':f' => $farmId];
    if (!empty($_GET['cattle_id'])) { $where[] = 'p.cattle_id = :c'; $params[':c'] = (int) $_GET['cattle_id']; }
    $whereSql = implode(' AND ', $where);

    $cnt = $db->prepare("SELECT COUNT(*) FROM pregnancy_checks p WHERE $whereSql");
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();

    $sql = "SELECT p.id, p.cattle_id, c.ear_tag, p.check_date, p.result, p.comment
            FROM pregnancy_checks p JOIN cattle c ON c.id = p.cattle_id
            WHERE $whereSql ORDER BY p.check_date DESC, p.id DESC LIMIT :lim OFFSET :off";
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
apiRequirePermission($farm, 'pregnancy.write');
apiRequireWrite($farm);

$input = jsonInput();
$cattleId = (int) ($input['cattle_id'] ?? 0);
$date = (string) ($input['check_date'] ?? '');
$result = (string) ($input['result'] ?? '');

$c = $db->prepare('SELECT id FROM cattle WHERE id = :id AND farm_id = :f');
$c->execute([':id' => $cattleId, ':f' => $farmId]);
if (!$c->fetch()) { jsonError('NOT_FOUND', 'Tier nicht gefunden.', 404); }
if ($date === '') { jsonError('VALIDATION', 'check_date ist erforderlich.', 422); }
if (!in_array($result, ['+', '-', '?'], true)) { jsonError('VALIDATION', 'Ungueltiges Ergebnis (+|-|?).', 422); }

$ins = $db->prepare('INSERT INTO pregnancy_checks (farm_id, cattle_id, check_date, result, comment) VALUES (:f, :c, :d, :r, :k)');
$ins->execute([':f' => $farmId, ':c' => $cattleId, ':d' => $date, ':r' => $result, ':k' => $input['comment'] ?? null]);

jsonSuccess(['id' => (int) $db->lastInsertId()], null, 201);
