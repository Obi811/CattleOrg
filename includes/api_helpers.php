<?php
/**
 * api_helpers.php
 * -----------------------------------------------------------------------------
 * Hilfsfunktionen fuer die REST-API (/public/api/v1):
 *  - einheitliches JSON-Response-Format
 *  - Bearer-Token-Authentifizierung gegen die Tabelle api_tokens
 *  - Mandanten-/Berechtigungskontext fuer API-Requests
 *  - einfaches Rate-Limiting pro Token
 *
 * Diese Datei bindet bewusst NICHT auth.php (Web-Session) ein, sondern stellt
 * eine eigene, token-basierte Authentifizierung bereit. Die Mandantenlogik aus
 * tenant.php (roleDefaults/computePermissions) wird wiederverwendet.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';     // $db, env(), Konstanten
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/tenant.php';     // roleDefaults(), computePermissions()

/**
 * Sendet eine JSON-Antwort und beendet das Skript.
 */
function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Erfolgs-Antwort im Standardformat.
 */
function jsonSuccess($data = null, ?array $meta = null, int $status = 200): void
{
    $payload = ['success' => true, 'data' => $data];
    if ($meta !== null) {
        $payload['meta'] = $meta;
    }
    jsonResponse($payload, $status);
}

/**
 * Fehler-Antwort im Standardformat.
 */
function jsonError(string $code, string $message, int $status = 400): void
{
    jsonResponse([
        'success' => false,
        'error'   => ['code' => $code, 'message' => $message],
    ], $status);
}

/**
 * Liest den Request-Body als JSON (oder Formdaten als Fallback).
 */
function jsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST ?? [];
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return $_POST ?? [];
    }
    return $data;
}

/**
 * Extrahiert das Bearer-Token aus dem Authorization-Header.
 */
function bearerToken(): ?string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }
    $auth = $headers['Authorization'] ?? $headers['authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', (string) $auth, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Erzeugt ein neues API-Token fuer einen User und speichert nur dessen Hash.
 * Gibt das KLARTEXT-Token zurueck (wird nur einmal ausgegeben).
 */
function createApiToken(PDO $db, int $userId, ?string $deviceName = null): string
{
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $expires = (new DateTime())->modify('+' . API_TOKEN_TTL_DAYS . ' days')->format('Y-m-d H:i:s');

    $stmt = $db->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at)
         VALUES (:uid, :hash, :device, :exp)'
    );
    $stmt->execute([
        ':uid' => $userId, ':hash' => $hash,
        ':device' => $deviceName, ':exp' => $expires,
    ]);
    return $token;
}

/**
 * Authentifiziert den aktuellen API-Request anhand des Bearer-Tokens.
 * Liefert ein Array mit User-Infos und setzt $GLOBALS['api_user'].
 * Bricht bei ungueltigem/abgelaufenem Token mit 401 ab.
 */
function apiAuthenticate(PDO $db): array
{
    $token = bearerToken();
    if ($token === null) {
        jsonError('UNAUTHORIZED', 'Kein Bearer-Token uebermittelt.', 401);
    }
    $hash = hash('sha256', $token);

    $stmt = $db->prepare(
        'SELECT t.id AS token_id, t.expires_at, u.id AS user_id, u.username, u.is_admin
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = :hash'
    );
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonError('UNAUTHORIZED', 'Ungueltiges Token.', 401);
    }
    if (strtotime($row['expires_at']) < time()) {
        jsonError('TOKEN_EXPIRED', 'Token abgelaufen. Bitte neu anmelden.', 401);
    }

    // Rate-Limiting pruefen
    apiRateLimit((int) $row['token_id']);

    // last_used_at aktualisieren
    $upd = $db->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id');
    $upd->execute([':id' => (int) $row['token_id']]);

    $user = [
        'token_id' => (int) $row['token_id'],
        'user_id'  => (int) $row['user_id'],
        'username' => $row['username'],
        'is_admin' => (int) $row['is_admin'],
    ];
    $GLOBALS['api_user'] = $user;
    return $user;
}

/**
 * Setzt den Mandantenkontext fuer den API-Request (Farm + Permissions).
 * Wird von Endpunkten aufgerufen, die farm-spezifisch arbeiten.
 * Die gewuenschte farm_id kommt aus dem Header X-Farm-Id oder Query-Param farm_id.
 */
function apiSetFarmContext(PDO $db, array $apiUser): array
{
    $farmId = (int) ($_SERVER['HTTP_X_FARM_ID'] ?? $_GET['farm_id'] ?? 0);
    if ($farmId <= 0) {
        jsonError('FARM_REQUIRED', 'Kein Betrieb gewaehlt (X-Farm-Id Header oder farm_id Parameter).', 400);
    }

    // System-Admin darf jeden aktiven Betrieb
    if ($apiUser['is_admin'] === 1) {
        $stmt = $db->prepare('SELECT id, name, is_demo FROM farms WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $farmId]);
        $farm = $stmt->fetch();
        if (!$farm) {
            jsonError('FORBIDDEN', 'Kein Zugriff auf diesen Betrieb.', 403);
        }
        $ctx = [
            'farm_id'     => (int) $farm['id'],
            'farm_name'   => $farm['name'],
            'role'        => 'owner',
            'permissions' => roleDefaults()['owner'],
            'is_demo'     => (int) $farm['is_demo'] === 1,
        ];
        $GLOBALS['api_farm'] = $ctx;
        return $ctx;
    }

    $stmt = $db->prepare(
        'SELECT fu.id AS farm_user_id, fu.role, f.name, f.is_demo
         FROM farm_users fu JOIN farms f ON f.id = fu.farm_id
         WHERE fu.user_id = :uid AND fu.farm_id = :fid AND f.is_active = 1'
    );
    $stmt->execute([':uid' => $apiUser['user_id'], ':fid' => $farmId]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonError('FORBIDDEN', 'Kein Zugriff auf diesen Betrieb.', 403);
    }

    $ovStmt = $db->prepare('SELECT permission, granted FROM permissions WHERE farm_user_id = :fuid');
    $ovStmt->execute([':fuid' => (int) $row['farm_user_id']]);
    $overrides = $ovStmt->fetchAll();

    $ctx = [
        'farm_id'     => $farmId,
        'farm_name'   => $row['name'],
        'role'        => $row['role'],
        'permissions' => computePermissions($row['role'], $overrides),
        'is_demo'     => (int) $row['is_demo'] === 1,
    ];
    $GLOBALS['api_farm'] = $ctx;
    return $ctx;
}

/**
 * Prueft eine Berechtigung im API-Kontext.
 */
function apiRequirePermission(array $farmCtx, string $permission): void
{
    if (!in_array($permission, $farmCtx['permissions'], true)) {
        jsonError('FORBIDDEN', 'Keine Berechtigung (' . $permission . ').', 403);
    }
}

/**
 * Blockiert Schreibzugriffe im Demo-Betrieb (API).
 */
function apiRequireWrite(array $farmCtx): void
{
    if (!empty($farmCtx['is_demo'])) {
        jsonError('DEMO_READONLY', 'Im Demo-Betrieb sind keine Aenderungen moeglich.', 403);
    }
}

/**
 * Einfaches Rate-Limiting pro Token (Datei-basiert, kein zusaetzliches Schema).
 */
function apiRateLimit(int $tokenId): void
{
    $dir = dirname(__DIR__) . '/logs/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $file = $dir . '/token_' . $tokenId . '.json';
    $now = time();
    $window = API_RATE_WINDOW;
    $limit  = API_RATE_LIMIT;

    $state = ['start' => $now, 'count' => 0];
    if (is_readable($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
            $state = $decoded;
        }
    }
    // Fenster zuruecksetzen
    if (($now - (int) $state['start']) > $window) {
        $state = ['start' => $now, 'count' => 0];
    }
    $state['count']++;

    if ($state['count'] > $limit) {
        $retry = $window - ($now - (int) $state['start']);
        header('Retry-After: ' . max(1, $retry));
        jsonError('RATE_LIMITED', 'Zu viele Anfragen. Bitte spaeter erneut versuchen.', 429);
    }
    @file_put_contents($file, json_encode($state), LOCK_EX);
}

/**
 * Liest und validiert Pagination-Parameter.
 */
function apiPagination(): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? 50);
    $perPage = max(1, min(200, $perPage));
    $offset = ($page - 1) * $perPage;
    return [$page, $perPage, $offset];
}

/**
 * Liefert alle Betriebe eines Users inkl. der effektiven Berechtigungen.
 * Wird von GET /api/v1/user und GET /api/v1/farm verwendet.
 *
 * Fuer regulaere User werden Rolle + individuelle Overrides (Tabelle
 * permissions) ueber computePermissions() ausgewertet. System-Admins
 * erhalten alle aktiven Betriebe mit Rolle "owner" und vollen Rechten.
 *
 * @return array<int,array{id:int,name:string,is_demo:bool,role:string,permissions:string[]}>
 */
function apiFarmsWithPermissions(PDO $db, array $apiUser): array
{
    // System-Admin: alle aktiven Betriebe, volle Rechte
    if ((int) $apiUser['is_admin'] === 1) {
        $rows = $db->query(
            'SELECT id, name, is_demo FROM farms WHERE is_active = 1 ORDER BY name'
        )->fetchAll();
        $ownerPerms = roleDefaults()['owner'];
        return array_map(static fn($f) => [
            'id'          => (int) $f['id'],
            'name'        => $f['name'],
            'is_demo'     => (int) $f['is_demo'] === 1,
            'role'        => 'owner',
            'permissions' => $ownerPerms,
        ], $rows);
    }

    // Regulaerer User: Betriebe inkl. Rolle, Overrides je Betrieb aufloesen
    $stmt = $db->prepare(
        'SELECT fu.id AS farm_user_id, fu.role, f.id, f.name, f.is_demo
         FROM farm_users fu
         JOIN farms f ON f.id = fu.farm_id
         WHERE fu.user_id = :uid AND f.is_active = 1
         ORDER BY f.name'
    );
    $stmt->execute([':uid' => (int) $apiUser['user_id']]);
    $farms = $stmt->fetchAll();

    $ovStmt = $db->prepare('SELECT permission, granted FROM permissions WHERE farm_user_id = :fuid');

    $out = [];
    foreach ($farms as $f) {
        $ovStmt->execute([':fuid' => (int) $f['farm_user_id']]);
        $overrides = $ovStmt->fetchAll();
        $out[] = [
            'id'          => (int) $f['id'],
            'name'        => $f['name'],
            'is_demo'     => (int) $f['is_demo'] === 1,
            'role'        => $f['role'],
            'permissions' => computePermissions($f['role'], $overrides),
        ];
    }
    return $out;
}
