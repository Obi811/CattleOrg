<?php
/**
 * cron/push_notifications.php
 * -----------------------------------------------------------------------------
 * CLI-Cronjob: ermittelt die faelligen Aufgaben je Betrieb (dieselbe Logik wie
 * public/todo_list.php) und sendet dazu iOS-Push-Nachrichten (APNs) an die
 * registrierten Geraete der berechtigten Benutzer.
 *
 * WICHTIG: Dieses Skript darf NICHT ueber das Web erreichbar sein. Es liegt
 * ausserhalb von public/ und bricht zusaetzlich ab, wenn es nicht per CLI
 * aufgerufen wird.
 *
 * Aufruf (cPanel-Cronjob, taeglich, z. B. 06:00):
 *   /usr/local/bin/php /home/<account>/cattleorg/cron/push_notifications.php
 *
 * Optionen:
 *   --dry-run   Nichts senden/protokollieren, nur ausgeben, was passieren wuerde.
 *   --verbose   Mehr Ausgabe (auch bei echtem Lauf).
 *
 * Die 4 Mitteilungsarten (type):
 *   weiterbehandlung | wartezeit | hemmstoff | traechtigkeit
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 0. Nur per CLI ausfuehrbar
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript darf nur ueber die Kommandozeile (CLI) ausgefuehrt werden.');
}

$DRY_RUN = in_array('--dry-run', $argv, true);
$VERBOSE = in_array('--verbose', $argv, true) || $DRY_RUN;

require_once __DIR__ . '/../includes/config.php';     // $db, env()
require_once __DIR__ . '/../includes/functions.php';  // formatEarTag()
require_once __DIR__ . '/../includes/tenant.php';     // computePermissions()
require_once __DIR__ . '/../includes/push.php';       // apns*()

/**
 * Kleine Logausgabe mit Zeitstempel.
 */
function logLine(string $msg): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

logLine(($DRY_RUN ? '[DRY-RUN] ' : '') . 'Push-Cron gestartet.');

// ---------------------------------------------------------------------------
// 1. APNs-Konfiguration pruefen (im Dry-Run nicht zwingend)
// ---------------------------------------------------------------------------
$cfg = apnsConfig();
$jwt = null;
if (!$DRY_RUN) {
    if (!apnsIsConfigured($cfg)) {
        logLine('FEHLER: APNs ist nicht konfiguriert (.env: APNS_ENABLED/APNS_KEY_PATH/APNS_KEY_ID/APNS_TEAM_ID/APNS_BUNDLE_ID). Abbruch.');
        exit(1);
    }
    try {
        $jwt = apnsCreateJwt($cfg);
    } catch (Throwable $e) {
        logLine('FEHLER beim Erzeugen des APNs-JWT: ' . $e->getMessage());
        exit(1);
    }
}

$gestern = (new DateTime('yesterday'))->format('Y-m-d');

// ---------------------------------------------------------------------------
// 2. Aktive, nicht-Demo-Betriebe laden
// ---------------------------------------------------------------------------
$farms = $db->query(
    "SELECT id, name FROM farms WHERE is_active = 1 AND is_demo = 0 ORDER BY id"
)->fetchAll();

// Ab wie vielen (nicht-dringenden) faelligen Mitteilungen je Benutzer wird
// eine SAMMEL-Mitteilung statt vieler Einzel-Pushes verschickt. Bei weniger
// als diesem Wert bleibt es bei Einzel-Pushes. Dringende Mitteilungen
// (Wartezeit/Hemmstoffprobe faellig) gehen IMMER einzeln.
$SUMMARY_THRESHOLD = 3;

$stats = [
    'sent'    => 0,  // tatsaechlich gesendete Pushes (Geraete)
    'planned' => 0,  // geplante Mitteilungen (nach Dedupe + Geraete-Filter)
    'skipped' => 0,  // wegen Dedupe uebersprungen
    'invalid' => 0,  // deaktivierte Tokens
];

foreach ($farms as $farm) {
    $farmId = (int) $farm['id'];

    // -----------------------------------------------------------------------
    // 2a. Faellige Aufgaben je Betrieb ermitteln (Logik aus todo_list.php)
    // -----------------------------------------------------------------------
    $notifications = computeFarmNotifications($db, $farmId, $gestern);
    if (empty($notifications)) {
        if ($VERBOSE) {
            logLine("Betrieb {$farmId} ({$farm['name']}): keine faelligen Aufgaben.");
        }
        continue;
    }

    // -----------------------------------------------------------------------
    // 2b. Berechtigte Benutzer des Betriebs (mit cattle.read) ermitteln
    // -----------------------------------------------------------------------
    $users = eligibleFarmUsers($db, $farmId);
    if (empty($users)) {
        if ($VERBOSE) {
            logLine("Betrieb {$farmId}: keine berechtigten Benutzer mit Geraeten.");
        }
        continue;
    }

    logLine("Betrieb {$farmId} ({$farm['name']}): "
        . count($notifications) . ' Mitteilung(en), ' . count($users) . ' berechtigte(r) Benutzer.');

    foreach ($users as $userId) {
        dispatchForUser(
            $db, $jwt, $cfg, $farmId, (string) $farm['name'], (int) $userId,
            $notifications, $DRY_RUN, $VERBOSE, $SUMMARY_THRESHOLD, $stats
        );
    }
}

logLine(($DRY_RUN ? '[DRY-RUN] ' : '') . 'Fertig. geplant=' . $stats['planned']
    . ' gesendet=' . $stats['sent'] . ' uebersprungen(dedupe)=' . $stats['skipped']
    . ' deaktiviert=' . $stats['invalid']);

exit(0);

// ===========================================================================
// Hilfsfunktionen
// ===========================================================================

/**
 * Ermittelt die faelligen Mitteilungen eines Betriebs.
 * Gibt ein Array von Mitteilungen zurueck:
 *   ['type','ref_key','title','body','cattle_id','pref_col']
 */
function computeFarmNotifications(PDO $db, int $farmId, string $gestern): array
{
    $out = [];

    // --- 1. Weiterbehandlungen (Behandlungen von gestern, ausser Trockenstellen) ---
    $ws = $db->prepare(
        "SELECT COUNT(*) FROM treatments t
           JOIN cattle c ON c.id = t.cattle_id AND c.farm_id = t.farm_id
          WHERE t.farm_id = :f AND t.treatment_date = :g AND t.type <> 'Trockenstellen'"
    );
    $ws->execute([':f' => $farmId, ':g' => $gestern]);
    $weiterCount = (int) $ws->fetchColumn();
    if ($weiterCount > 0) {
        $out[] = [
            'type'      => 'weiterbehandlung',
            'ref_key'   => 'date:' . $gestern,
            'title'     => 'Weiterbehandlungen faellig',
            'body'      => $weiterCount === 1
                ? '1 Tier von gestern braucht heute eine Weiterbehandlung.'
                : $weiterCount . ' Tiere von gestern brauchen heute eine Weiterbehandlung.',
            'cattle_id' => null,
            'pref_col'  => 'push_weiterbehandlung',
            'count'     => $weiterCount,
        ];
    }

    // --- 2. Hemmstoffprobe faellig (Wartezeit abgelaufen) ---
    $hsql = "
        SELECT c.id, c.ear_tag,
               DATE_ADD(t.treatment_date, INTERVAL COALESCE(m.wz_milk,0) DAY) AS wartezeit_ende
        FROM cattle c
        JOIN (SELECT cattle_id, MAX(treatment_date) AS last_td
              FROM treatments WHERE farm_id = :f1 GROUP BY cattle_id) lt ON lt.cattle_id = c.id
        JOIN treatments t ON t.cattle_id = c.id AND t.treatment_date = lt.last_td AND t.farm_id = :f2
        JOIN medications m ON m.name = t.medication
        WHERE c.farm_id = :f3 AND c.status = 'Aktiv'
          AND COALESCE(m.wz_milk,0) > 0
          AND DATE_ADD(t.treatment_date, INTERVAL COALESCE(m.wz_milk,0) DAY) <= CURDATE()
          AND NOT EXISTS (
              SELECT 1 FROM hemmstoff_probes hp
              WHERE hp.cattle_id = c.id AND hp.farm_id = c.farm_id
                AND hp.status = 'frei'
                AND hp.probe_date >= DATE_ADD(t.treatment_date, INTERVAL COALESCE(m.wz_milk,0) DAY)
          )
          AND NOT EXISTS (
              SELECT 1 FROM hemmstoff_probes hp2
              WHERE hp2.cattle_id = c.id AND hp2.farm_id = c.farm_id AND hp2.status = 'versendet'
          )";
    $hs = $db->prepare($hsql);
    $hs->execute([':f1' => $farmId, ':f2' => $farmId, ':f3' => $farmId]);
    foreach ($hs->fetchAll() as $r) {
        $out[] = [
            'type'      => 'wartezeit',
            'ref_key'   => 'cattle:' . (int) $r['id'] . ':' . $r['wartezeit_ende'],
            'title'     => 'Hemmstoffprobe faellig',
            'body'      => 'Wartezeit fuer ' . formatEarTag($r['ear_tag']) . ' abgelaufen. Bitte Hemmstoffprobe senden.',
            'cattle_id' => (int) $r['id'],
            'pref_col'  => 'push_wartezeit',
            'count'     => 1,
        ];
    }

    // --- 3. Hemmstoffproben "versendet" (Ergebnis ausstehend) ---
    $vs = $db->prepare(
        "SELECT hp.id, hp.nummer, c.id AS cattle_id, c.ear_tag
           FROM hemmstoff_probes hp
           JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
          WHERE hp.farm_id = :f AND hp.status = 'versendet'"
    );
    $vs->execute([':f' => $farmId]);
    foreach ($vs->fetchAll() as $r) {
        $out[] = [
            'type'      => 'hemmstoff',
            'ref_key'   => 'probe:' . (int) $r['id'],
            'title'     => 'Hemmstoffprobe - Ergebnis ausstehend',
            'body'      => 'Probe ' . $r['nummer'] . ' (' . formatEarTag($r['ear_tag']) . ') wartet auf das Ergebnis.',
            'cattle_id' => (int) $r['cattle_id'],
            'pref_col'  => 'push_hemmstoff',
            'count'     => 1,
        ];
    }

    // --- 4. Offene Traechtigkeitskontrollen (>= 35 Tage seit Besamung) ---
    $psql = "
        SELECT c.id, c.ear_tag, i.date AS last_insem, DATEDIFF(CURDATE(), i.date) AS tage
        FROM cattle c
        JOIN inseminations i ON i.cattle_id = c.id AND i.farm_id = c.farm_id
        JOIN (SELECT cattle_id, MAX(date) maxdate FROM inseminations WHERE farm_id = :f1 GROUP BY cattle_id) lm
          ON lm.cattle_id = i.cattle_id AND lm.maxdate = i.date
        WHERE c.farm_id = :f2 AND c.status = 'Aktiv'
          AND DATEDIFF(CURDATE(), i.date) >= 35
          AND NOT EXISTS (SELECT 1 FROM pregnancy_checks p
                          WHERE p.cattle_id = c.id AND p.farm_id = c.farm_id AND p.check_date >= i.date)";
    $ps = $db->prepare($psql);
    $ps->execute([':f1' => $farmId, ':f2' => $farmId]);
    foreach ($ps->fetchAll() as $r) {
        $out[] = [
            'type'      => 'traechtigkeit',
            'ref_key'   => 'cattle:' . (int) $r['id'] . ':' . $r['last_insem'],
            'title'     => 'Traechtigkeitskontrolle faellig',
            'body'      => 'Traechtigkeitskontrolle fuer ' . formatEarTag($r['ear_tag'])
                            . ' faellig (' . (int) $r['tage'] . ' Tage seit Besamung).',
            'cattle_id' => (int) $r['id'],
            'pref_col'  => 'push_traechtigkeit',
            'count'     => 1,
        ];
    }

    return $out;
}

/**
 * Liefert die user_ids, die auf dem Betrieb die Berechtigung cattle.read
 * besitzen UND mindestens ein aktives Geraet registriert haben.
 * (Betriebs-Mitglieder laut farm_users; System-Admins ohne Mitgliedschaft
 *  erhalten bewusst keine betriebsbezogenen Pushes.)
 */
function eligibleFarmUsers(PDO $db, int $farmId): array
{
    $stmt = $db->prepare(
        'SELECT fu.id AS farm_user_id, fu.user_id, fu.role
         FROM farm_users fu
         WHERE fu.farm_id = :f'
    );
    $stmt->execute([':f' => $farmId]);
    $members = $stmt->fetchAll();

    $ovStmt = $db->prepare('SELECT permission, granted FROM permissions WHERE farm_user_id = :fuid');

    $result = [];
    foreach ($members as $m) {
        $ovStmt->execute([':fuid' => (int) $m['farm_user_id']]);
        $perms = computePermissions($m['role'], $ovStmt->fetchAll());
        if (!in_array('cattle.read', $perms, true)) {
            continue;
        }
        // Nur Benutzer mit mindestens einem aktiven, passenden Geraet
        $has = $db->prepare(
            'SELECT 1 FROM device_tokens
              WHERE user_id = :u AND is_active = 1 AND (farm_id = :f OR farm_id IS NULL) LIMIT 1'
        );
        $has->execute([':u' => (int) $m['user_id'], ':f' => $farmId]);
        if ($has->fetchColumn()) {
            $result[] = (int) $m['user_id'];
        }
    }
    return $result;
}

/**
 * Alle aktiven iOS-Geraete eines Benutzers fuer einen Betrieb, inklusive der
 * Opt-out-Spalten je Mitteilungsart. Die Filterung nach Mitteilungsart
 * geschieht danach in PHP (ein Geraet erlaubt eine Art, wenn die zugehoerige
 * push_*-Spalte = 1 ist).
 */
function userDevicesAll(PDO $db, int $userId, int $farmId): array
{
    $sql = "SELECT id, token, environment, platform,
                   push_weiterbehandlung, push_wartezeit, push_traechtigkeit, push_hemmstoff
              FROM device_tokens
             WHERE user_id = :u AND is_active = 1
               AND (farm_id = :f OR farm_id IS NULL)
               AND platform = 'ios'";
    $stmt = $db->prepare($sql);
    $stmt->execute([':u' => $userId, ':f' => $farmId]);
    return $stmt->fetchAll();
}

/**
 * Verarbeitet alle faelligen Mitteilungen eines Benutzers fuer einen Betrieb:
 *   1. Dedupe (push_log) und Geraete-Filter (Opt-out je Art) anwenden.
 *   2. Dringende Mitteilungen (Wartezeit) IMMER einzeln senden.
 *   3. Restliche Mitteilungen: unter dem Schwellwert einzeln, sonst als EINE
 *      Sammel-Mitteilung je Betrieb/Tag (target=tasks).
 */
function dispatchForUser(
    PDO $db,
    ?string $jwt,
    array $cfg,
    int $farmId,
    string $farmName,
    int $userId,
    array $notifications,
    bool $dryRun,
    bool $verbose,
    int $summaryThreshold,
    array &$stats
): void {
    $devices = userDevicesAll($db, $userId, $farmId);
    if (empty($devices)) {
        return;
    }

    $chk = $db->prepare(
        'SELECT 1 FROM push_log WHERE user_id = :u AND notification_type = :t AND ref_key = :r LIMIT 1'
    );

    // 1. Pending ermitteln: nicht dedupt UND mind. ein berechtigtes Geraet.
    $pending = [];
    foreach ($notifications as $n) {
        $chk->execute([':u' => $userId, ':t' => $n['type'], ':r' => $n['ref_key']]);
        if ($chk->fetchColumn()) {
            $stats['skipped']++;
            continue;
        }
        $elig = [];
        foreach ($devices as $d) {
            if ((int) $d[$n['pref_col']] === 1) {
                $elig[] = $d;
            }
        }
        if (empty($elig)) {
            // Kein passendes Geraet -> NICHT protokollieren (User kann sich
            // spaeter registrieren und erhaelt die Mitteilung dann noch).
            continue;
        }
        $n['_devices'] = $elig;
        $pending[] = $n;
        $stats['planned']++;
    }
    if (empty($pending)) {
        return;
    }

    // Badge je Geraet = Anzahl faelliger Aufgaben, die dieses Geraet betreffen.
    $badgeByDevice = [];
    foreach ($pending as $n) {
        foreach ($n['_devices'] as $d) {
            $id = (int) $d['id'];
            $badgeByDevice[$id] = ($badgeByDevice[$id] ?? 0) + (int) $n['count'];
        }
    }

    $threadId = 'farm-' . $farmId;

    // Dringend (Wartezeit) vs. normal trennen.
    $urgent = [];
    $normal = [];
    foreach ($pending as $n) {
        if ($n['type'] === 'wartezeit') {
            $urgent[] = $n;
        } else {
            $normal[] = $n;
        }
    }

    // 2. Dringende Mitteilungen: immer einzeln.
    foreach ($urgent as $n) {
        sendSingleNotification($db, $jwt, $cfg, $farmId, $userId, $n, $badgeByDevice, $threadId, $dryRun, $verbose, $stats);
    }

    // 3. Normale Mitteilungen: Einzel-Pushes oder Sammel-Mitteilung.
    if (count($normal) === 0) {
        return;
    }
    if (count($normal) < $summaryThreshold) {
        foreach ($normal as $n) {
            sendSingleNotification($db, $jwt, $cfg, $farmId, $userId, $n, $badgeByDevice, $threadId, $dryRun, $verbose, $stats);
        }
    } else {
        sendSummaryNotification($db, $jwt, $cfg, $farmId, $farmName, $userId, $normal, $badgeByDevice, $threadId, $dryRun, $verbose, $stats);
    }
}

/**
 * Sendet eine EINZELNE Mitteilung an alle berechtigten Geraete des Benutzers
 * und protokolliert sie (einmal pro Benutzer + Art + Bezug).
 */
function sendSingleNotification(
    PDO $db,
    ?string $jwt,
    array $cfg,
    int $farmId,
    int $userId,
    array $n,
    array $badgeByDevice,
    string $threadId,
    bool $dryRun,
    bool $verbose,
    array &$stats
): void {
    $anyOk = false;
    foreach ($n['_devices'] as $dev) {
        $badge   = $badgeByDevice[(int) $dev['id']] ?? null;
        $payload = buildApnsPayload($n['title'], $n['body'], $n['type'], $farmId, $n['cattle_id'], $badge, $threadId);

        if ($dryRun) {
            logLine('  [DRY-RUN] user=' . $userId . ' EINZEL type=' . $n['type']
                . ' ref=' . $n['ref_key'] . ' geraet=' . $dev['id'] . ' badge=' . ($badge ?? 0)
                . ' payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $anyOk = true;
            continue;
        }
        if (sendOneDevice($db, $jwt, $cfg, $dev, $payload, [], $verbose, $stats)) {
            $anyOk = true;
        }
    }

    if ($anyOk && !$dryRun) {
        logPush($db, $userId, $farmId, $n['type'], $n['ref_key'], $n['cattle_id']);
    }
}

/**
 * Sendet EINE Sammel-Mitteilung (Tagesuebersicht) je Geraet. Jedes Geraet
 * erhaelt nur die Kategorien, die es erlaubt. Ein apns-collapse-id je
 * Betrieb/Tag sorgt dafuer, dass eine neuere Uebersicht die aeltere ersetzt.
 */
function sendSummaryNotification(
    PDO $db,
    ?string $jwt,
    array $cfg,
    int $farmId,
    string $farmName,
    int $userId,
    array $normal,
    array $badgeByDevice,
    string $threadId,
    bool $dryRun,
    bool $verbose,
    array &$stats
): void {
    $collapseId = 'sum-' . $farmId . '-' . date('Ymd');
    $title      = $farmName . ' - heute faellig';

    // Alle Geraete einsammeln, die mind. eine normale Mitteilung erhalten sollen.
    $deviceById = [];
    foreach ($normal as $n) {
        foreach ($n['_devices'] as $d) {
            $deviceById[(int) $d['id']] = $d;
        }
    }

    $okDeviceIds = [];
    foreach ($deviceById as $devId => $dev) {
        // Kategorien (Summen) nur fuer die auf diesem Geraet erlaubten Arten.
        $counts = [];
        foreach ($normal as $n) {
            if ((int) $dev[$n['pref_col']] !== 1) {
                continue;
            }
            $counts[$n['type']] = ($counts[$n['type']] ?? 0) + (int) $n['count'];
        }
        if (empty($counts)) {
            continue;
        }

        $body   = summaryBody($counts);
        $filter = (count($counts) === 1) ? array_key_first($counts) : null;
        $badge  = $badgeByDevice[$devId] ?? array_sum($counts);

        $payload = buildSummaryPayload($title, $body, $farmId, $badge, $threadId, $filter);

        if ($dryRun) {
            logLine('  [DRY-RUN] user=' . $userId . ' SAMMEL geraet=' . $devId
                . ' collapse=' . $collapseId . ' badge=' . $badge
                . ' payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $okDeviceIds[$devId] = true;
            continue;
        }
        if (sendOneDevice($db, $jwt, $cfg, $dev, $payload, ['apns-collapse-id' => $collapseId], $verbose, $stats)) {
            $okDeviceIds[$devId] = true;
        }
    }

    // Protokollieren: jede normale Mitteilung, deren Geraet die Uebersicht erhielt.
    if ($dryRun) {
        return;
    }
    foreach ($normal as $n) {
        $delivered = false;
        foreach ($n['_devices'] as $d) {
            if (isset($okDeviceIds[(int) $d['id']])) {
                $delivered = true;
                break;
            }
        }
        if ($delivered) {
            logPush($db, $userId, $farmId, $n['type'], $n['ref_key'], $n['cattle_id']);
        }
    }
}

/**
 * Baut den Text einer Sammel-Mitteilung, z. B.
 * "5 Traechtigkeitskontrollen, 2 Hemmstoffproben, 1 Weiterbehandlung".
 *
 * @param array<string,int> $counts type => Anzahl
 */
function summaryBody(array $counts): string
{
    $labels = [
        'traechtigkeit'    => ['Traechtigkeitskontrolle', 'Traechtigkeitskontrollen'],
        'hemmstoff'        => ['Hemmstoffprobe (Ergebnis)', 'Hemmstoffproben (Ergebnis)'],
        'weiterbehandlung' => ['Weiterbehandlung', 'Weiterbehandlungen'],
    ];
    $order = ['traechtigkeit', 'hemmstoff', 'weiterbehandlung'];

    $parts = [];
    foreach ($order as $type) {
        if (empty($counts[$type])) {
            continue;
        }
        $c   = (int) $counts[$type];
        $lbl = $labels[$type] ?? [$type, $type];
        $parts[] = $c . ' ' . ($c === 1 ? $lbl[0] : $lbl[1]);
    }
    return implode(', ', $parts);
}

/**
 * Sendet ein Payload an EIN Geraet, verbucht die Statistik und deaktiviert
 * dauerhaft ungueltige Tokens. Gibt true zurueck, wenn der Versand ok war.
 */
function sendOneDevice(
    PDO $db,
    string $jwt,
    array $cfg,
    array $dev,
    array $payload,
    array $extraHeaders,
    bool $verbose,
    array &$stats
): bool {
    $env = $dev['environment'] ?: $cfg['default_env'];
    $res = apnsSend($jwt, (string) $dev['token'], $payload, $env, $cfg, $extraHeaders);

    if ($res['ok']) {
        $stats['sent']++;
        return true;
    }
    if ($res['invalid']) {
        $db->prepare('UPDATE device_tokens SET is_active = 0 WHERE id = :id')
           ->execute([':id' => (int) $dev['id']]);
        $stats['invalid']++;
        if ($verbose) {
            logLine('  Token deaktiviert (id=' . $dev['id'] . ', reason=' . ($res['reason'] ?? '') . ')');
        }
    } else {
        logLine('  Push fehlgeschlagen (geraet=' . $dev['id'] . ', status=' . $res['status']
            . ', reason=' . ($res['reason'] ?? '') . ')');
    }
    return false;
}

/**
 * Protokolliert eine zugestellte Mitteilung (einmal pro Benutzer + Art + Bezug).
 */
function logPush(PDO $db, int $userId, int $farmId, string $type, string $refKey, ?int $cattleId): void
{
    $ins = $db->prepare(
        'INSERT IGNORE INTO push_log (user_id, farm_id, notification_type, ref_key, cattle_id, status)
         VALUES (:u, :f, :t, :r, :c, :s)'
    );
    $ins->execute([
        ':u' => $userId, ':f' => $farmId, ':t' => $type,
        ':r' => $refKey, ':c' => $cattleId, ':s' => 'sent',
    ]);
}
