<?php
/**
 * admin/medications.php
 * -----------------------------------------------------------------------------
 * System-Admin: vollständige Verwaltung des GLOBALEN Medikamentenkatalogs
 * (anlegen, bearbeiten, löschen). Löschen ist nur möglich, wenn keine
 * Behandlung das Medikament referenziert (FK-Schutz).
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

requireAdmin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $active = trim($_POST['active_substance'] ?? '');
        $dosage = trim($_POST['dosage'] ?? '');
        $wzMilk = trim($_POST['wz_milk'] ?? '');
        $wzFleisch = trim($_POST['wz_fleisch'] ?? '');

        if ($name === '') {
            $errors[] = 'Bitte einen Namen eingeben.';
        } elseif ($id > 0) {
            $upd = $db->prepare('UPDATE medications SET name=:n, active_substance=:a, dosage=:d, wz_milk=:m, wz_fleisch=:f WHERE id=:id');
            $upd->execute([':n' => $name, ':a' => $active ?: null, ':d' => $dosage ?: null, ':m' => $wzMilk ?: null, ':f' => $wzFleisch ?: null, ':id' => $id]);
            flash('success', 'Medikament aktualisiert.', 'success');
            redirect(url('admin/medications.php'));
        } else {
            $chk = $db->prepare('SELECT COUNT(*) FROM medications WHERE name = :n');
            $chk->execute([':n' => $name]);
            if ((int) $chk->fetchColumn() > 0) {
                $errors[] = 'Name existiert bereits.';
            } else {
                $ins = $db->prepare('INSERT INTO medications (name, active_substance, dosage, wz_milk, wz_fleisch) VALUES (:n, :a, :d, :m, :f)');
                $ins->execute([':n' => $name, ':a' => $active ?: null, ':d' => $dosage ?: null, ':m' => $wzMilk ?: null, ':f' => $wzFleisch ?: null]);
                flash('success', 'Medikament angelegt.', 'success');
                redirect(url('admin/medications.php'));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        // Referenzierung prüfen
        $name = $db->prepare('SELECT name FROM medications WHERE id = :id');
        $name->execute([':id' => $id]);
        $medName = $name->fetchColumn();
        if ($medName !== false) {
            $ref = $db->prepare('SELECT COUNT(*) FROM treatments WHERE medication = :n');
            $ref->execute([':n' => $medName]);
            if ((int) $ref->fetchColumn() > 0) {
                flash('error', 'Löschen nicht möglich: Medikament wird in Behandlungen verwendet.', 'danger');
            } else {
                $del = $db->prepare('DELETE FROM medications WHERE id = :id');
                $del->execute([':id' => $id]);
                flash('success', 'Medikament gelöscht.', 'success');
            }
        }
        redirect(url('admin/medications.php'));
    }
}

// Bearbeiten?
$edit = null;
if (isset($_GET['edit'])) {
    $e = $db->prepare('SELECT * FROM medications WHERE id = :id');
    $e->execute([':id' => (int) $_GET['edit']]);
    $edit = $e->fetch() ?: null;
}

$meds = $db->query('SELECT * FROM medications ORDER BY name')->fetchAll();

$pageTitle = 'Medikamente (global)';
require_once __DIR__ . '/../../templates/header.php';
?>
<h1 class="h3 mb-3">Medikamentenkatalog (global) <span class="badge bg-danger">System-Admin</span></h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card card-body">
            <h2 class="h6"><?= $edit ? 'Medikament bearbeiten' : 'Neues Medikament' ?></h2>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
                <div class="mb-2"><label class="form-label mb-0">Name *</label><input name="name" class="form-control form-control-sm" value="<?= e($edit['name'] ?? '') ?>" required></div>
                <div class="mb-2"><label class="form-label mb-0">Wirkstoff</label><input name="active_substance" class="form-control form-control-sm" value="<?= e($edit['active_substance'] ?? '') ?>"></div>
                <div class="mb-2"><label class="form-label mb-0">Dosierung</label><input name="dosage" class="form-control form-control-sm" value="<?= e($edit['dosage'] ?? '') ?>"></div>
                <div class="mb-2"><label class="form-label mb-0">Wartezeit Milch</label><input name="wz_milk" class="form-control form-control-sm" value="<?= e($edit['wz_milk'] ?? '') ?>"></div>
                <div class="mb-2"><label class="form-label mb-0">Wartezeit Fleisch</label><input name="wz_fleisch" class="form-control form-control-sm" value="<?= e($edit['wz_fleisch'] ?? '') ?>"></div>
                <button class="btn btn-success btn-sm w-100"><i class="bi bi-check-lg"></i> Speichern</button>
                <?php if ($edit): ?><a href="<?= e(url('admin/medications.php')) ?>" class="btn btn-link btn-sm w-100">Abbrechen</a><?php endif; ?>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped table-sm align-middle mb-0">
                <thead><tr><th>Name</th><th>Wirkstoff</th><th>WZ Milch</th><th>WZ Fleisch</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($meds as $m): ?>
                        <tr>
                            <td><?= e($m['name']) ?></td><td><?= e($m['active_substance']) ?></td>
                            <td><?= e($m['wz_milk']) ?></td><td><?= e($m['wz_fleisch']) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int) $m['id'] ?>"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Wirklich löschen?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
