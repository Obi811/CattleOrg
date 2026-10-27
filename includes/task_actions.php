<?php
/**
 * includes/task_actions.php
 * -----------------------------------------------------------------------------
 * Gemeinsame POST-Verarbeitung der Aufgaben-Schnellaktionen. Wird von
 * index.php (Dashboard) und todo_list.php eingebunden, damit die
 * Inline-Erfassung (Hemmstoff-Ergebnis, Probe weggeschickt,
 * Traechtigkeitsergebnis, Nachbehandlung) an beiden Stellen identisch
 * funktioniert.
 *
 * Alle Schreibzugriffe sind betriebsbezogen (farm_id), CSRF-geschuetzt,
 * im Demo-Betrieb gesperrt und verwenden Prepared Statements.
 */

declare(strict_types=1);

if (!function_exists('handleTaskPost')) {
    /**
     * Verarbeitet eine Aufgaben-Schnellaktion aus $_POST und leitet danach
     * auf $redirectTo zurueck. Gibt true zurueck, wenn eine Aktion erkannt
     * und (nach Berechtigung) verarbeitet wurde.
     */
    function handleTaskPost(PDO $db, int $farmId, string $redirectTo): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }
        $action = $_POST['action'] ?? '';
        $taskActions = ['weiterbehandeln', 'hemm_result', 'hemm_sent', 'preg_result', 'followup_dismiss'];
        if (!in_array($action, $taskActions, true)) {
            return false;
        }

        requireCsrf();

        $canTreat = hasPermission('treatments.write') && !isDemoBetrieb();
        $canPreg  = hasPermission('pregnancy.write')   && !isDemoBetrieb();
        $userId   = currentUserId();

        switch ($action) {
            // --- Nachbehandlung: gestrige Behandlung heute uebernehmen (bestehende Logik) ---
            case 'weiterbehandeln':
                if ($canTreat) {
                    $treatmentId = (int) ($_POST['treatment_id'] ?? 0);
                    $s = $db->prepare('SELECT * FROM treatments WHERE id = :id AND farm_id = :f');
                    $s->execute([':id' => $treatmentId, ':f' => $farmId]);
                    $orig = $s->fetch();
                    if ($orig) {
                        $ins = $db->prepare(
                            'INSERT INTO treatments (farm_id, cattle_id, treatment_date, medication, dosage, type, notes)
                             VALUES (:f, :c, :d, :m, :dos, :t, :n)'
                        );
                        $ins->execute([
                            ':f' => $farmId, ':c' => $orig['cattle_id'], ':d' => date('Y-m-d'),
                            ':m' => $orig['medication'], ':dos' => $orig['dosage'],
                            ':t' => $orig['type'], ':n' => $orig['notes'],
                        ]);
                        flash('success', 'Behandlung wurde fuer heute uebernommen.', 'success');
                    }
                }
                break;

            // --- Nachbehandlung "Nicht noetig": Vorschlag fuer heute ausblenden ---
            case 'followup_dismiss':
                if ($canTreat) {
                    $treatmentId = (int) ($_POST['treatment_id'] ?? 0);
                    // Behandlung betriebsbezogen pruefen (cattle_id ermitteln)
                    $s = $db->prepare('SELECT id, cattle_id FROM treatments WHERE id = :id AND farm_id = :f');
                    $s->execute([':id' => $treatmentId, ':f' => $farmId]);
                    $orig = $s->fetch();
                    if ($orig) {
                        $ins = $db->prepare(
                            'INSERT INTO followup_dismissals (farm_id, cattle_id, treatment_id, dismiss_date, dismissed_by)
                             VALUES (:f, :c, :t, CURDATE(), :u)
                             ON DUPLICATE KEY UPDATE dismissed_by = VALUES(dismissed_by)'
                        );
                        $ins->execute([':f' => $farmId, ':c' => $orig['cattle_id'], ':t' => $treatmentId, ':u' => $userId]);
                        flash('success', 'Nachbehandlung als "nicht noetig" markiert.', 'success');
                    }
                }
                break;

            // --- Hemmstoffprobe: Ergebnis frei / nicht frei vor Ort erfassen ---
            case 'hemm_result':
                if ($canTreat) {
                    $probeId   = (int) ($_POST['probe_id'] ?? 0);
                    $newStatus = $_POST['new_status'] ?? '';
                    if (in_array($newStatus, ['frei', 'nicht frei'], true)) {
                        $upd = $db->prepare(
                            'UPDATE hemmstoff_probes
                             SET status = :s, result_at = NOW(), result_by = :u
                             WHERE id = :id AND farm_id = :f'
                        );
                        $upd->execute([':s' => $newStatus, ':u' => $userId, ':id' => $probeId, ':f' => $farmId]);
                        flash('success', 'Ergebnis der Hemmstoffprobe gespeichert: ' . $newStatus . '.', 'success');
                    }
                }
                break;

            // --- Hemmstoffprobe: nach Ablauf der Wartezeit als weggeschickt markieren ---
            case 'hemm_sent':
                if ($canTreat) {
                    $cattleId = (int) ($_POST['cattle_id'] ?? 0);
                    $nummer   = trim((string) ($_POST['nummer'] ?? ''));
                    $sentDate = trim((string) ($_POST['sent_date'] ?? '')) ?: date('Y-m-d');
                    // Tier betriebsbezogen pruefen
                    $cs = $db->prepare('SELECT id FROM cattle WHERE id = :id AND farm_id = :f');
                    $cs->execute([':id' => $cattleId, ':f' => $farmId]);
                    if ($cs->fetch() && $nummer !== '') {
                        $ins = $db->prepare(
                            'INSERT INTO hemmstoff_probes (farm_id, cattle_id, probe_date, status, nummer, sent_at, sent_by)
                             VALUES (:f, :c, :d, :s, :n, NOW(), :u)'
                        );
                        $ins->execute([
                            ':f' => $farmId, ':c' => $cattleId, ':d' => $sentDate,
                            ':s' => 'versendet', ':n' => $nummer, ':u' => $userId,
                        ]);
                        flash('success', 'Hemmstoffprobe Nr. ' . $nummer . ' als weggeschickt gespeichert.', 'success');
                    } else {
                        flash('error', 'Bitte eine laufende Nummer angeben.', 'danger');
                    }
                }
                break;

            // --- Traechtigkeitskontrolle: Ergebnis traechtig / nicht traechtig vor Ort erfassen ---
            case 'preg_result':
                if ($canPreg) {
                    $cattleId = (int) ($_POST['cattle_id'] ?? 0);
                    $result   = $_POST['result'] ?? '';
                    if (in_array($result, ['+', '-'], true)) {
                        $cs = $db->prepare('SELECT id FROM cattle WHERE id = :id AND farm_id = :f');
                        $cs->execute([':id' => $cattleId, ':f' => $farmId]);
                        if ($cs->fetch()) {
                            $ins = $db->prepare(
                                'INSERT INTO pregnancy_checks (farm_id, cattle_id, check_date, result)
                                 VALUES (:f, :c, CURDATE(), :r)'
                            );
                            $ins->execute([':f' => $farmId, ':c' => $cattleId, ':r' => $result]);
                            $text = $result === '+' ? 'traechtig' : 'nicht traechtig';
                            flash('success', 'Traechtigkeitskontrolle gespeichert: ' . $text . '.', 'success');
                        }
                    }
                }
                break;
        }

        redirect($redirectTo);
        return true; // nicht erreichbar (redirect beendet), dient der Klarheit
    }
}
