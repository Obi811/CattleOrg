<?php
/**
 * GET /api/v1/user
 * -----------------------------------------------------------------------------
 * Liefert das Profil des authentifizierten Benutzers inkl. der Betriebe mit
 * den jeweils effektiven Berechtigungen (fuer die Startansicht der iOS-App).
 *
 * Antwort (data):
 *   id, username, email, is_admin, last_login,
 *   farms: [ { id, name, is_demo, role, permissions[] }, ... ]
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET']);

$apiUser = apiAuthenticate($db);

$stmt = $db->prepare('SELECT id, username, email, is_admin, last_login FROM users WHERE id = :id');
$stmt->execute([':id' => $apiUser['user_id']]);
$user = $stmt->fetch();
if (!$user) {
    jsonError('NOT_FOUND', 'Benutzer nicht gefunden.', 404);
}

jsonSuccess([
    'id'         => (int) $user['id'],
    'username'   => $user['username'],
    'email'      => $user['email'],
    'is_admin'   => (int) $user['is_admin'] === 1,
    'last_login' => $user['last_login'],
    'farms'      => apiFarmsWithPermissions($db, $apiUser),
]);
