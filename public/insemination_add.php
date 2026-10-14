<?php
/**
 * public/insemination_add.php
 * -----------------------------------------------------------------------------
 * Besamung / Belegung erfassen. Betriebsbezogen, CSRF-geschuetzt.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('inseminations.write');
requireWriteAccess();
$farmId = getCurrentFarmId();

$cattleId = (int) ($_GET['cattle_id'] ?? $_POST['cattle_id'] ?? 0);

$stmt = $db->prepare('SELECT id, ear_tag FROM cattle WHERE id = :id AND farm_id = :f');
$stmt->execute([':id' => $cattleId, ':f' => $farmId]);
$cattle = $stmt->fetch();
if (!$cattle) {
    http_response_code(404);
    flash('error', 'Tier nicht gefunden.', 'danger');
    redirect(url('cattle_list.php'));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $date = trim($_POST['date'] ?? '');
    $bull = trim($_POST['bull_name'] ?? '');
    $type = trim($_POST['type'] ?? '');

    if ($date === '') {
        $errors[] = 'Bitte ein Datum eingeben.';
    }
    if (!in_array($type, ['besamt', 'gestiert'], true)) {
        $errors[] = 'Bitte eine gueltige Art waehlen.';
    }

    if (empty($errors)) {
        $ins = $db->prepare(
            'INSERT INTO inseminations (farm_id, cattle_id, date, bull_name, type)
             VALUES (:f, :c, :d, :b, :t)'
        );
        $ins->execute([
            ':f' => $farmId, ':c' => $cattleId, ':d' => $date,
            ':b' => $bull !== '' ? $bull : null, ':t' => $type,
        ]);
        flash('success', 'Besamung gespeichert.', 'success');
        redirect(url('cattle_show.php') . '?id=' . $cattleId);
    }
}

$pageTitle = 'Besamung erfassen';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-6">
    <h1 class="h3 mb-3">Besamung &ndash; <?= e(formatEarTag($cattle['ear_tag'])) ?></h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" class="card card-body">
        <?= csrfField() ?>
        <input type="hidden" name="cattle_id" value="<?= (int) $cattleId ?>">
        <div class="mb-3">
            <label class="form-label">Datum *</label>
            <input type="date" name="date" value="<?= e(date('Y-m-d')) ?>" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Bulle</label>
            <input type="text" name="bull_name" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label">Art *</label>
            <select name="type" class="form-select" required>
                <option value="besamt">besamt (kuenstlich)</option>
                <option value="gestiert">gestiert (Natursprung)</option>
            </select>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success"><i class="bi bi-check-lg"></i> Speichern</button>
            <a href="<?= e(url('cattle_show.php')) ?>?id=<?= $cattleId ?>" class="btn btn-outline-secondary">Abbrechen</a>
        </div>
    </form>
</div></div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
