<?php
/**
 * includes/task_queries.php
 * -----------------------------------------------------------------------------
 * Zentrale Abfragen fuer die Aufgaben-/Ueberblicksansicht. Wird sowohl vom
 * Dashboard (index.php) als auch von der Aufgabenliste (todo_list.php)
 * eingebunden, damit beide dieselbe Logik verwenden.
 *
 * Alle Abfragen sind strikt auf den uebergebenen Betrieb (farm_id) beschraenkt
 * und verwenden ausschliesslich Prepared Statements.
 */

declare(strict_types=1);

if (!function_exists('loadTasks')) {
    /**
     * Laedt alle Aufgaben-Datensaetze des Betriebs gruppiert nach Thema.
     *
     * Rueckgabe (Array):
     *   'nachbehandlungen'   => offene Nachbehandlungs-Vorschlaege (gestern behandelt)
     *   'vorbehandlungen'    => Kontext je cattle_id (letzte 14 Tage)
     *   'hemm_faellig'       => Hemmstoffprobe faellig (Wartezeit abgelaufen, nicht verschickt)
     *   'hemm_sent_today'    => heute als "weggeschickt" markierte Proben (erledigt heute)
     *   'hemm_versendet'     => verschickt, Ergebnis ausstehend
     *   'hemm_result_today'  => heute erfasstes Ergebnis (erledigt heute)
     *   'traechtig_offen'    => offene Traechtigkeitskontrollen
     *   'traechtig_today'    => heute erfasste Kontrollen (erledigt heute)
     *   'counts'             => Zaehler je Thema (nur offene Aufgaben)
     */
    function loadTasks(PDO $db, int $farmId): array
    {
        $gestern = (new DateTime('yesterday'))->format('Y-m-d');
        $vor14   = (new DateTime('-14 days'))->format('Y-m-d');

        // --- 1. Nachbehandlungen: gestern behandelt (ausser Trockenstellen),
        //        heute noch nicht behandelt und nicht als "nicht noetig" markiert ---
        $wsql = "SELECT t.id, t.cattle_id, t.treatment_date, t.type, t.medication, t.dosage, t.notes, c.ear_tag
                 FROM treatments t
                 JOIN cattle c ON c.id = t.cattle_id AND c.farm_id = t.farm_id
                 WHERE t.farm_id = :f AND t.treatment_date = :g AND t.type <> 'Trockenstellen'
                   AND NOT EXISTS (
                       SELECT 1 FROM followup_dismissals fd
                       WHERE fd.farm_id = t.farm_id AND fd.treatment_id = t.id AND fd.dismiss_date = CURDATE()
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM treatments t2
                       WHERE t2.farm_id = t.farm_id AND t2.cattle_id = t.cattle_id AND t2.treatment_date = CURDATE()
                   )
                 ORDER BY c.ear_tag";
        $ws = $db->prepare($wsql);
        $ws->execute([':f' => $farmId, ':g' => $gestern]);
        $nachbehandlungen = $ws->fetchAll();

        // Vorbehandlungen der letzten 14 Tage je betroffenem Tier (Kontext)
        $vorbehandlungen = [];
        $cattleIds = array_unique(array_column($nachbehandlungen, 'cattle_id'));
        if (!empty($cattleIds)) {
            $ph = implode(',', array_fill(0, count($cattleIds), '?'));
            $vsql = "SELECT cattle_id, treatment_date, type, medication, dosage
                     FROM treatments
                     WHERE farm_id = ? AND cattle_id IN ($ph)
                       AND treatment_date < ? AND treatment_date >= ?
                     ORDER BY cattle_id, treatment_date DESC";
            $vs = $db->prepare($vsql);
            $vs->execute(array_merge([$farmId], array_values($cattleIds), [$gestern, $vor14]));
            foreach ($vs->fetchAll() as $r) {
                $vorbehandlungen[$r['cattle_id']][] = $r;
            }
        }

        // --- 2. Hemmstoffprobe faellig: Wartezeit (wz_milk) abgelaufen, keine "frei"-Probe danach,
        //        noch keine verschickte Probe offen ---
        $hsql = "
            SELECT c.id, c.ear_tag, t.treatment_date, m.wz_milk,
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
            ORDER BY wartezeit_ende ASC";
        $hs = $db->prepare($hsql);
        $hs->execute([':f1' => $farmId, ':f2' => $farmId, ':f3' => $farmId]);
        $hemmFaellig = $hs->fetchAll();

        // Heute als "weggeschickt" markierte Proben (erledigt heute)
        $hst = $db->prepare(
            "SELECT hp.id, hp.nummer, hp.probe_date, hp.sent_at, c.ear_tag, c.id AS cattle_id
             FROM hemmstoff_probes hp JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
             WHERE hp.farm_id = :f AND hp.sent_at IS NOT NULL AND DATE(hp.sent_at) = CURDATE()
             ORDER BY hp.sent_at DESC"
        );
        $hst->execute([':f' => $farmId]);
        $hemmSentToday = $hst->fetchAll();

        // --- 3. Hemmstoffproben verschickt, Ergebnis ausstehend ---
        $h = $db->prepare(
            "SELECT hp.id, hp.nummer, hp.probe_date, c.ear_tag, c.id AS cattle_id
             FROM hemmstoff_probes hp JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
             WHERE hp.farm_id = :f AND hp.status = 'versendet' ORDER BY hp.probe_date ASC"
        );
        $h->execute([':f' => $farmId]);
        $hemmVersendet = $h->fetchAll();

        // Heute erfasstes Ergebnis (erledigt heute)
        $hrt = $db->prepare(
            "SELECT hp.id, hp.nummer, hp.status, hp.result_at, c.ear_tag, c.id AS cattle_id, u.username AS result_user
             FROM hemmstoff_probes hp
             JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
             LEFT JOIN users u ON u.id = hp.result_by
             WHERE hp.farm_id = :f AND hp.result_at IS NOT NULL AND DATE(hp.result_at) = CURDATE()
             ORDER BY hp.result_at DESC"
        );
        $hrt->execute([':f' => $farmId]);
        $hemmResultToday = $hrt->fetchAll();

        // --- 4. Offene Traechtigkeitskontrollen (>= 35 Tage seit Besamung) ---
        $psql = "
            SELECT c.id, c.ear_tag, i.date AS last_insem, DATEDIFF(CURDATE(), i.date) AS tage
            FROM cattle c
            JOIN inseminations i ON i.cattle_id = c.id AND i.farm_id = c.farm_id
            JOIN (SELECT cattle_id, MAX(date) maxdate FROM inseminations WHERE farm_id=:f1 GROUP BY cattle_id) lm
              ON lm.cattle_id=i.cattle_id AND lm.maxdate=i.date
            WHERE c.farm_id=:f2 AND c.status='Aktiv'
              AND DATEDIFF(CURDATE(), i.date) >= 35
              AND NOT EXISTS (SELECT 1 FROM pregnancy_checks p WHERE p.cattle_id=c.id AND p.farm_id=c.farm_id AND p.check_date >= i.date)
            ORDER BY i.date ASC";
        $ps = $db->prepare($psql);
        $ps->execute([':f1' => $farmId, ':f2' => $farmId]);
        $traechtigOffen = $ps->fetchAll();

        // Heute erfasste Traechtigkeitskontrollen (erledigt heute)
        $pt = $db->prepare(
            "SELECT p.id, p.result, p.comment, p.created_at, c.ear_tag, c.id AS cattle_id
             FROM pregnancy_checks p JOIN cattle c ON c.id = p.cattle_id AND c.farm_id = p.farm_id
             WHERE p.farm_id = :f AND p.check_date = CURDATE()
             ORDER BY p.id DESC"
        );
        $pt->execute([':f' => $farmId]);
        $traechtigToday = $pt->fetchAll();

        return [
            'nachbehandlungen'  => $nachbehandlungen,
            'vorbehandlungen'   => $vorbehandlungen,
            'hemm_faellig'      => $hemmFaellig,
            'hemm_sent_today'   => $hemmSentToday,
            'hemm_versendet'    => $hemmVersendet,
            'hemm_result_today' => $hemmResultToday,
            'traechtig_offen'   => $traechtigOffen,
            'traechtig_today'   => $traechtigToday,
            'counts' => [
                'nachbehandlungen' => count($nachbehandlungen),
                'hemm_faellig'     => count($hemmFaellig),
                'hemm_versendet'   => count($hemmVersendet),
                'traechtig_offen'  => count($traechtigOffen),
            ],
        ];
    }
}
