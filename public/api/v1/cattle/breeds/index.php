<?php
/**
 * GET /api/v1/cattle/breeds
 * -----------------------------------------------------------------------------
 * Liefert die im Betrieb vorhandenen Rassen als einfache Auswahlliste
 * (DISTINCT, alphabetisch sortiert). Dient der iOS-App als Quelle fuer den
 * Rasse-Filter der Tierliste (siehe GET /api/v1/cattle?rasse=...).
 *
 * Erfordert Bearer-Token + Betriebskontext (X-Farm-Id) + Berechtigung
 * cattle.read. Streng auf farm_id beschraenkt, vorbereitete Statements.
 *
 * Antwort (data): Array von Rassen-Strings, z. B. ["Fleckvieh", "Holstein"].
 *
 * Hinweis: Endpunkt zwei Ebenen unter v1 -> zusaetzliches "../" zur bootstrap.
 */

require_once __DIR__ . '/../../bootstrap_api.php';

apiRequireMethod(['GET']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

apiRequirePermission($farm, 'cattle.read');

$stmt = $db->prepare(
    "SELECT DISTINCT rasse FROM cattle
     WHERE farm_id = :f AND rasse IS NOT NULL AND rasse <> ''
     ORDER BY rasse"
);
$stmt->execute([':f' => $farmId]);
$breeds = $stmt->fetchAll(PDO::FETCH_COLUMN);

jsonSuccess($breeds);
