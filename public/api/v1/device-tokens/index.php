<?php
/**
 * /api/v1/device-tokens
 * -----------------------------------------------------------------------------
 * Funktional gleichwertiger Alias zu /api/v1/devices. Verwendet dieselbe
 * Implementierung (includes/device_tokens.php), es gibt KEINE zweite Logik.
 *
 *  POST   /api/v1/device-tokens          -> Geraet registrieren/aktualisieren (Upsert)
 *  PUT    /api/v1/device-tokens          -> wie POST (Alias)
 *  DELETE /api/v1/device-tokens?token=   -> Geraet abmelden (token via Query ODER Body)
 *
 * Unterschied zu /api/v1/devices: DELETE akzeptiert das Token zusaetzlich als
 * Query-Parameter ?token=. Ansonsten identisches Verhalten/Antwortformat.
 *
 * Authentifizierung: Bearer-Token. Betriebskontext (X-Farm-Id/farm_id) optional.
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../../includes/device_tokens.php';

apiRequireMethod(['POST', 'PUT', 'DELETE']);

$apiUser = apiAuthenticate($db);
$userId  = (int) $apiUser['user_id'];

$input = jsonInput();

// ---------------------------------------------------------------------------
// DELETE: Geraet abmelden (token via ?token= oder Body)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $token = trim((string) ($_GET['token'] ?? $input['token'] ?? ''));
    if ($token === '') {
        jsonError('VALIDATION', 'Parameter "token" (Query oder Body) ist erforderlich.', 422);
    }
    $removed = deviceTokenDeactivate($db, $userId, $token);
    jsonSuccess(['message' => 'Geraet abgemeldet.', 'removed' => $removed]);
}

// ---------------------------------------------------------------------------
// POST / PUT: Geraet registrieren oder aktualisieren (Upsert)
// ---------------------------------------------------------------------------
$device = deviceTokenUpsert($db, $apiUser, $input);
$device['message'] = 'Geraet registriert/aktualisiert.';

jsonSuccess($device, null, 201);
