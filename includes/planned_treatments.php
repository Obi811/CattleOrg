<?php
/**
 * includes/planned_treatments.php
 * -----------------------------------------------------------------------------
 * Zentrale Daten- und Aktionslogik fuer GEPLANTE Behandlungen
 * (Tabelle planned_treatments, Migration 015).
 *
 * Wird verwendet von:
 *   - public/index.php / public/todo_list.php (Aufgaben-Kategorie, Erledigt-Aktion)
 *   - public/cattle_show.php (Anzeige + Aktionen im Tierprofil)
 *   - public/calendar.php (geplante Behandlungen im Kalender)
 *   - public/group_actions.php (Behandlung fuer eine ganze Box planen)
 *   - public/api/v1/planned-treatments (API)
 *
 * Alle Abfragen sind strikt auf den uebergebenen Betrieb (farm_id) beschraenkt
 * und verwenden Prepared Statements. Mit PDO::ATTR_EMULATE_PREPARES=false
 * kommt jeder benannte Platzhalter je Query nur einmal vor.
 */

declare(strict_types=1);

if (!function_exists('loadPlannedDue')) {
    /**
     * Faellige und ueberfaellige geplante Behandlungen (status=geplant,
     * planned_date <= heute) aktiver Tiere. Fuer die Aufgaben-Ansicht.
     *
     * @return array<int,array<string,mixed>>
     */
    function loadPlannedDue(PDO $db, int $farmId): array
    {
        $sql = "SELECT p.id, p.cattle_id, p.planned_date, p.medication, p.dosage, p.type, p.notes,
                       c.ear_tag, DATEDIFF(CURDATE(), p.planned_date) AS tage_ueberfaellig
                FROM planned_treatments p
                JOIN cattle c ON c.id = p.cattle_id AND c.farm_id = p.farm_id
                WHERE p.farm_id = :f AND p.status = 'geplant'
                  AND p.planned_date <= CURDATE()
                  AND c.status = 'Aktiv'
                ORDER BY p.planned_date ASC, c.ear_tag";
        $st = $db->prepare($sql);
        $st->execute([':f' => $farmId]);
        return $st->fetchAll();
    }
}

if (!function_exists('loadPlannedForCattle')) {
    /**
     * Alle geplanten Behandlungen eines Tieres (offene zuerst, dann nach Datum).
     */
    function loadPlannedForCattle(PDO $db, int $farmId, int $cattleId): array
    {
        $sql = "SELECT p.id, p.planned_date, p.medication, p.dosage, p.type, p.notes,
                       p.status, p.treatment_id
                FROM planned_treatments p
                WHERE p.farm_id = :f AND p.cattle_id = :c
                ORDER BY (p.status = 'geplant') DESC, p.planned_date ASC, p.id DESC";
        $st = $db->prepare($sql);
        $st->execute([':f' => $farmId, ':c' => $cattleId]);
        return $st->fetchAll();
    }
}

if (!function_exists('loadPlannedInRange')) {
    /**
     * Offene geplante Behandlungen in einem Datumsbereich (fuer den Kalender).
     */
    function loadPlannedInRange(PDO $db, int $farmId, string $from, string $to): array
    {
        $sql = "SELECT p.id, p.cattle_id, p.planned_date AS d, p.medication, p.type, c.ear_tag
                FROM planned_treatments p
                JOIN cattle c ON c.id = p.cattle_id AND c.farm_id = p.farm_id
                WHERE p.farm_id = :f AND p.status = 'geplant'
                  AND p.planned_date BETWEEN :a AND :b
                ORDER BY p.planned_date ASC";
        $st = $db->prepare($sql);
        $st->execute([':f' => $farmId, ':a' => $from, ':b' => $to]);
        return $st->fetchAll();
    }
}

if (!function_exists('countPlannedDue')) {
    /** Anzahl faelliger/ueberfaelliger geplanter Behandlungen (aktive Tiere). */
    function countPlannedDue(PDO $db, int $farmId): int
    {
        $st = $db->prepare(
            "SELECT COUNT(*) FROM planned_treatments p
             JOIN cattle c ON c.id = p.cattle_id AND c.farm_id = p.farm_id
             WHERE p.farm_id = :f AND p.status = 'geplant'
               AND p.planned_date <= CURDATE() AND c.status = 'Aktiv'"
        );
        $st->execute([':f' => $farmId]);
        return (int) $st->fetchColumn();
    }
}

if (!function_exists('createPlannedTreatment')) {
    /**
     * Legt eine geplante Behandlung an. Validiert Tier (betriebsbezogen) und
     * Medikament (globaler Katalog). Gibt die neue ID zurueck oder null bei
     * Validierungsfehler (Fehlertext in $error).
     */
    function createPlannedTreatment(
        PDO $db,
        int $farmId,
        int $cattleId,
        string $plannedDate,
        string $medication,
        string $type,
        ?string $dosage,
        ?string $notes,
        ?int $userId,
        ?string &$error = null
    ): ?int {
        // Tier betriebsbezogen pruefen
        $cs = $db->prepare('SELECT id FROM cattle WHERE id = :id AND farm_id = :f');
        $cs->execute([':id' => $cattleId, ':f' => $farmId]);
        if (!$cs->fetch()) {
            $error = 'Tier nicht gefunden.';
            return null;
        }
        if ($plannedDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $plannedDate)) {
            $error = 'Bitte ein gueltiges geplantes Datum (JJJJ-MM-TT) angeben.';
            return null;
        }
        // Medikament aus globalem Katalog pruefen
        $ms = $db->prepare('SELECT COUNT(*) FROM medications WHERE name = :n');
        $ms->execute([':n' => $medication]);
        if ($medication === '' || (int) $ms->fetchColumn() === 0) {
            $error = 'Bitte ein gueltiges Medikament aus dem Katalog waehlen.';
            return null;
        }
        if (!in_array($type, treatmentTypes(), true)) {
            $type = 'Sonstige';
        }
        $ins = $db->prepare(
            'INSERT INTO planned_treatments
                (farm_id, cattle_id, planned_date, medication, dosage, type, notes, status, created_by)
             VALUES (:f, :c, :d, :m, :dos, :t, :n, :s, :u)'
        );
        $ins->execute([
            ':f' => $farmId, ':c' => $cattleId, ':d' => $plannedDate,
            ':m' => $medication, ':dos' => ($dosage !== null && $dosage !== '') ? $dosage : null,
            ':t' => $type, ':n' => ($notes !== null && $notes !== '') ? $notes : null,
            ':s' => 'geplant', ':u' => $userId,
        ]);
        return (int) $db->lastInsertId();
    }
}

if (!function_exists('completePlannedTreatment')) {
    /**
     * Markiert eine geplante Behandlung als erledigt und erzeugt daraus eine
     * echte Behandlung in `treatments`. Datum der Behandlung = $treatmentDate
     * (Standard: heute). Gibt die neue treatment-ID zurueck oder null, wenn die
     * geplante Behandlung nicht (mehr) offen ist.
     */
    function completePlannedTreatment(
        PDO $db,
        int $farmId,
        int $plannedId,
        ?string $treatmentDate,
        ?int $userId,
        ?string &$error = null
    ): ?int {
        $ps = $db->prepare(
            "SELECT * FROM planned_treatments
             WHERE id = :id AND farm_id = :f AND status = 'geplant'"
        );
        $ps->execute([':id' => $plannedId, ':f' => $farmId]);
        $plan = $ps->fetch();
        if (!$plan) {
            $error = 'Geplante Behandlung nicht gefunden oder bereits abgeschlossen.';
            return null;
        }
        $date = ($treatmentDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $treatmentDate))
            ? $treatmentDate
            : date('Y-m-d');

        $db->beginTransaction();
        try {
            $ins = $db->prepare(
                'INSERT INTO treatments (farm_id, cattle_id, treatment_date, medication, dosage, type, notes)
                 VALUES (:f, :c, :d, :m, :dos, :t, :n)'
            );
            $ins->execute([
                ':f' => $farmId, ':c' => (int) $plan['cattle_id'], ':d' => $date,
                ':m' => $plan['medication'], ':dos' => $plan['dosage'],
                ':t' => $plan['type'], ':n' => $plan['notes'],
            ]);
            $treatmentId = (int) $db->lastInsertId();

            $upd = $db->prepare(
                "UPDATE planned_treatments
                 SET status = 'erledigt', treatment_id = :tid, done_by = :u, done_at = NOW()
                 WHERE id = :id AND farm_id = :f"
            );
            $upd->execute([':tid' => $treatmentId, ':u' => $userId, ':id' => $plannedId, ':f' => $farmId]);

            $db->commit();
            return $treatmentId;
        } catch (Throwable $ex) {
            $db->rollBack();
            $error = 'Speichern fehlgeschlagen.';
            return null;
        }
    }
}

if (!function_exists('dismissPlannedTreatment')) {
    /** Verwirft eine geplante Behandlung (status=verworfen). */
    function dismissPlannedTreatment(PDO $db, int $farmId, int $plannedId, ?int $userId): bool
    {
        $upd = $db->prepare(
            "UPDATE planned_treatments
             SET status = 'verworfen', done_by = :u, done_at = NOW()
             WHERE id = :id AND farm_id = :f AND status = 'geplant'"
        );
        $upd->execute([':u' => $userId, ':id' => $plannedId, ':f' => $farmId]);
        return $upd->rowCount() > 0;
    }
}

if (!function_exists('deletePlannedTreatment')) {
    /** Loescht eine geplante Behandlung dauerhaft. */
    function deletePlannedTreatment(PDO $db, int $farmId, int $plannedId): bool
    {
        $del = $db->prepare('DELETE FROM planned_treatments WHERE id = :id AND farm_id = :f');
        $del->execute([':id' => $plannedId, ':f' => $farmId]);
        return $del->rowCount() > 0;
    }
}

if (!function_exists('handlePlannedTreatmentAction')) {
    /**
     * Verarbeitet eine geplante-Behandlung-Aktion aus $_POST, sofern
     * $_POST['action'] eine der folgenden ist: plan_complete, plan_dismiss,
     * plan_delete. Setzt eine Flash-Nachricht. Prueft Berechtigung
     * (treatments.write) und Demo-Schutz. CSRF muss der Aufrufer bereits
     * geprueft haben.
     *
     * @return bool true, wenn eine Aktion erkannt (und ggf. ausgefuehrt) wurde.
     */
    function handlePlannedTreatmentAction(PDO $db, int $farmId): bool
    {
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['plan_complete', 'plan_dismiss', 'plan_delete'], true)) {
            return false;
        }
        $canTreat = hasPermission('treatments.write') && !isDemoBetrieb();
        if (!$canTreat) {
            flash('error', 'Keine Berechtigung fuer geplante Behandlungen.', 'danger');
            return true;
        }
        $userId    = currentUserId();
        $plannedId = (int) ($_POST['planned_id'] ?? 0);

        if ($action === 'plan_complete') {
            $date = trim((string) ($_POST['treatment_date'] ?? '')) ?: date('Y-m-d');
            $err = null;
            $tid = completePlannedTreatment($db, $farmId, $plannedId, $date, $userId, $err);
            if ($tid !== null) {
                flash('success', 'Geplante Behandlung als erledigt uebernommen (Behandlung erfasst).', 'success');
            } else {
                flash('error', $err ?? 'Aktion fehlgeschlagen.', 'danger');
            }
        } elseif ($action === 'plan_dismiss') {
            dismissPlannedTreatment($db, $farmId, $plannedId, $userId);
            flash('success', 'Geplante Behandlung verworfen.', 'success');
        } elseif ($action === 'plan_delete') {
            deletePlannedTreatment($db, $farmId, $plannedId);
            flash('success', 'Geplante Behandlung geloescht.', 'success');
        }
        return true;
    }
}
