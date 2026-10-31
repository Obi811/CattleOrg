<?php
/**
 * public/api/v1/bootstrap_api.php
 * -----------------------------------------------------------------------------
 * Gemeinsamer Einstieg fuer alle API-v1-Endpunkte. Bindet die API-Helfer ein
 * und setzt Standard-Header (CORS-vorbereitet, JSON).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_helpers.php';

// Basis-Header fuer JSON-API
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS (fuer die spaetere mobile App / Web-Clients). In Produktion ggf.
// auf konkrete Origins beschraenken.
$allowedOrigin = env('API_CORS_ORIGIN', '*');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Farm-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

// Preflight sofort beantworten
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// -----------------------------------------------------------------------------
// Globale Fehlerbehandlung
// -----------------------------------------------------------------------------
// Ohne diese Handler wird eine nicht abgefangene Ausnahme (z. B. ein SQL-Fehler,
// da PDO im ERRMODE_EXCEPTION laeuft) zu einem PHP-Fatal. Mit display_errors=0
// in Produktion liefert der Server dann einen nackten HTTP-500 OHNE JSON-Body –
// der API-Client (iOS-App) kann nur "Serverfehler 500" anzeigen, nicht die
// eigentliche Ursache. Die folgenden Handler liefern stattdessen eine saubere
// JSON-Fehlerantwort und schreiben die Details ins PHP-Fehlerprotokoll.

set_exception_handler(static function (\Throwable $e): void {
    error_log('[API] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        jsonError('INTERNAL_ERROR', 'Serverfehler: ' . $e->getMessage(), 500);
    }
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('[API] Fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => ['code' => 'FATAL_ERROR', 'message' => 'Serverfehler: ' . $err['message']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

/**
 * Stellt sicher, dass die HTTP-Methode erlaubt ist.
 */
function apiRequireMethod(array $methods): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($m, $methods, true)) {
        jsonError('METHOD_NOT_ALLOWED', 'Methode nicht erlaubt. Erlaubt: ' . implode(', ', $methods), 405);
    }
}
