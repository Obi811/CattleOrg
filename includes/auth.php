<?php
/**
 * auth.php
 * -----------------------------------------------------------------------------
 * Session-Härtung, Login-Prüfung, System-Admin-Prüfung und CSRF-Schutz.
 *
 * Wird über bootstrap.php eingebunden (nach config.php + functions.php).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Sichere Session starten
// ---------------------------------------------------------------------------

// Session-Speicherpfad explizit setzen (HostEurope/cPanel-Fix)
// Verhindert Session-Verlust bei eingeschränkten Server-Rechten auf tmp-Verzeichnisse
$sessionPath = dirname(__DIR__) . '/tmp/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0700, true);
}
session_save_path($sessionPath);

if (session_status() === PHP_SESSION_NONE) {
    $lifetime = (int) env('SESSION_LIFETIME', '28800');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => APP_HTTPS,      // nur über HTTPS senden, wenn aktiv
        'httponly' => true,           // kein JS-Zugriff
        'samesite' => 'Lax',          // CSRF-Mitigation
    ]);
    session_name('CATTLEORG_SESSID');
    session_start();

    // Idle-Timeout
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > $lifetime) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['_last_activity'] = time();
}

// ---------------------------------------------------------------------------
// 2. Login- / Rollen-Prüfungen
// ---------------------------------------------------------------------------

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('isAdmin')) {
    /**
     * System-Admin (plattformweiter Zugriff). Quelle der Wahrheit: $_SESSION['is_admin'].
     */
    function isAdmin(): bool
    {
        return !empty($_SESSION['is_admin']);
    }
}

if (!function_exists('currentUserId')) {
    function currentUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(): void
    {
        if (!isLoggedIn()) {
            flash('login', 'Bitte zuerst anmelden.', 'warning');
            redirect(url('login.php'));
        }
    }
}

if (!function_exists('requireAdmin')) {
    /**
     * Erzwingt System-Admin-Rechte. Behebt den Bug der Altanwendung
     * (requireAdmin war dort nie definiert).
     */
    function requireAdmin(): void
    {
        requireLogin();
        if (!isAdmin()) {
            http_response_code(403);
            flash('error', 'Kein Zugriff: Systemadministrator-Rechte erforderlich.', 'danger');
            redirect(url('index.php'));
        }
    }
}

if (!function_exists('loginUser')) {
    /**
     * Setzt die Session nach erfolgreichem Login und beugt Session-Fixation vor.
     */
    function loginUser(array $user): void
    {
        // Session-Fixation verhindern
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = (int) ($user['is_admin'] ?? 0);
        $_SESSION['_last_activity'] = time();
    }
}

if (!function_exists('logoutUser')) {
    function logoutUser(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

// ---------------------------------------------------------------------------
// 3. CSRF-Schutz
// ---------------------------------------------------------------------------

if (!function_exists('csrfToken')) {
    /**
     * Liefert das CSRF-Token der aktuellen Session (erzeugt es bei Bedarf).
     */
    function csrfToken(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrfField')) {
    /**
     * Gibt ein fertiges Hidden-Input für Formulare aus.
     */
    function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    }
}

if (!function_exists('verifyCsrf')) {
    /**
     * Prüft das übergebene CSRF-Token gegen die Session.
     */
    function verifyCsrf(?string $token): bool
    {
        return !empty($_SESSION['_csrf_token'])
            && is_string($token)
            && hash_equals($_SESSION['_csrf_token'], $token);
    }
}

if (!function_exists('requireCsrf')) {
    /**
     * Erzwingt einen gültigen CSRF-Token bei POST-Requests.
     */
    function requireCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['_csrf'] ?? '';
            if (!verifyCsrf($token)) {
                http_response_code(419);
                exit('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Formular erneut laden.');
            }
        }
    }
}
