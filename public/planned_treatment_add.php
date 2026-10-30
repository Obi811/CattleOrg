<?php
/**
 * public/planned_treatment_add.php
 * -----------------------------------------------------------------------------
 * Geplante (Nach-)Behandlung fuer ein Tier anlegen oder bearbeiten.
 *
 * Anlegen:  ?cattle_id=123  (optional vorbelegt: medication, dosage, type,
 *           planned_date – z. B. aus einem Nachbehandlungsvorschlag der Aufgaben)
 * Bearbeiten: ?id=45  (laedt die geplante Behandlung, nur solange status=geplant)
 *
 * Medikament wird aus dem GLOBALEN Katalog gewaehlt. Strikt betriebsbezogen,
 * CSRF-geschuetzt, treatments.write erforderlich, Demo-Betrieb schreibgeschuetzt.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/planned_treatments.php';

requireLogin();
requirePermission('treatments.write');
requireWriteAccess();
$farmId = getCurrentFarmId();

$planId   = (int) ($_GET['id'] ?? $_POST['plan_id'] ?? 0);
$cattleId = (int) ($_GET['cattle_id'] ?? $_POST['cattle_id'] ?? 0);

$editing = false;
$plan    = null;
if ($planId > 0) {
    $ps = $db->prepare("SELECT * FROM planned_treatments WHERE id = :id AND farm_id = :f");
    $ps->execute([':id' => $planId, ':f' => $farmId]);
    $plan = $ps->fetch();
    if (!$plan) {
        http_response_code(404);
        flash('error', 'Geplante Behandlung nicht gefunden.', 'danger');
        redirect(url('todo_list.php'));
    }
    $cattleId = (int) $plan['cattle_id'];
    $editing  = true;
}

// Tier betriebsbezogen pruefen
$stmt = $db->prepare('SELECT id, ear_tag FROM cattle WHERE id = :id AND farm_id = :f');
$stmt->execute([':id' => $cattleId, ':f' => $farmId]);
$cattle = $stmt->fetch();
if (!$cattle) {
    http_response_code(404);
    flash('error', 'Tier nicht gefunden.', 'danger');
    redirect(url('cattle_list.php'));
}

$meds = $db->query('SELECT name FROM medications ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

// Vorbelegung (Bearbeiten -> aus DB, sonst aus GET-Parametern)
$prefillMed  = $editing ? (string) $plan['medication'] : (string) ($_GET['medication'] ?? '');
$prefillDos  = $editing ? (string) $plan['dosage']     : (string) ($_GET['dosage'] ?? '');
$prefillType = $editing ? (string) $plan['type']       : (string) ($_GET['type'] ?? '');
$prefillDate = $editing ? (string) $plan['planned_date'] : (string) ($_GET['planned_date'] ?? date('Y-m-d'));
$prefillNote = $editing ? (string) $plan['notes']      : '';
if ($prefillMed !== '' && !in_array($prefillMed, $meds, true)) { $prefillMed = ''; }
if ($prefillType !== '' && !in_array($prefillType, treatmentTypes(), true)) { $prefillType = ''; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $plannedDate = trim($_POST['planned_date'] ?? '');
    $medication  = trim($_POST['medication'] ?? '');
    $type        = trim($_POST['type'] ?? 'Sonstige');
    $dosage      = trim($_POST['dosage'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');

    if ($editing) {
        // Nur offene (geplante) Eintraege duerfen bearbeitet werden
        if ($plan['status'] !== 'geplant') {
            flash('error', 'Diese geplante Behandlung ist bereits abgeschlossen und kann nicht bearbeitet werden.', 'danger');
            redirect(url('cattle_show.php') . '?id=' . $cattleId);
        }
        if ($medication === '' || !in_array($medication, $meds, true)) {
            $errors[] = 'Bitte ein gueltiges Medikament aus dem Katalog waehlen.';
        }
        if ($plannedDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $plannedDate)) {
            $errors[] = 'Bitte ein gueltiges geplantes Datum angeben.';
        }
        if (!in_array($type, treatmentTypes(), true)) { $type = 'Sonstige'; }
        if (empty($errors)) {
            $upd = $db->prepare(
                'UPDATE planned_treatments
                 SET planned_date = :d, medication = :m, dosage = :dos, type = :t, notes = :n
                 WHERE id = :id AND farm_id = :f AND status = \'geplant\''
            );
            $upd->execute([
                ':d' => $plannedDate, ':m' => $medication,
                ':dos' => $dosage !== '' ? $dosage : null, ':t' => $type,
                ':n' => $notes !== '' ? $notes : null,
                ':id' => $planId, ':f' => $farmId,
            ]);
            flash('success', 'Geplante Behandlung aktualisiert.', 'success');
            redirect(url('cattle_show.php') . '?id=' . $cattleId);
        }
    } else {
        $err = null;
        $newId = createPlannedTreatment(
            $db, $farmId, $cattleId, $plannedDate, $medication, $type,
            $dosage, $notes, currentUserId(), $err
        );
        if ($newId !== null) {
            flash('success', 'Behandlung geplant. Sie erscheint am geplanten Tag in den Aufgaben und im Kalender.', 'success');
            redirect(url('cattle_show.php') . '?id=' . $cattleId);
        }
        $errors[] = $err ?? 'Speichern fehlgeschlagen.';
    }

    // Formular nach Fehler mit Eingaben neu befuellen
    $prefillMed = $medication; $prefillDos = $dosage; $prefillType = $type;
    $prefillDate = $plannedDate; $prefillNote = $notes;
}

$pageTitle = $editing ? 'Geplante Behandlung bearbeiten' : 'Behandlung planen';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-6">
    <h1 class="h3 mb-3"><?= $editing ? 'Geplante Behandlung bearbeiten' : 'Behandlung planen' ?> &ndash; <?= e(formatEarTag($cattle['ear_tag'])) ?></h1>
    <p class="text-muted small">
        Eine geplante Behandlung erscheint am geplanten Tag (und ueberfaellig) in den Aufgaben
        sowie im Kalender. Mit &bdquo;Erledigt&ldquo; wird sie als echte Behandlung uebernommen.
    </p>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <?php if (empty($meds)): ?>
        <div class="alert alert-warning">Es sind noch keine Medikamente im Katalog. Bitte zuerst Medikamente anlegen.</div>
    <?php endif; ?>
    <form method="post" class="card card-body">
        <?= csrfField() ?>
        <input type="hidden" name="cattle_id" value="<?= (int) $cattleId ?>">
        <?php if ($editing): ?><input type="hidden" name="plan_id" value="<?= (int) $planId ?>"><?php endif; ?>
        <div class="mb-3">
            <label class="form-label">Geplantes Datum *</label>
            <input type="date" name="planned_date" value="<?= e($prefillDate) ?>" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Medikament *</label>
            <select name="medication" class="form-select" required>
                <option value="">-- waehlen --</option>
                <?php foreach ($meds as $m): ?><option value="<?= e($m) ?>"<?= $m === $prefillMed ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Art</label>
            <select name="type" class="form-select">
                <?php foreach (treatmentTypes() as $t): ?><option value="<?= e($t) ?>"<?= $t === $prefillType ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Dosierung</label>
            <input type="text" name="dosage" value="<?= e($prefillDos) ?>" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label">Notiz</label>
            <textarea name="notes" class="form-control" rows="2"><?= e($prefillNote) ?></textarea>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success"><i class="bi bi-calendar-plus"></i> <?= $editing ? 'Speichern' : 'Planen' ?></button>
            <a href="<?= e(url('cattle_show.php')) ?>?id=<?= $cattleId ?>" class="btn btn-outline-secondary">Abbrechen</a>
        </div>
    </form>
</div></div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
