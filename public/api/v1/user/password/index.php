<?php
/**
 * PUT /api/v1/user/password
 * -----------------------------------------------------------------------------
 * Aendert das Passwort des authentifizierten Benutzers.
 *
 * Body (JSON): { "current_password": "...", "new_password": "..." }
 *   - current_password muss korrekt sein (sonst 422 VALIDATION)
 *   - new_password mind. 8 Zeichen (sonst 422 VALIDATION)
 *
 * Nach erfolgreicher Aenderung werden ALLE anderen API-Tokens des Benutzers
 * widerrufen (das aktuell verwendete Token bleibt gueltig). Damit werden
 * andere/alte Geraete abgemeldet. Haengende Geraete-Tokens werden ueber den
 * FK (api_token_id ON DELETE CASCADE) mit entfernt.
 *
 * Hinweis: Dieser Endpunkt liegt zwei Ebenen unter v1, daher der zusaetzliche
 * "../" im require-Pfad zur bootstrap_api.php.
 */

require_once __DIR__ . '/../../bootstrap_api.php';

apiRequireMethod(['PUT']);

$apiUser = apiAuthenticate($db);

$input = jsonInput();
$current = (string) ($input['current_password'] ?? '');
$new     = (string) ($input['new_password'] ?? '');

if ($current === '' || $new === '') {
    jsonError('VALIDATION', 'current_password und new_password sind erforderlich.', 422);
}
if (strlen($new) < 8) {
    jsonError('VALIDATION', 'Das neue Passwort muss mindestens 8 Zeichen haben.', 422);
}

// Aktuellen Hash laden und pruefen
$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute([':id' => $apiUser['user_id']]);
$row = $stmt->fetch();
if (!$row) {
    jsonError('NOT_FOUND', 'Benutzer nicht gefunden.', 404);
}
if (!password_verify($current, $row['password_hash'])) {
    jsonError('VALIDATION', 'Das aktuelle Passwort ist nicht korrekt.', 422);
}

// Passwort aktualisieren (gleiche Methode wie Web-Profil/Login)
$upd = $db->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
$upd->execute([
    ':p'  => password_hash($new, PASSWORD_DEFAULT),
    ':id' => $apiUser['user_id'],
]);

// Alle ANDEREN Tokens des Benutzers widerrufen (aktuelles Token behalten)
$delTokens = $db->prepare('DELETE FROM api_tokens WHERE user_id = :u AND id <> :tid');
$delTokens->execute([':u' => $apiUser['user_id'], ':tid' => $apiUser['token_id']]);

jsonSuccess([
    'message'         => 'Passwort geaendert. Andere Sitzungen wurden abgemeldet.',
    'revoked_tokens'  => $delTokens->rowCount(),
]);
