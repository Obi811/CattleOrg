<?php
/**
 * public/stillbirth_add.php
 * -----------------------------------------------------------------------------
 * Totgeburt einer Kuh erfassen. Totgeburten bekommen KEINE Ohrmarke und werden
 * NICHT in der Tabelle `cattle` gespeichert, sondern in `stillbirths` der
 * Mutter-Kuh zugeordnet. Betriebsbezogen, CSRF-geschützt, Demo-Betrieb read-only.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.write');
requireWriteAccess();
$farmId = getCurrentFarmId();

$cattleId = (int) ($_GET['cattle_id'] ?? $_POST['cattle_id'] ?? 0);

// Mutter-Kuh laden und prüfen, dass sie zum aktuellen Betrieb gehört
$stmt = $db->prepare('SELECT id, ear_tag, geschlecht FROM cattle WHERE id = :id AND farm_id = :f');
$stmt->execute([':id' => $cattleId, ':f' => $farmId]);
$cattle = $stmt->fetch();
if (!$cattle) {
    http_response_code(404);
    flash('error', 'Tier nicht gefunden.', 'danger');
    redirect(url('cattle_list.php'));
}
if (($cattle['geschlecht'] ?? '') !== 'W') {
    flash('error', 'Totgeburten können nur bei weiblichen Tieren erfasst werden.', 'danger');
    redirect(url('cattle_show.php') . '?id=' . $cattleId);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $date    = trim($_POST['birth_date'] ?? '');
    $sex     = trim($_POST['sex'] ?? 'unbekannt');
    $comment = trim($_POST['comment'] ?? '');

    if ($date === '') {
        $errors[] = 'Bitte ein Datum eingeben.';
    }
    if (!in_array($sex, ['maennlich', 'weiblich', 'unbekannt'], true)) {
        $sex = 'unbekannt';
    }

    if (empty($errors)) {
        $ins = $db->prepare(
            'INSERT INTO stillbirths (farm_id, mother_cattle_id, birth_date, sex, comment, created_by)
             VALUES (:f, :c, :d, :s, :k, :u)'
        );
        $ins->execute([
            ':f' => $farmId,
            ':c' => $cattleId,
            ':d' => $date,
            ':s' => $sex,
            ':k' => $comment !== '' ? $comment : null,
            ':u' => currentUserId(),
        ]);
        flash('success', 'Totgeburt gespeichert.', 'success');
        redirect(url('cattle_show.php') . '?id=' . $cattleId);
    }
}

$pageTitle = 'Totgeburt erfassen';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-6">
    <h1 class="h3 mb-3">Totgeburt erfassen &ndash; <?= e(formatEarTag($cattle['ear_tag'])) ?></h1>
    <p class="text-muted small">Eine Totgeburt erhält keine Ohrmarke und wird bei den Kalbungen dieser Kuh aufgeführt.</p>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" class="card card-body">
        <?= csrfField() ?>
        <input type="hidden" name="cattle_id" value="<?= (int) $cattleId ?>">
        <div class="mb-3">
            <label class="form-label">Datum *</label>
            <input type="date" name="birth_date" value="<?= e(date('Y-m-d')) ?>" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Geschlecht</label>
            <select name="sex" class="form-select">
                <option value="unbekannt">unbekannt</option>
                <option value="weiblich">weiblich</option>
                <option value="maennlich">männlich</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Bemerkung</label>
            <textarea name="comment" class="form-control" rows="2" maxlength="2000" placeholder="optional"></textarea>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success"><i class="bi bi-check-lg"></i> Speichern</button>
            <a href="<?= e(url('cattle_show.php')) ?>?id=<?= $cattleId ?>" class="btn btn-outline-secondary">Abbrechen</a>
        </div>
    </form>
</div></div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
