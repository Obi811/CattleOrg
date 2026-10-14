<?php
/**
 * POST /api/v1/auth/login
 * -----------------------------------------------------------------------------
 * Authentifiziert per Benutzername/Passwort und liefert ein Bearer-Token
 * sowie die Liste der zugaenglichen Betriebe (fuer die Betriebsauswahl).
 *
 * Body (JSON): { "username": "...", "password": "...", "device_name": "..." }
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['POST']);

$input = jsonInput();
$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');
$device   = trim((string) ($input['device_name'] ?? '')) ?: null;

if ($username === '' || $password === '') {
    jsonError('VALIDATION', 'Benutzername und Passwort sind erforderlich.', 422);
}

$stmt = $db->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
$stmt->execute([':u' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    jsonError('INVALID_CREDENTIALS', 'Benutzername oder Passwort ist falsch.', 401);
}

// last_login aktualisieren
$db->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')->execute([':id' => (int) $user['id']]);

// Token erzeugen
$token = createApiToken($db, (int) $user['id'], $device);

// Betriebe ermitteln
if ((int) $user['is_admin'] === 1) {
    $farms = $db->query('SELECT id, name, is_demo FROM farms WHERE is_active = 1 ORDER BY name')->fetchAll();
} else {
    $farms = getUserFarms($db, (int) $user['id']);
}

jsonSuccess([
    'token'      => $token,
    'token_type' => 'Bearer',
    'expires_in_days' => API_TOKEN_TTL_DAYS,
    'user' => [
        'id'       => (int) $user['id'],
        'username' => $user['username'],
        'is_admin' => (int) $user['is_admin'] === 1,
    ],
    'farms' => array_map(fn($f) => [
        'id'      => (int) $f['id'],
        'name'    => $f['name'],
        'is_demo' => (int) ($f['is_demo'] ?? 0) === 1,
        'role'    => $f['role'] ?? 'admin',
    ], $farms),
]);
