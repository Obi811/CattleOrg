<?php
/**
 * /api/v1/devices
 * -----------------------------------------------------------------------------
 * Verwaltung der Push-Geraete-Tokens der mobilen App (derzeit iOS/APNs).
 *
 *  POST   /api/v1/devices   -> Geraet registrieren ODER aktualisieren (Upsert)
 *  PUT    /api/v1/devices   -> wie POST (Alias)
 *  DELETE /api/v1/devices   -> Geraet abmelden (is_active = 0), token im Body
 *
 * Hinweis: /api/v1/device-tokens ist ein funktional gleichwertiger Alias dieses
 * Endpunkts (gleiche Logik via includes/device_tokens.php). Beide duerfen von
 * der iOS-App verwendet werden; neue Clients sollten /api/v1/device-tokens
 * nutzen (DELETE dort auch via ?token=).
 *
 * Authentifizierung: Bearer-Token (api_tokens), wie die uebrige API.
 * Betriebs-Kontext ist OPTIONAL: wird ein gueltiger X-Farm-Id-Header bzw.
 * farm_id-Parameter uebergeben und hat der User darauf Zugriff, wird das
 * Geraet diesem Betrieb zugeordnet (sonst NULL = alle Betriebe des Users).
 *
 * Body (JSON):
 *   {
 *     "token": "<APNs-Device-Token-Hex>",   // Pflicht
 *     "platform": "ios",                      // optional (ios|android), Standard ios
 *     "environment": "production",            // optional (sandbox|production)
 *     "device_name": "iPhone von Max",        // optional
 *     "app_version": "1.0.0",                 // optional
 *     "prefs": {                               // optional: Mitteilungsarten an/aus
 *       "weiterbehandlung": true,
 *       "wartezeit": true,
 *       "traechtigkeit": true,
 *       "hemmstoff": true
 *     }
 *   }
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../../includes/device_tokens.php';

apiRequireMethod(['POST', 'PUT', 'DELETE']);

$apiUser = apiAuthenticate($db);
$userId  = (int) $apiUser['user_id'];

$input = jsonInput();

// ---------------------------------------------------------------------------
// DELETE: Geraet abmelden (token im Body)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $token = trim((string) ($input['token'] ?? $_GET['token'] ?? ''));
    if ($token === '') {
        jsonError('VALIDATION', 'Feld "token" ist erforderlich.', 422);
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
