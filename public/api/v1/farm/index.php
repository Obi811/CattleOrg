<?php
/**
 * GET /api/v1/farm
 * -----------------------------------------------------------------------------
 * Liefert die Betriebe, auf die der authentifizierte Benutzer Zugriff hat,
 * jeweils inkl. Rolle und effektiven Berechtigungen (permissions[]).
 * System-Admins erhalten alle aktiven Betriebe mit Owner-Rechten.
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET']);

$apiUser = apiAuthenticate($db);

jsonSuccess(apiFarmsWithPermissions($db, $apiUser));
