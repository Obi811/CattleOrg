<?php
/**
 * GET /api/v1/stalls
 * -----------------------------------------------------------------------------
 * Liefert die aktiven Staelle des Betriebs (X-Farm-Id erforderlich).
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
apiRequirePermission($farm, 'cattle.read');

$stalls = getStallList($db, $farm['farm_id']);

jsonSuccess(array_values($stalls), ['total' => count($stalls)]);
