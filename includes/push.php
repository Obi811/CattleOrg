<?php
/**
 * push.php
 * -----------------------------------------------------------------------------
 * APNs-Versand (Apple Push Notification service) für die native iOS-App.
 *
 * Authentifizierung token-basiert (.p8-Schlüssel, Key ID, Team ID, Bundle ID),
 * JWT mit ES256, Zustellung über HTTP/2 via curl. Es werden KEINE zusätzlichen
 * Composer-Pakete benötigt (läuft auf cPanel/PHP 8.3 ohne Root).
 *
 * Konfiguration ausschließlich über .env (siehe .env.example):
 *   APNS_ENABLED, APNS_KEY_PATH (.p8, AUSSERHALB von public/), APNS_KEY_ID,
 *   APNS_TEAM_ID, APNS_BUNDLE_ID, APNS_DEFAULT_ENV
 *
 * Diese Datei setzt voraus, dass config.php bereits geladen wurde (env()).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Liefert die APNs-Konfiguration aus der .env.
 */
function apnsConfig(): array
{
    return [
        'enabled'     => filter_var(env('APNS_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
        'key_path'    => (string) env('APNS_KEY_PATH', ''),
        'key_id'      => (string) env('APNS_KEY_ID', ''),
        'team_id'     => (string) env('APNS_TEAM_ID', ''),
        'bundle_id'   => (string) env('APNS_BUNDLE_ID', ''),
        'default_env' => in_array(env('APNS_DEFAULT_ENV', 'production'), ['sandbox', 'production'], true)
                            ? (string) env('APNS_DEFAULT_ENV', 'production') : 'production',
    ];
}

/**
 * Prüft, ob der APNs-Versand grundsätzlich konfiguriert/aktiv ist.
 */
function apnsIsConfigured(array $cfg): bool
{
    return $cfg['enabled']
        && $cfg['key_path'] !== '' && is_readable($cfg['key_path'])
        && $cfg['key_id'] !== '' && $cfg['team_id'] !== '' && $cfg['bundle_id'] !== '';
}

/**
 * base64url-Kodierung (RFC 7515) ohne Padding.
 */
function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Wandelt eine ECDSA-DER-Signatur in das von JWS/ES256 erwartete
 * feste R||S-Format (je 32 Byte) um.
 */
function ecDerToConcat(string $der): string
{
    $offset = 0;
    if (($der[$offset++] ?? '') !== "\x30") {
        throw new RuntimeException('Ungültige DER-Signatur (SEQUENCE erwartet).');
    }
    // Länge der SEQUENCE (ggf. long form) überspringen
    $seqLen = ord($der[$offset++]);
    if ($seqLen & 0x80) {
        $offset += ($seqLen & 0x7f);
    }
    // INTEGER R
    if (($der[$offset++] ?? '') !== "\x02") {
        throw new RuntimeException('Ungültige DER-Signatur (INTEGER R erwartet).');
    }
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;
    // INTEGER S
    if (($der[$offset++] ?? '') !== "\x02") {
        throw new RuntimeException('Ungültige DER-Signatur (INTEGER S erwartet).');
    }
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);

    // Führende 0x00 entfernen (Vorzeichen) bzw. links auf 32 Byte auffüllen
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

/**
 * Erzeugt ein APNs-Provider-JWT (ES256), gültig max. 1 Stunde.
 * Das Token kann für mehrere Pushes wiederverwendet werden.
 */
function apnsCreateJwt(array $cfg): string
{
    $key = openssl_pkey_get_private(file_get_contents($cfg['key_path']));
    if ($key === false) {
        throw new RuntimeException('APNs .p8-Schlüssel konnte nicht gelesen werden.');
    }
    $header  = ['alg' => 'ES256', 'kid' => $cfg['key_id']];
    $claims  = ['iss' => $cfg['team_id'], 'iat' => time()];
    $signingInput = base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));

    $der = '';
    if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('APNs-JWT konnte nicht signiert werden.');
    }
    $signature = ecDerToConcat($der);
    return $signingInput . '.' . base64UrlEncode($signature);
}

/**
 * Sendet eine Push-Nachricht an ein einzelnes Gerät.
 *
 * @param string $jwt         vorab erzeugtes Provider-JWT (apnsCreateJwt)
 * @param string $deviceToken APNs Device-Token (Hex)
 * @param array  $payload     vollständiges Payload inkl. "aps"
 * @param string $environment "sandbox" | "production"
 * @param array  $cfg         apnsConfig()
 * @param array  $extraHeaders Zusätzliche APNs-Header, z. B.
 *               ['apns-collapse-id' => 'sum-3-20261014']. Werte werden als
 *               "name: wert" an die HTTP/2-Anfrage angehängt.
 *
 * @return array{ok:bool,status:int,reason:?string,apns_id:?string,invalid:bool}
 *         invalid = true, wenn das Geräte-Token dauerhaft ungültig ist (410 / BadDeviceToken)
 */
function apnsSend(string $jwt, string $deviceToken, array $payload, string $environment, array $cfg, array $extraHeaders = []): array
{
    $host = $environment === 'sandbox'
        ? 'https://api.sandbox.push.apple.com'
        : 'https://api.push.apple.com';
    $url = $host . '/3/device/' . $deviceToken;

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $headers = [
        'authorization: bearer ' . $jwt,
        'apns-topic: ' . $cfg['bundle_id'],
        'apns-push-type: alert',
        'apns-priority: 10',
        'content-type: application/json',
    ];
    foreach ($extraHeaders as $name => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $headers[] = $name . ': ' . $value;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'reason' => 'curl: ' . $err, 'apns_id' => null, 'invalid' => false];
    }

    $status      = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders  = substr($response, 0, $headerSize);
    $rawBody     = substr($response, $headerSize);
    curl_close($ch);

    // apns-id aus Antwort-Headern lesen
    $apnsId = null;
    if (preg_match('/apns-id:\s*([^\r\n]+)/i', $rawHeaders, $m)) {
        $apnsId = trim($m[1]);
    }

    $reason = null;
    if ($status !== 200 && $rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) && isset($decoded['reason'])) {
            $reason = $decoded['reason'];
        }
    }

    // Dauerhaft ungültige Tokens: 410 (Unregistered) oder 400/BadDeviceToken
    $invalid = ($status === 410)
        || ($reason !== null && in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true));

    return [
        'ok'      => $status === 200,
        'status'  => $status,
        'reason'  => $reason,
        'apns_id' => $apnsId,
        'invalid' => $invalid,
    ];
}

/**
 * Baut ein APNs-Payload für eine EINZELNE Mitteilung mit alert/sound und
 * Deep-Link-Feldern (type/farm_id/cattle_id).
 *
 * @param ?int    $badge    optionaler Badge-Wert für das App-Symbol
 * @param ?string $threadId optionale aps.thread-id (iOS gruppiert Mitteilungen
 *                          mit gleicher thread-id, z. B. je Betrieb)
 */
function buildApnsPayload(
    string $title,
    string $body,
    string $type,
    int $farmId,
    ?int $cattleId = null,
    ?int $badge = null,
    ?string $threadId = null
): array {
    $aps = [
        'alert' => ['title' => $title, 'body' => $body],
        'sound' => 'default',
    ];
    if ($badge !== null) {
        $aps['badge'] = $badge;
    }
    if ($threadId !== null && $threadId !== '') {
        $aps['thread-id'] = $threadId;
    }

    $payload = [
        'aps'     => $aps,
        'type'    => $type,
        'farm_id' => $farmId,
    ];
    if ($cattleId !== null) {
        $payload['cattle_id'] = $cattleId;
    }
    return $payload;
}

/**
 * Baut ein APNs-Payload für eine SAMMEL-Mitteilung (Tagesübersicht je Betrieb).
 * Die App soll beim Antippen in den Aufgaben-Tab springen (target = "tasks"),
 * optional direkt auf eine Kategorie gefiltert (filter = z. B. "traechtigkeit").
 *
 * @param string  $title    z. B. "Musterhof - heute fällig"
 * @param string  $body     z. B. "5 Trächtigkeitskontrollen, 2 Hemmstoffproben"
 * @param int     $farmId   Betrieb, auf den die App wechseln soll
 * @param int     $badge    Gesamtzahl offener Aufgaben (App-Symbol)
 * @param string  $threadId aps.thread-id (iOS-Gruppierung, je Betrieb)
 * @param ?string $filter   optionaler Kategorie-Filter (nur bei einer Kategorie)
 */
function buildSummaryPayload(
    string $title,
    string $body,
    int $farmId,
    int $badge,
    string $threadId,
    ?string $filter = null
): array {
    $payload = [
        'aps' => [
            'alert'     => ['title' => $title, 'body' => $body],
            'sound'     => 'default',
            'badge'     => $badge,
            'thread-id' => $threadId,
        ],
        'type'    => 'summary',
        'target'  => 'tasks',
        'farm_id' => $farmId,
    ];
    if ($filter !== null && $filter !== '') {
        $payload['filter'] = $filter;
    }
    return $payload;
}
