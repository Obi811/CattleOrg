<?php
/**
 * public/search_mutter.php
 * -----------------------------------------------------------------------------
 * AJAX-Endpunkt fuer die Muttertier-Suche.
 *
 * Behebt die kritische Sicherheitsluecke der Altanwendung: HIER wird
 * Authentifizierung erzwungen UND streng auf den aktuellen Betrieb (farm_id)
 * gefiltert. So sind keine fremden Betriebsdaten abfragbar.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
requireFarmAccess();
requirePermission('cattle.read');
$farmId = getCurrentFarmId();

$q = normalizeEarTag($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$stmt = $db->prepare(
    'SELECT id, ear_tag FROM cattle
     WHERE farm_id = :f AND ear_tag LIKE :q
     ORDER BY ear_tag LIMIT 15'
);
$stmt->execute([':f' => $farmId, ':q' => '%' . $q . '%']);
$rows = $stmt->fetchAll();

echo json_encode(['results' => $rows], JSON_UNESCAPED_UNICODE);
