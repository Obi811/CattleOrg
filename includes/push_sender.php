<?php
/**
 * push_sender.php
 * -----------------------------------------------------------------------------
 * Schlanke Fassade fuer den APNs-Versand (Apple Push Notification service).
 *
 * WICHTIG: Diese Datei enthaelt KEINE zweite APNs-Implementierung. Die komplette
 * Low-Level-Logik (JWT/ES256, curl/HTTP2, Payload) liegt ausschliesslich in
 * includes/push.php. Diese Fassade bietet nur bequeme High-Level-Funktionen,
 * die intern an push.php delegieren:
 *
 *   - sendApnsPush($deviceToken, $payload, $environment=null)   -> ein Geraet
 *   - sendPushToUser(PDO $db, $userId, array $payload, $farmId=null)
 *   - sendPushToFarm(PDO $db, $farmId, array $payload)
 *
 * Konfiguration ueber .env (siehe .env.example). Zusaetzlich zu den in push.php
 * verwendeten Namen (APNS_KEY_PATH/APNS_KEY_ID/APNS_TEAM_ID/APNS_BUNDLE_ID)
 * werden auch die alternativen Schreibweisen KEY_PATH/KEY_ID/TEAM_ID/BUNDLE_ID
 * akzeptiert sowie ein optionaler Host-Override APNS_URL.
 *
 * Setzt voraus, dass config.php (env()) geladen werden kann.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push.php';

if (!function_exists('pushSenderConfig')) {
    /**
     * Liefert die aufgeloeste APNs-Konfiguration. Baut auf apnsConfig() auf und
     * ergaenzt fehlende Werte aus den alternativen Variablennamen sowie den
     * optionalen Host-Override APNS_URL.
     */
    function pushSenderConfig(): array
    {
        $cfg = apnsConfig(); // enabled,key_path,key_id,team_id,bundle_id,default_env

        // Alternative Variablennamen als Fallback zulassen.
        if ($cfg['key_path'] === '') { $cfg['key_path'] = (string) env('KEY_PATH', ''); }
        if ($cfg['key_id'] === '')   { $cfg['key_id']   = (string) env('KEY_ID', ''); }
        if ($cfg['team_id'] === '')  { $cfg['team_id']  = (string) env('TEAM_ID', ''); }
        if ($cfg['bundle_id'] === ''){ $cfg['bundle_id']= (string) env('BUNDLE_ID', ''); }

        // Optionaler Host-Override (z. B. fuer Tests gegen Sandbox).
        $cfg['url'] = trim((string) env('APNS_URL', ''));

        return $cfg;
    }
}

if (!function_exists('pushSenderResolveEnv')) {
    /**
     * Ermittelt die APNs-Umgebung ("sandbox"|"production").
     * Reihenfolge: explizit uebergeben > aus APNS_URL abgeleitet > default_env.
     * Ein APNS_URL, der "sandbox" enthaelt, erzwingt die Sandbox; so wird der
     * Host-Override wirksam, ohne die curl-Logik aus push.php zu duplizieren.
     */
    function pushSenderResolveEnv(array $cfg, ?string $environment): string
    {
        if ($environment !== null && in_array($environment, ['sandbox', 'production'], true)) {
            return $environment;
        }
        if (($cfg['url'] ?? '') !== '') {
            return stripos($cfg['url'], 'sandbox') !== false ? 'sandbox' : 'production';
        }
        return $cfg['default_env'] ?? 'production';
    }
}

if (!function_exists('sendApnsPush')) {
    /**
     * Sendet ein Payload an ein einzelnes Geraete-Token.
     *
     * @param string      $deviceToken APNs Device-Token (Hex)
     * @param array       $payload     vollstaendiges Payload inkl. "aps"
     * @param string|null $environment "sandbox"|"production"|null (auto)
     *
     * @return array{ok:bool,status:int,reason:?string,apns_id:?string,invalid:bool,error:?string}
     */
    function sendApnsPush(string $deviceToken, array $payload, ?string $environment = null): array
    {
        $cfg = pushSenderConfig();

        if (!apnsIsConfigured($cfg)) {
            return [
                'ok' => false, 'status' => 0, 'reason' => null, 'apns_id' => null,
                'invalid' => false, 'error' => 'APNs ist nicht konfiguriert (.env pruefen).',
            ];
        }

        try {
            $jwt = apnsCreateJwt($cfg);
        } catch (\Throwable $e) {
            return [
                'ok' => false, 'status' => 0, 'reason' => null, 'apns_id' => null,
                'invalid' => false, 'error' => 'JWT-Fehler: ' . $e->getMessage(),
            ];
        }

        $env = pushSenderResolveEnv($cfg, $environment);
        $res = apnsSend($jwt, $deviceToken, $payload, $env, $cfg);
        $res['error'] = null;
        return $res;
    }
}

if (!function_exists('sendPushToUser')) {
    /**
     * Sendet ein Payload an alle aktiven iOS-Geraete eines Benutzers.
     * Optional auf einen Betrieb einschraenken (farm_id oder betriebsneutrale
     * Geraete mit farm_id IS NULL). Dauerhaft ungueltige Tokens werden
     * automatisch deaktiviert.
     *
     * @return array{sent:int,failed:int,invalid:int,devices:int}
     */
    function sendPushToUser(PDO $db, int $userId, array $payload, ?int $farmId = null): array
    {
        $cfg = pushSenderConfig();
        $out = ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'devices' => 0];

        if (!apnsIsConfigured($cfg)) {
            return $out;
        }
        try {
            $jwt = apnsCreateJwt($cfg);
        } catch (\Throwable $e) {
            return $out;
        }

        if ($farmId !== null) {
            $sql = "SELECT id, token, environment FROM device_tokens
                     WHERE user_id = :u AND is_active = 1 AND platform = 'ios'
                       AND (farm_id = :f OR farm_id IS NULL)";
            $stmt = $db->prepare($sql);
            $stmt->execute([':u' => $userId, ':f' => $farmId]);
        } else {
            $sql = "SELECT id, token, environment FROM device_tokens
                     WHERE user_id = :u AND is_active = 1 AND platform = 'ios'";
            $stmt = $db->prepare($sql);
            $stmt->execute([':u' => $userId]);
        }
        $devices = $stmt->fetchAll();
        $out['devices'] = count($devices);

        foreach ($devices as $dev) {
            $env = $dev['environment'] ?: ($cfg['default_env'] ?? 'production');
            $res = apnsSend($jwt, (string) $dev['token'], $payload, $env, $cfg);
            if ($res['ok']) {
                $out['sent']++;
            } elseif ($res['invalid']) {
                $db->prepare('UPDATE device_tokens SET is_active = 0 WHERE id = :id')
                   ->execute([':id' => (int) $dev['id']]);
                $out['invalid']++;
            } else {
                $out['failed']++;
            }
        }
        return $out;
    }
}

if (!function_exists('sendPushToFarm')) {
    /**
     * Sendet ein Payload an alle aktiven iOS-Geraete der Mitglieder eines
     * Betriebs (betriebsbezogene Geraete oder betriebsneutrale Geraete von
     * Betriebsmitgliedern). Dauerhaft ungueltige Tokens werden deaktiviert.
     *
     * @return array{sent:int,failed:int,invalid:int,devices:int}
     */
    function sendPushToFarm(PDO $db, int $farmId, array $payload): array
    {
        $cfg = pushSenderConfig();
        $out = ['sent' => 0, 'failed' => 0, 'invalid' => 0, 'devices' => 0];

        if (!apnsIsConfigured($cfg)) {
            return $out;
        }
        try {
            $jwt = apnsCreateJwt($cfg);
        } catch (\Throwable $e) {
            return $out;
        }

        // Nur Geraete von tatsaechlichen Mitgliedern des Betriebs.
        $sql = "SELECT dt.id, dt.token, dt.environment
                  FROM device_tokens dt
                  JOIN farm_users fu ON fu.user_id = dt.user_id AND fu.farm_id = :f1
                 WHERE dt.is_active = 1 AND dt.platform = 'ios'
                   AND (dt.farm_id = :f2 OR dt.farm_id IS NULL)
                 GROUP BY dt.id, dt.token, dt.environment";
        $stmt = $db->prepare($sql);
        $stmt->execute([':f1' => $farmId, ':f2' => $farmId]);
        $devices = $stmt->fetchAll();
        $out['devices'] = count($devices);

        foreach ($devices as $dev) {
            $env = $dev['environment'] ?: ($cfg['default_env'] ?? 'production');
            $res = apnsSend($jwt, (string) $dev['token'], $payload, $env, $cfg);
            if ($res['ok']) {
                $out['sent']++;
            } elseif ($res['invalid']) {
                $db->prepare('UPDATE device_tokens SET is_active = 0 WHERE id = :id')
                   ->execute([':id' => (int) $dev['id']]);
                $out['invalid']++;
            } else {
                $out['failed']++;
            }
        }
        return $out;
    }
}
