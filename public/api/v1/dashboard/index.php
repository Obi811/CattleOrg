<?php
/**
 * GET /api/v1/dashboard
 * -----------------------------------------------------------------------------
 * Liefert die faelligen Aufgaben eines Betriebs in einem Rutsch (fuer die
 * Startansicht/To-do-Liste der iOS-App). Fachlogik entspricht
 * public/todo_list.php bzw. cron/push_notifications.php.
 *
 * Berechtigung: cattle.read. Strikt betriebsbezogen (farm_id).
 *
 * Antwort (data):
 *   weiterbehandlungen[]   Behandlungen von gestern (ausser Trockenstellen)
 *   hemmstoff_faellig[]    Tiere mit abgelaufener Wartezeit ohne Freigabe/Probe
 *   hemmstoff_versendet[]  versendete Proben, Ergebnis ausstehend
 *   tu_faellig[]           Traechtigkeitskontrolle faellig (>= 35 Tage)
 *   wartezeit_aktiv[]      Tiere mit aktuell laufender Wartezeit
 *   counts{ ...je Liste..., gesamt }
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

apiRequirePermission($farm, 'cattle.read');

$gestern = (new DateTime('yesterday'))->format('Y-m-d');

// --- 1. Weiterbehandlungen (Behandlungen von gestern, ausser Trockenstellen) ---
$ws = $db->prepare(
    "SELECT t.id, t.cattle_id, c.ear_tag, t.treatment_date, t.type, t.medication, t.dosage, t.notes
       FROM treatments t
       JOIN cattle c ON c.id = t.cattle_id AND c.farm_id = t.farm_id
      WHERE t.farm_id = :f AND t.treatment_date = :g AND t.type <> 'Trockenstellen'
      ORDER BY c.ear_tag"
);
$ws->execute([':f' => $farmId, ':g' => $gestern]);
$weiterbehandlungen = $ws->fetchAll();

// --- 2. Hemmstoffprobe faellig (Wartezeit abgelaufen) ---
$hsql = "
    SELECT c.id AS cattle_id, c.ear_tag, t.treatment_date, t.medication,
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
      )
    ORDER BY c.ear_tag";
$hs = $db->prepare($hsql);
$hs->execute([':f1' => $farmId, ':f2' => $farmId, ':f3' => $farmId]);
$hemmstoffFaellig = $hs->fetchAll();

// --- 3. Hemmstoffproben "versendet" (Ergebnis ausstehend) ---
$vs = $db->prepare(
    "SELECT hp.id, hp.cattle_id, c.ear_tag, hp.nummer, hp.probe_date
       FROM hemmstoff_probes hp
       JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
      WHERE hp.farm_id = :f AND hp.status = 'versendet'
      ORDER BY hp.probe_date ASC"
);
$vs->execute([':f' => $farmId]);
$hemmstoffVersendet = $vs->fetchAll();

// --- 4. Offene Traechtigkeitskontrollen (>= 35 Tage seit Besamung) ---
$psql = "
    SELECT c.id AS cattle_id, c.ear_tag, i.date AS last_insemination,
           DATEDIFF(CURDATE(), i.date) AS tage
    FROM cattle c
    JOIN inseminations i ON i.cattle_id = c.id AND i.farm_id = c.farm_id
    JOIN (SELECT cattle_id, MAX(date) maxdate FROM inseminations WHERE farm_id = :f1 GROUP BY cattle_id) lm
      ON lm.cattle_id = i.cattle_id AND lm.maxdate = i.date
    WHERE c.farm_id = :f2 AND c.status = 'Aktiv'
      AND DATEDIFF(CURDATE(), i.date) >= 35
      AND NOT EXISTS (SELECT 1 FROM pregnancy_checks p
                      WHERE p.cattle_id = c.id AND p.farm_id = c.farm_id AND p.check_date >= i.date)
    ORDER BY c.ear_tag";
$ps = $db->prepare($psql);
$ps->execute([':f1' => $farmId, ':f2' => $farmId]);
$tuFaellig = $ps->fetchAll();

// --- 5. Aktuell laufende Wartezeiten (max(wz_milk, wz_fleisch) >= heute) ---
$wasql = "
    SELECT c.id AS cattle_id, c.ear_tag, t.treatment_date, t.medication,
           DATE_ADD(t.treatment_date,
                    INTERVAL GREATEST(COALESCE(m.wz_milk,0), COALESCE(m.wz_fleisch,0)) DAY) AS wz_ende
    FROM cattle c
    JOIN (SELECT cattle_id, MAX(treatment_date) AS last_td
          FROM treatments WHERE farm_id = :f1 GROUP BY cattle_id) lt ON lt.cattle_id = c.id
    JOIN treatments t ON t.cattle_id = c.id AND t.treatment_date = lt.last_td AND t.farm_id = :f2
    JOIN medications m ON m.name = t.medication
    WHERE c.farm_id = :f3 AND c.status = 'Aktiv'
      AND GREATEST(COALESCE(m.wz_milk,0), COALESCE(m.wz_fleisch,0)) > 0
      AND DATE_ADD(t.treatment_date,
                   INTERVAL GREATEST(COALESCE(m.wz_milk,0), COALESCE(m.wz_fleisch,0)) DAY) >= CURDATE()
    ORDER BY wz_ende ASC";
$wa = $db->prepare($wasql);
$wa->execute([':f1' => $farmId, ':f2' => $farmId, ':f3' => $farmId]);
$wartezeitAktiv = $wa->fetchAll();

// --- 6. Faellige geplante Behandlungen (planned_treatments, status=geplant) ---
// HOTFIX Dashboard-500: additiver Block, abgesichert. Faellt dieser Teil aus
// (fehlende Tabelle/Include auf dem Server, Zwischenstand usw.), liefert das
// Dashboard trotzdem die Kern-Aufgaben mit HTTP 200 statt eines nackten 500.
$geplanteFaellig = [];
try {
    // includes/functions.php ist regulaer bereits ueber api_helpers.php geladen;
    // defensiv erneut einbinden, falls ein Server einen Zwischenstand deployt hat.
    if (is_file(__DIR__ . '/../../../includes/functions.php')) {
        require_once __DIR__ . '/../../../includes/functions.php';
    }
    if (is_file(__DIR__ . '/../../../includes/planned_treatments.php')) {
        require_once __DIR__ . '/../../../includes/planned_treatments.php';
    }
    if (function_exists('loadPlannedDue')) {
        $geplanteFaellig = loadPlannedDue($db, $farmId);
    } else {
        error_log('dashboard: loadPlannedDue() nicht verfuegbar (planned_treatments.php fehlt?) – Fallback leeres Array');
    }
} catch (\Throwable $e) {
    error_log('dashboard: geplante_faellig fehlgeschlagen: ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $geplanteFaellig = [];
}

// --- Kennzahlen Milchkuehe / Trockensteher (additiv) ---
// HOTFIX Dashboard-500: Die Antwort bleibt TYP-STABIL. Faellt eine
// Helper-Funktion/Spalte aus, ist die jeweilige Kennzahl 0 (eine Ganzzahl),
// niemals null – damit auch strikte Client-Modelle (iOS) die Antwort sicher
// dekodieren koennen. Die Kern-Antwort wird dadurch nicht blockiert.
$milchkuehe    = 0;
$trockensteher = 0;
try {
    if (function_exists('milchkuhCattleCondition')) {
        $milchCond = milchkuhCattleCondition('c');
        $ms = $db->prepare(
            'SELECT COUNT(*) FROM cattle c
              WHERE c.farm_id = :f AND c.status = "Aktiv" AND c.geschlecht = "W" AND ' . $milchCond
        );
        $ms->execute([':f' => $farmId]);
        $milchkuehe = (int) $ms->fetchColumn();
    } else {
        error_log('dashboard: milchkuhCattleCondition() nicht verfuegbar (functions.php Zwischenstand?) – Fallback 0');
    }
} catch (\Throwable $e) {
    error_log('dashboard: kennzahl milchkuehe fehlgeschlagen: ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $milchkuehe = 0;
}

try {
    if (function_exists('trockensteherCattleCondition')) {
        $trockCond = trockensteherCattleCondition('c');
        $ts = $db->prepare(
            'SELECT COUNT(*) FROM cattle c
              WHERE c.farm_id = :f AND c.status = "Aktiv" AND c.geschlecht = "W" AND ' . $trockCond
        );
        $ts->execute([':f' => $farmId]);
        $trockensteher = (int) $ts->fetchColumn();
    } else {
        error_log('dashboard: trockensteherCattleCondition() nicht verfuegbar (functions.php Zwischenstand?) – Fallback 0');
    }
} catch (\Throwable $e) {
    error_log('dashboard: kennzahl trockensteher fehlgeschlagen: ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $trockensteher = 0;
}

$counts = [
    'weiterbehandlungen'  => count($weiterbehandlungen),
    'hemmstoff_faellig'   => count($hemmstoffFaellig),
    'hemmstoff_versendet' => count($hemmstoffVersendet),
    'tu_faellig'          => count($tuFaellig),
    'wartezeit_aktiv'     => count($wartezeitAktiv),
    'geplante_faellig'    => count($geplanteFaellig),
];
$counts['gesamt'] = array_sum($counts);

jsonSuccess([
    'weiterbehandlungen'  => $weiterbehandlungen,
    'hemmstoff_faellig'   => $hemmstoffFaellig,
    'hemmstoff_versendet' => $hemmstoffVersendet,
    'tu_faellig'          => $tuFaellig,
    'wartezeit_aktiv'     => $wartezeitAktiv,
    'geplante_faellig'    => $geplanteFaellig,
    'counts'              => $counts,
    'kennzahlen'          => [
        'milchkuehe'    => $milchkuehe,
        'trockensteher' => $trockensteher,
    ],
]);
