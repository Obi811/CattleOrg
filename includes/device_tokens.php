<?php
/**
 * device_tokens.php
 * -----------------------------------------------------------------------------
 * Gemeinsame Logik für die Verwaltung der Push-Geräte-Tokens (APNs/iOS).
 *
 * Diese Datei ist die EINZIGE Implementierung des Upserts/Abmeldens. Sowohl der
 * bestehende Endpunkt /api/v1/devices als auch der Alias /api/v1/device-tokens
 * delegieren hierher, damit es keine doppelte Logik gibt.
 *
 * Setzt voraus, dass api_helpers.php (und damit config.php/PDO) geladen wurde.
 */

declare(strict_types=1);

if (!function_exists('deviceOptionalFarmId')) {
    /**
     * Ermittelt einen optionalen, gültigen Betriebs-Kontext für ein Gerät.
     * Gibt die farm_id zurück oder null (kein/ungültiger Kontext).
     */
    function deviceOptionalFarmId(PDO $db, array $apiUser): ?int
    {
        $farmId = (int) ($_SERVER['HTTP_X_FARM_ID'] ?? $_GET['farm_id'] ?? 0);
        if ($farmId <= 0) {
            return null;
        }
        // System-Admin darf jeden aktiven Betrieb
        if ((int) $apiUser['is_admin'] === 1) {
            $s = $db->prepare('SELECT id FROM farms WHERE id = :id AND is_active = 1');
            $s->execute([':id' => $farmId]);
            return $s->fetchColumn() ? $farmId : null;
        }
        $s = $db->prepare(
            'SELECT 1 FROM farm_users fu JOIN farms f ON f.id = fu.farm_id
             WHERE fu.user_id = :uid AND fu.farm_id = :fid AND f.is_active = 1'
        );
        $s->execute([':uid' => (int) $apiUser['user_id'], ':fid' => $farmId]);
        return $s->fetchColumn() ? $farmId : null;
    }
}

if (!function_exists('deviceTokenUpsert')) {
    /**
     * Registriert oder aktualisiert (Upsert über UNIQUE(token)) ein Gerät.
     * Schreibt alle Spalten inkl. der 010er-Zusatzfelder (api_token_id,
     * device_name, app_version, last_seen_at).
     *
     * @param array $input  Request-Body (token Pflicht; optional platform,
     *                       environment, device_name, app_version, prefs{})
     * @return array        Normalisierter Geräte-Datensatz für die Antwort.
     */
    function deviceTokenUpsert(PDO $db, array $apiUser, array $input): array
    {
        $userId  = (int) $apiUser['user_id'];
        $tokenId = (int) ($apiUser['token_id'] ?? 0) ?: null;

        $token = trim((string) ($input['token'] ?? ''));
        if ($token === '') {
            jsonError('VALIDATION', 'Feld "token" ist erforderlich.', 422);
        }

        $platform = strtolower(trim((string) ($input['platform'] ?? 'ios')));
        if (!in_array($platform, ['ios', 'android'], true)) {
            $platform = 'ios';
        }
        $environment = strtolower(trim((string) ($input['environment'] ?? 'production')));
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            $environment = 'production';
        }
        $deviceName = trim((string) ($input['device_name'] ?? '')) ?: null;
        $appVersion = trim((string) ($input['app_version'] ?? '')) ?: null;
        $farmId     = deviceOptionalFarmId($db, $apiUser);

        // Mitteilungs-Präferenzen (optional, Standard: alle an)
        $prefs = is_array($input['prefs'] ?? null) ? $input['prefs'] : [];
        $pWeiter  = isset($prefs['weiterbehandlung']) ? (int) filter_var($prefs['weiterbehandlung'], FILTER_VALIDATE_BOOL) : 1;
        $pWarte   = isset($prefs['wartezeit'])        ? (int) filter_var($prefs['wartezeit'], FILTER_VALIDATE_BOOL)        : 1;
        $pTraecht = isset($prefs['traechtigkeit'])    ? (int) filter_var($prefs['traechtigkeit'], FILTER_VALIDATE_BOOL)    : 1;
        $pHemm    = isset($prefs['hemmstoff'])        ? (int) filter_var($prefs['hemmstoff'], FILTER_VALIDATE_BOOL)        : 1;

        $sql = 'INSERT INTO device_tokens
                  (user_id, api_token_id, farm_id, platform, device_name, app_version, token,
                   environment, is_active, push_weiterbehandlung, push_wartezeit,
                   push_traechtigkeit, push_hemmstoff, last_used_at, last_seen_at)
                VALUES
                  (:uid, :tid, :fid, :plat, :dname, :aver, :tok, :env, 1,
                   :pw, :pwz, :pt, :ph, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  user_id = VALUES(user_id),
                  api_token_id = VALUES(api_token_id),
                  farm_id = VALUES(farm_id),
                  platform = VALUES(platform),
                  device_name = VALUES(device_name),
                  app_version = VALUES(app_version),
                  environment = VALUES(environment),
                  is_active = 1,
                  push_weiterbehandlung = VALUES(push_weiterbehandlung),
                  push_wartezeit = VALUES(push_wartezeit),
                  push_traechtigkeit = VALUES(push_traechtigkeit),
                  push_hemmstoff = VALUES(push_hemmstoff),
                  last_used_at = NOW(),
                  last_seen_at = NOW()';
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':uid' => $userId, ':tid' => $tokenId, ':fid' => $farmId, ':plat' => $platform,
            ':dname' => $deviceName, ':aver' => $appVersion, ':tok' => $token, ':env' => $environment,
            ':pw' => $pWeiter, ':pwz' => $pWarte, ':pt' => $pTraecht, ':ph' => $pHemm,
        ]);

        $sel = $db->prepare(
            'SELECT id, user_id, farm_id, platform, device_name, app_version, environment, is_active,
                    push_weiterbehandlung, push_wartezeit, push_traechtigkeit, push_hemmstoff
             FROM device_tokens WHERE token = :t'
        );
        $sel->execute([':t' => $token]);
        $row = $sel->fetch();

        return [
            'id'          => (int) $row['id'],
            'platform'    => $row['platform'],
            'device_name' => $row['device_name'],
            'app_version' => $row['app_version'],
            'environment' => $row['environment'],
            'farm_id'     => $row['farm_id'] !== null ? (int) $row['farm_id'] : null,
            'is_active'   => (int) $row['is_active'] === 1,
            'prefs'       => [
                'weiterbehandlung' => (int) $row['push_weiterbehandlung'] === 1,
                'wartezeit'        => (int) $row['push_wartezeit'] === 1,
                'traechtigkeit'    => (int) $row['push_traechtigkeit'] === 1,
                'hemmstoff'        => (int) $row['push_hemmstoff'] === 1,
            ],
        ];
    }
}

if (!function_exists('deviceTokenDeactivate')) {
    /**
     * Meldet ein Gerät ab (is_active = 0). Nur eigene Geräte (IDOR-Schutz
     * über user_id). Gibt true zurück, wenn ein Datensatz betroffen war.
     */
    function deviceTokenDeactivate(PDO $db, int $userId, string $token): bool
    {
        $stmt = $db->prepare('UPDATE device_tokens SET is_active = 0 WHERE token = :t AND user_id = :u');
        $stmt->execute([':t' => $token, ':u' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
