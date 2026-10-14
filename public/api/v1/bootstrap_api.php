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
