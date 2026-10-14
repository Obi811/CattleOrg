<?php
/**
 * public/add_medications.php
 * -----------------------------------------------------------------------------
 * Verwaltung des GLOBALEN Medikamentenkatalogs (kein farm_id).
 * Erfordert die Berechtigung medications.manage (owner/manager).
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('medications.manage');
requireWriteAccess();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name = trim($_POST['name'] ?? '');
    $active = trim($_POST['active_substance'] ?? '');
    $dosage = trim($_POST['dosage'] ?? '');
    $wzMilk = trim($_POST['wz_milk'] ?? '');
    $wzFleisch = trim($_POST['wz_fleisch'] ?? '');

    if ($name === '') {
        $errors[] = 'Bitte einen Namen eingeben.';
    } else {
        $chk = $db->prepare('SELECT COUNT(*) FROM medications WHERE name = :n');
        $chk->execute([':n' => $name]);
        if ((int) $chk->fetchColumn() > 0) {
            $errors[] = 'Ein Medikament mit diesem Namen existiert bereits.';
        }
    }

    if (empty($errors)) {
        $ins = $db->prepare(
            'INSERT INTO medications (name, active_substance, dosage, wz_milk, wz_fleisch)
             VALUES (:n, :a, :d, :m, :f)'
        );
        $ins->execute([
            ':n' => $name,
            ':a' => $active !== '' ? $active : null,
            ':d' => $dosage !== '' ? $dosage : null,
            ':m' => $wzMilk !== '' ? $wzMilk : null,
            ':f' => $wzFleisch !== '' ? $wzFleisch : null,
        ]);
        flash('success', 'Medikament wurde hinzugefuegt.', 'success');
        redirect(url('add_medications.php'));
    }
}

$meds = $db->query('SELECT * FROM medications ORDER BY name')->fetchAll();

$pageTitle = 'Medikamente';
require_once __DIR__ . '/../templates/header.php';
?>
<h1 class="h3 mb-3">Medikamentenkatalog <span class="badge bg-secondary">global</span></h1>
<div class="alert alert-info small">
    <i class="bi bi-info-circle"></i> Der Medikamentenkatalog ist betriebsuebergreifend (global) und steht allen Betrieben zur Verfuegung.
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card card-body">
            <h2 class="h6">Neues Medikament</h2>
            <?php foreach ($errors as $err): ?><div class="alert alert-danger py-1 small"><?= e($err) ?></div><?php endforeach; ?>
            <form method="post">
                <?= csrfField() ?>
                <div class="mb-2"><label class="form-label mb-0">Name *</label><input name="name" class="form-control form-control-sm" required></div>
                <div class="mb-2"><label class="form-label mb-0">Wirkstoff</label><input name="active_substance" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label mb-0">Dosierung</label><input name="dosage" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label mb-0">Wartezeit Milch (Tage)</label><input name="wz_milk" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label mb-0">Wartezeit Fleisch (Tage)</label><input name="wz_fleisch" class="form-control form-control-sm"></div>
                <button class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg"></i> Hinzufuegen</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped table-sm mb-0">
                <thead><tr><th>Name</th><th>Wirkstoff</th><th>Dosierung</th><th>WZ Milch</th><th>WZ Fleisch</th></tr></thead>
                <tbody>
                    <?php if (empty($meds)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Noch keine Medikamente.</td></tr>
                    <?php else: foreach ($meds as $m): ?>
                        <tr>
                            <td><?= e($m['name']) ?></td><td><?= e($m['active_substance']) ?></td>
                            <td><?= e($m['dosage']) ?></td><td><?= e($m['wz_milk']) ?></td><td><?= e($m['wz_fleisch']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
