<?php
/**
 * public/change_stall.php
 * -----------------------------------------------------------------------------
 * Stallwechsel erfassen. Die Stallliste kommt aus der DB (pro Betrieb),
 * nicht mehr aus einer hardcodierten Liste. Schliesst den offenen
 * Stalleintrag (end_date) und legt einen neuen an.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.write');
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

$stalls = getStallList($db, $farmId);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $stallName = trim($_POST['stall_name'] ?? '');
    $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));

    if ($stallName === '' || !in_array($stallName, $stalls, true)) {
        $errors[] = 'Bitte einen gueltigen Stall waehlen.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // offenen Eintrag schliessen
            $close = $db->prepare(
                'UPDATE stall_history SET end_date = :d
                 WHERE cattle_id = :c AND farm_id = :f AND end_date IS NULL'
            );
            $close->execute([':d' => $startDate, ':c' => $cattleId, ':f' => $farmId]);

            // neuen Eintrag anlegen
            $ins = $db->prepare(
                'INSERT INTO stall_history (farm_id, cattle_id, stall_name, start_date)
                 VALUES (:f, :c, :s, :d)'
            );
            $ins->execute([':f' => $farmId, ':c' => $cattleId, ':s' => $stallName, ':d' => $startDate]);

            $db->commit();
            flash('success', 'Stallwechsel gespeichert.', 'success');
            redirect(url('cattle_show.php') . '?id=' . $cattleId);
        } catch (Throwable $ex) {
            $db->rollBack();
            $errors[] = 'Speichern fehlgeschlagen.';
        }
    }
}

$pageTitle = 'Stallwechsel';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-6">
    <h1 class="h3 mb-3">Stallwechsel &ndash; <?= e(formatEarTag($cattle['ear_tag'])) ?></h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <?php if (empty($stalls)): ?>
        <div class="alert alert-warning">Fuer diesen Betrieb sind noch keine Staelle hinterlegt.</div>
    <?php endif; ?>
    <form method="post" class="card card-body">
        <?= csrfField() ?>
        <input type="hidden" name="cattle_id" value="<?= (int) $cattleId ?>">
        <div class="mb-3">
            <label class="form-label">Neuer Stall *</label>
            <select name="stall_name" class="form-select" required>
                <option value="">-- waehlen --</option>
                <?php foreach ($stalls as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Datum</label>
            <input type="date" name="start_date" value="<?= e(date('Y-m-d')) ?>" class="form-control">
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success"><i class="bi bi-check-lg"></i> Speichern</button>
            <a href="<?= e(url('cattle_show.php')) ?>?id=<?= $cattleId ?>" class="btn btn-outline-secondary">Abbrechen</a>
        </div>
    </form>
</div></div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
