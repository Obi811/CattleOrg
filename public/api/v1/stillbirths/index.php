<?php
/**
 * /api/v1/stillbirths
 * -----------------------------------------------------------------------------
 * GET    -> Totgeburten (Filter: cattle_id = Mutter-Kuh; paginiert). Einzeln ?id=
 * POST   -> Totgeburt anlegen
 * DELETE -> Totgeburt loeschen (?id=)
 *
 * Totgeburten sind einer Mutter-Kuh zugeordnet (mother_cattle_id) und zaehlen
 * wie eine Kalbung. Lesen: cattle.read   Schreiben: cattle.write (+ nicht Demo).
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET', 'POST', 'DELETE']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// GET -> Liste / Einzeln
// ---------------------------------------------------------------------------
if ($method === 'GET') {
    apiRequirePermission($farm, 'cattle.read');

    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare(
            'SELECT s.*, c.ear_tag AS mother_ear_tag
               FROM stillbirths s
               JOIN cattle c ON c.id = s.mother_cattle_id AND c.farm_id = s.farm_id
              WHERE s.id = :id AND s.farm_id = :f'
        );
        $stmt->execute([':id' => $id, ':f' => $farmId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonError('NOT_FOUND', 'Totgeburt nicht gefunden.', 404);
        }
        jsonSuccess($row);
    }

    [$page, $perPage, $offset] = apiPagination();

    $where  = ['s.farm_id = :f'];
    $params = [':f' => $farmId];
    $cattleId = (int) ($_GET['cattle_id'] ?? 0);
    if ($cattleId > 0) { $where[] = 's.mother_cattle_id = :c'; $params[':c'] = $cattleId; }
    $whereSql = implode(' AND ', $where);

    $cnt = $db->prepare("SELECT COUNT(*) FROM stillbirths s WHERE $whereSql");
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();

    $sql = "SELECT s.id, s.mother_cattle_id, c.ear_tag AS mother_ear_tag,
                   s.birth_date, s.sex, s.comment, s.created_at
              FROM stillbirths s
              JOIN cattle c ON c.id = s.mother_cattle_id AND c.farm_id = s.farm_id
             WHERE $whereSql
             ORDER BY s.birth_date DESC, s.id DESC
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
    apiRequirePermission($farm, 'cattle.write');
    apiRequireWrite($farm);

    $input    = jsonInput();
    $cattleId = (int) ($input['cattle_id'] ?? ($input['mother_cattle_id'] ?? 0));
    $date     = trim((string) ($input['birth_date'] ?? ''));
    $sex      = trim((string) ($input['sex'] ?? 'unbekannt'));
    $comment  = trim((string) ($input['comment'] ?? ''));

    if ($cattleId <= 0) {
        jsonError('VALIDATION', 'Feld cattle_id (Mutter-Kuh) ist erforderlich.', 422);
    }
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonError('VALIDATION', 'Feld birth_date (YYYY-MM-DD) ist erforderlich.', 422);
    }
    if (!in_array($sex, ['maennlich', 'weiblich', 'unbekannt'], true)) {
        $sex = 'unbekannt';
    }

    // Mutter-Kuh streng betriebsbezogen pruefen
    $cs = $db->prepare('SELECT id FROM cattle WHERE id = :id AND farm_id = :f');
    $cs->execute([':id' => $cattleId, ':f' => $farmId]);
    if (!$cs->fetch()) {
        jsonError('NOT_FOUND', 'Mutter-Kuh nicht gefunden.', 404);
    }

    $ins = $db->prepare(
        'INSERT INTO stillbirths (farm_id, mother_cattle_id, birth_date, sex, comment, created_by)
         VALUES (:f, :c, :d, :s, :k, :u)'
    );
    $ins->execute([
        ':f' => $farmId, ':c' => $cattleId, ':d' => $date, ':s' => $sex,
        ':k' => $comment !== '' ? $comment : null, ':u' => (int) $apiUser['user_id'],
    ]);
    $newId = (int) $db->lastInsertId();
    $sel = $db->prepare('SELECT * FROM stillbirths WHERE id = :id AND farm_id = :f');
    $sel->execute([':id' => $newId, ':f' => $farmId]);
    jsonSuccess($sel->fetch(), null, 201);
}

// ---------------------------------------------------------------------------
// DELETE -> loeschen
// ---------------------------------------------------------------------------
apiRequirePermission($farm, 'cattle.write');
apiRequireWrite($farm);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonError('VALIDATION', 'Query-Parameter id ist erforderlich.', 422);
}
$del = $db->prepare('DELETE FROM stillbirths WHERE id = :id AND farm_id = :f');
$del->execute([':id' => $id, ':f' => $farmId]);
if ($del->rowCount() === 0) {
    jsonError('NOT_FOUND', 'Totgeburt nicht gefunden.', 404);
}
jsonSuccess(['deleted' => true, 'id' => $id]);
