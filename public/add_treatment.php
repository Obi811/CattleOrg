<?php
/**
 * public/add_treatment.php
 * -----------------------------------------------------------------------------
 * Behandlung zu einem Tier erfassen. Medikament wird aus dem GLOBALEN
 * Medikamentenkatalog gewaehlt. Tierzugriff strikt betriebsbezogen.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('treatments.write');
requireWriteAccess();
$farmId = getCurrentFarmId();

$cattleId = (int) ($_GET['cattle_id'] ?? $_POST['cattle_id'] ?? 0);

// Tier betriebsbezogen pruefen
$stmt = $db->prepare('SELECT id, ear_tag FROM cattle WHERE id = :id AND farm_id = :f');
$stmt->execute([':id' => $cattleId, ':f' => $farmId]);
$cattle = $stmt->fetch();
if (!$cattle) {
    http_response_code(404);
    flash('error', 'Tier nicht gefunden.', 'danger');
    redirect(url('cattle_list.php'));
}

// Globaler Medikamentenkatalog
$meds = $db->query('SELECT name FROM medications ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $date = trim($_POST['treatment_date'] ?? '');
    $medication = trim($_POST['medication'] ?? '');
    $type = trim($_POST['type'] ?? 'Sonstige');
    $dosage = trim($_POST['dosage'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($medication === '' || !in_array($medication, $meds, true)) {
        $errors[] = 'Bitte ein gueltiges Medikament aus dem Katalog waehlen.';
    }
    if (!in_array($type, treatmentTypes(), true)) {
        $errors[] = 'Ungueltige Behandlungsart.';
    }

    if (empty($errors)) {
        $ins = $db->prepare(
            'INSERT INTO treatments (farm_id, cattle_id, treatment_date, medication, dosage, type, notes)
             VALUES (:f, :c, :d, :m, :dos, :t, :n)'
        );
        $ins->execute([
            ':f' => $farmId, ':c' => $cattleId,
            ':d' => $date !== '' ? $date : null,
            ':m' => $medication, ':dos' => $dosage !== '' ? $dosage : null,
            ':t' => $type, ':n' => $notes !== '' ? $notes : null,
        ]);
        flash('success', 'Behandlung gespeichert.', 'success');
        redirect(url('cattle_show.php') . '?id=' . $cattleId);
    }
}

$pageTitle = 'Behandlung erfassen';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-6">
    <h1 class="h3 mb-3">Behandlung &ndash; <?= e(formatEarTag($cattle['ear_tag'])) ?></h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <?php if (empty($meds)): ?>
        <div class="alert alert-warning">Es sind noch keine Medikamente im Katalog. Bitte zuerst Medikamente anlegen.</div>
    <?php endif; ?>
    <form method="post" class="card card-body">
        <?= csrfField() ?>
        <input type="hidden" name="cattle_id" value="<?= (int) $cattleId ?>">
        <div class="mb-3">
            <label class="form-label">Datum</label>
            <input type="date" name="treatment_date" value="<?= e(date('Y-m-d')) ?>" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label">Medikament *</label>
            <select name="medication" class="form-select" required>
                <option value="">-- waehlen --</option>
                <?php foreach ($meds as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Art</label>
            <select name="type" class="form-select">
                <?php foreach (treatmentTypes() as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Dosierung</label>
            <input type="text" name="dosage" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label">Notiz</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success"><i class="bi bi-check-lg"></i> Speichern</button>
            <a href="<?= e(url('cattle_show.php')) ?>?id=<?= $cattleId ?>" class="btn btn-outline-secondary">Abbrechen</a>
        </div>
    </form>
</div></div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
