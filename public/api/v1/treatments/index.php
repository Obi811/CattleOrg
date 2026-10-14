<?php
/**
 * /api/v1/treatments
 * -----------------------------------------------------------------------------
 * GET  -> Behandlungen des Betriebs (optional ?cattle_id=, paginiert)
 * POST -> neue Behandlung anlegen (Medikament aus globalem Katalog)
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET', 'POST']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiRequirePermission($farm, 'treatments.read');
    [$page, $perPage, $offset] = apiPagination();

    $where = ['t.farm_id = :f'];
    $params = [':f' => $farmId];
    if (!empty($_GET['cattle_id'])) { $where[] = 't.cattle_id = :c'; $params[':c'] = (int) $_GET['cattle_id']; }
    $whereSql = implode(' AND ', $where);

    $cnt = $db->prepare("SELECT COUNT(*) FROM treatments t WHERE $whereSql");
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();

    // Wartezeiten (Milch/Fleisch) aus dem globalen Medikamentenkatalog ergaenzen.
    // medications ist global (ohne farm_id), daher Join nur ueber den Namen.
    $sql = "SELECT t.id, t.cattle_id, c.ear_tag, t.treatment_date, t.medication, t.dosage, t.type, t.notes,
                   m.wz_milk, m.wz_fleisch
            FROM treatments t
            JOIN cattle c ON c.id = t.cattle_id AND c.farm_id = t.farm_id
            LEFT JOIN medications m ON m.name = t.medication
            WHERE $whereSql ORDER BY t.treatment_date DESC, t.id DESC LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $today = new DateTime('today');
    $rows = array_map(static function (array $t) use ($today): array {
        // wz_milk/wz_fleisch sind im Katalog VARCHAR -> auf int casten
        $wzMilk   = isset($t['wz_milk'])   ? (int) $t['wz_milk']   : 0;
        $wzFleisch = isset($t['wz_fleisch']) ? (int) $t['wz_fleisch'] : 0;
        $maxWz    = max($wzMilk, $wzFleisch);
        $wzEnde   = null;
        $wzAktiv  = false;
        if ($maxWz > 0 && !empty($t['treatment_date'])) {
            try {
                $ende   = (new DateTime($t['treatment_date']))->modify("+{$maxWz} days");
                $wzEnde = $ende->format('Y-m-d');
                // Wartezeit aktiv, wenn Ende heute oder in der Zukunft liegt
                $wzAktiv = $ende >= $today;
            } catch (Exception $e) {
                // ungueltiges Datum -> keine Wartezeit
            }
        }
        return [
            'id'             => (int) $t['id'],
            'cattle_id'      => (int) $t['cattle_id'],
            'ear_tag'        => $t['ear_tag'],
            'treatment_date' => $t['treatment_date'],
            'medication'     => $t['medication'],
            'dosage'         => $t['dosage'],
            'type'           => $t['type'],
            'notes'          => $t['notes'],
            'wz_milk'        => $t['wz_milk'] !== null ? $wzMilk : null,
            'wz_fleisch'     => $t['wz_fleisch'] !== null ? $wzFleisch : null,
            'wz_ende'        => $wzEnde,
            'wz_aktiv'       => $wzAktiv,
        ];
    }, $stmt->fetchAll());

    jsonSuccess($rows, [
        'page' => $page, 'per_page' => $perPage, 'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ]);
}

// POST
apiRequirePermission($farm, 'treatments.write');
apiRequireWrite($farm);

$input = jsonInput();
$cattleId = (int) ($input['cattle_id'] ?? 0);
$medication = trim((string) ($input['medication'] ?? ''));
$type = (string) ($input['type'] ?? 'Sonstige');

// Tier pruefen
$c = $db->prepare('SELECT id FROM cattle WHERE id = :id AND farm_id = :f');
$c->execute([':id' => $cattleId, ':f' => $farmId]);
if (!$c->fetch()) {
    jsonError('NOT_FOUND', 'Tier nicht gefunden.', 404);
}
// Medikament aus globalem Katalog pruefen
$m = $db->prepare('SELECT COUNT(*) FROM medications WHERE name = :n');
$m->execute([':n' => $medication]);
if ((int) $m->fetchColumn() === 0) {
    jsonError('VALIDATION', 'Unbekanntes Medikament. Bitte aus dem globalen Katalog waehlen.', 422);
}
if (!in_array($type, treatmentTypes(), true)) { $type = 'Sonstige'; }

$ins = $db->prepare(
    'INSERT INTO treatments (farm_id, cattle_id, treatment_date, medication, dosage, type, notes)
     VALUES (:f, :c, :d, :med, :dos, :t, :n)'
);
$ins->execute([
    ':f' => $farmId, ':c' => $cattleId,
    ':d' => !empty($input['treatment_date']) ? $input['treatment_date'] : date('Y-m-d'),
    ':med' => $medication, ':dos' => $input['dosage'] ?? null,
    ':t' => $type, ':n' => $input['notes'] ?? null,
]);

jsonSuccess(['id' => (int) $db->lastInsertId()], null, 201);
