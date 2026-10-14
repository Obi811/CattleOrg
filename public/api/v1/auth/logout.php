<?php
/**
 * POST /api/v1/auth/logout
 * -----------------------------------------------------------------------------
 * Widerruft das aktuell verwendete Bearer-Token (loescht es).
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['POST']);

$apiUser = apiAuthenticate($db);

// Zuerst die mit diesem Token verknuepften Push-Geraete abmelden.
// (Der Fremdschluessel ON DELETE CASCADE wuerde dies zwar ebenfalls erledigen,
//  wir loeschen aber explizit, damit die Logik auch ohne Migration 010 greift.)
try {
    $delDev = $db->prepare('DELETE FROM device_tokens WHERE api_token_id = :tid');
    $delDev->execute([':tid' => $apiUser['token_id']]);
} catch (\PDOException $e) {
    // Spalte api_token_id evtl. noch nicht vorhanden -> ignorieren.
}

$del = $db->prepare('DELETE FROM api_tokens WHERE id = :id');
$del->execute([':id' => $apiUser['token_id']]);

jsonSuccess(['message' => 'Abgemeldet. Token wurde widerrufen.']);
