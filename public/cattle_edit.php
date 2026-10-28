<?php
/**
 * public/cattle_edit.php
 * -----------------------------------------------------------------------------
 * Tier bearbeiten. Zugriff nur auf Tiere des eigenen Betriebs (IDOR-Schutz via
 * WHERE id = :id AND farm_id = :farm_id).
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.write');
requireWriteAccess();
$farmId = getCurrentFarmId();

$id = (int) ($_GET['id'] ?? 0);

// Datensatz streng betriebsbezogen laden
$stmt = $db->prepare('SELECT * FROM cattle WHERE id = :id AND farm_id = :f');
$stmt->execute([':id' => $id, ':f' => $farmId]);
$cattle = $stmt->fetch();
if (!$cattle) {
    http_response_code(404);
    flash('error', 'Tier nicht gefunden.', 'danger');
    redirect(url('cattle_list.php'));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $earTag    = normalizeEarTag($_POST['ear_tag'] ?? '');
    $birthDate = trim($_POST['birth_date'] ?? '');
    $rasse     = trim($_POST['rasse'] ?? '');
    $geschlecht = trim($_POST['geschlecht'] ?? '');
    $mutter    = normalizeEarTag($_POST['mutter'] ?? '');
    $mehrling  = ($_POST['mehrling'] ?? 'Nein') === 'Ja' ? 'Ja' : 'Nein';
    $status    = ($_POST['status'] ?? 'Aktiv') === 'Inaktiv' ? 'Inaktiv' : 'Aktiv';
    $zugang    = trim($_POST['zugang'] ?? '');
    $abgang    = trim($_POST['abgang'] ?? '');
    $verendung = trim($_POST['verendung'] ?? '');

    if ($earTag === '') {
        $errors[] = 'Bitte eine Ohrmarkennummer eingeben.';
    }
    if ($rasse !== '' && !in_array($rasse, cattleRaces(), true)) {
        $errors[] = 'Ungültige Rasse.';
    }

    // Eindeutigkeit (außer dem aktuellen Datensatz)
    if ($earTag !== '') {
        $chk = $db->prepare('SELECT COUNT(*) FROM cattle WHERE farm_id = :f AND ear_tag = :e AND id <> :id');
        $chk->execute([':f' => $farmId, ':e' => $earTag, ':id' => $id]);
        if ((int) $chk->fetchColumn() > 0) {
            $errors[] = 'Ein anderes Tier mit dieser Ohrmarke existiert bereits.';
        }
    }

    if (empty($errors)) {
        $upd = $db->prepare(
            'UPDATE cattle SET ear_tag=:ear, birth_date=:birth, rasse=:rasse, geschlecht=:geschlecht,
                mutter=:mutter, mehrling=:mehrling, status=:status, zugang=:zugang, abgang=:abgang, verendung=:verendung
             WHERE id=:id AND farm_id=:f'
        );
        $upd->execute([
            ':ear' => $earTag,
            ':birth' => $birthDate !== '' ? $birthDate : null,
            ':rasse' => $rasse !== '' ? $rasse : null,
            ':geschlecht' => $geschlecht !== '' ? $geschlecht : null,
            ':mutter' => $mutter !== '' ? $mutter : null,
            ':mehrling' => $mehrling,
            ':status' => $status,
            ':zugang' => $zugang !== '' ? $zugang : null,
            ':abgang' => $abgang !== '' ? $abgang : null,
            ':verendung' => $verendung !== '' ? $verendung : null,
            ':id' => $id, ':f' => $farmId,
        ]);
        flash('success', 'Änderungen gespeichert.', 'success');
        redirect(url('cattle_show.php') . '?id=' . $id);
    }
    // Bei Fehler: eingegebene Werte beibehalten
    $cattle = array_merge($cattle, [
        'ear_tag' => $earTag, 'birth_date' => $birthDate, 'rasse' => $rasse,
        'geschlecht' => $geschlecht, 'mutter' => $mutter, 'mehrling' => $mehrling,
        'status' => $status, 'zugang' => $zugang, 'abgang' => $abgang, 'verendung' => $verendung,
    ]);
}

$pageTitle = 'Tier bearbeiten';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="h3 mb-3">Tier bearbeiten &ndash; <?= e(formatEarTag($cattle['ear_tag'])) ?></h1>
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" class="card card-body">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Ohrmarke *</label>
                    <input type="text" name="ear_tag" value="<?= e($cattle['ear_tag']) ?>" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Geburtsdatum</label>
                    <input type="date" name="birth_date" value="<?= e($cattle['birth_date']) ?>" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rasse</label>
                    <select name="rasse" class="form-select">
                        <option value="">-- wählen --</option>
                        <?php foreach (cattleRaces() as $r): ?>
                            <option value="<?= e($r) ?>" <?= $cattle['rasse'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Geschlecht</label>
                    <select name="geschlecht" class="form-select">
                        <option value="">--</option>
                        <option value="W" <?= $cattle['geschlecht'] === 'W' ? 'selected' : '' ?>>Weiblich</option>
                        <option value="M" <?= $cattle['geschlecht'] === 'M' ? 'selected' : '' ?>>Männlich</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Mehrling</label>
                    <select name="mehrling" class="form-select">
                        <option value="Nein" <?= $cattle['mehrling'] === 'Nein' ? 'selected' : '' ?>>Nein</option>
                        <option value="Ja"   <?= $cattle['mehrling'] === 'Ja' ? 'selected' : '' ?>>Ja</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mutter (Ohrmarke)</label>
                    <input type="text" name="mutter" value="<?= e($cattle['mutter']) ?>" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="Aktiv"   <?= $cattle['status'] === 'Aktiv' ? 'selected' : '' ?>>Aktiv</option>
                        <option value="Inaktiv" <?= $cattle['status'] === 'Inaktiv' ? 'selected' : '' ?>>Inaktiv</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Zugang</label>
                    <input type="date" name="zugang" value="<?= e($cattle['zugang']) ?>" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Abgang</label>
                    <input type="date" name="abgang" value="<?= e($cattle['abgang']) ?>" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Verendung</label>
                    <input type="date" name="verendung" value="<?= e($cattle['verendung']) ?>" class="form-control">
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-success"><i class="bi bi-check-lg"></i> Speichern</button>
                <a href="<?= e(url('cattle_show.php')) ?>?id=<?= $id ?>" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
