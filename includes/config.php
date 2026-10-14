<?php
/**
 * config.php
 * -----------------------------------------------------------------------------
 * Laedt die Umgebungskonfiguration aus der `.env`-Datei, konfiguriert die
 * Fehlerbehandlung passend zur Umgebung und baut die zentrale PDO-Verbindung auf.
 *
 * Diese Datei wird ausschliesslich ueber bootstrap.php eingebunden.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. .env laden
// ---------------------------------------------------------------------------

/**
 * Liest eine .env-Datei (einfaches KEY=VALUE-Format) und stellt die Werte
 * ueber getenv()/$_ENV bereit. Werte koennen in "..." oder '...' stehen.
 */
function loadEnv(string $path): void
{
    if (!is_readable($path)) {
        http_response_code(500);
        exit('Konfigurationsfehler: .env-Datei fehlt. Bitte .env.example nach .env kopieren und anpassen.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Umschliessende Anfuehrungszeichen entfernen
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if ($key !== '') {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

/**
 * Bequemer Zugriff auf eine Umgebungsvariable mit Default.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
}

// .env liegt im Projekt-Root (eine Ebene ueber includes/)
loadEnv(dirname(__DIR__) . '/.env');

// ---------------------------------------------------------------------------
// 2. Konstanten
// ---------------------------------------------------------------------------

define('APP_NAME', env('APP_NAME', 'CattleOrg'));
define('BASE_URL', env('BASE_URL', '/'));
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_HTTPS', filter_var(env('APP_HTTPS', 'true'), FILTER_VALIDATE_BOOL));
define('IS_PRODUCTION', APP_ENV === 'production');

// API-Konfiguration
define('API_TOKEN_TTL_DAYS', (int) env('API_TOKEN_TTL_DAYS', '90'));
define('API_RATE_LIMIT', (int) env('API_RATE_LIMIT', '100'));
define('API_RATE_WINDOW', (int) env('API_RATE_WINDOW', '900'));

// ---------------------------------------------------------------------------
// 3. Fehlerbehandlung je nach Umgebung
// ---------------------------------------------------------------------------

if (IS_PRODUCTION) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    // Fehler in Logdatei schreiben
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    ini_set('log_errors', '1');
    ini_set('error_log', $logDir . '/php-error.log');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// ---------------------------------------------------------------------------
// 4. Zentrale Datenbankverbindung (PDO)
// ---------------------------------------------------------------------------

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    env('DB_HOST', 'localhost'),
    env('DB_PORT', '3306'),
    env('DB_NAME', 'cattleorg'),
    env('DB_CHARSET', 'utf8mb4')
);

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $db = new PDO($dsn, env('DB_USER', ''), env('DB_PASS', ''), $pdoOptions);
} catch (PDOException $e) {
    // Im Produktivbetrieb keine Details an den Client geben
    error_log('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
    http_response_code(500);
    if (IS_PRODUCTION) {
        exit('Es ist ein interner Fehler aufgetreten. Bitte spaeter erneut versuchen.');
    }
    exit('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
}

// $db steht ab hier global zur Verfuegung.
