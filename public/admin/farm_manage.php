<?php
/**
 * admin/farm_manage.php
 * -----------------------------------------------------------------------------
 * System-Admin: Betriebe (Mandanten) anlegen, aktivieren/deaktivieren.
 * Erfordert System-Admin-Rechte (requireAdmin()).
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

requireAdmin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $betriebsnummer = trim($_POST['betriebsnummer'] ?? '');
        $isDemo = isset($_POST['is_demo']) ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Bitte einen Betriebsnamen eingeben.';
        } else {
            $ins = $db->prepare('INSERT INTO farms (name, betriebsnummer, is_demo) VALUES (:n, :b, :d)');
            $ins->execute([':n' => $name, ':b' => $betriebsnummer !== '' ? $betriebsnummer : null, ':d' => $isDemo]);
            flash('success', 'Betrieb wurde angelegt.', 'success');
            redirect(url('admin/farm_manage.php'));
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['farm_id'] ?? 0);
        $upd = $db->prepare('UPDATE farms SET is_active = 1 - is_active WHERE id = :id');
        $upd->execute([':id' => $id]);
        flash('success', 'Status geändert.', 'success');
        redirect(url('admin/farm_manage.php'));
    }
}

$farms = $db->query(
    'SELECT f.*, (SELECT COUNT(*) FROM cattle c WHERE c.farm_id = f.id) AS cattle_count,
            (SELECT COUNT(*) FROM farm_users fu WHERE fu.farm_id = f.id) AS user_count
     FROM farms f ORDER BY f.name'
)->fetchAll();

$pageTitle = 'Betriebe verwalten';
require_once __DIR__ . '/../../templates/header.php';
?>
<h1 class="h3 mb-3">Betriebe verwalten <span class="badge bg-danger">System-Admin</span></h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card card-body">
            <h2 class="h6">Neuen Betrieb anlegen</h2>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-2"><label class="form-label mb-0">Name *</label><input name="name" class="form-control form-control-sm" required></div>
                <div class="mb-2"><label class="form-label mb-0">Betriebsnummer</label><input name="betriebsnummer" class="form-control form-control-sm"></div>
                <div class="form-check mb-2"><input type="checkbox" name="is_demo" value="1" class="form-check-input" id="isDemo"><label class="form-check-label" for="isDemo">Demo-Betrieb (nur Lesen)</label></div>
                <button class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg"></i> Anlegen</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>Name</th><th>Betriebsnr.</th><th>Tiere</th><th>Benutzer</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($farms as $f): ?>
                        <tr>
                            <td><?= e($f['name']) ?> <?php if ($f['is_demo']): ?><span class="badge bg-warning text-dark">Demo</span><?php endif; ?></td>
                            <td><?= e($f['betriebsnummer']) ?></td>
                            <td><?= (int) $f['cattle_count'] ?></td>
                            <td><?= (int) $f['user_count'] ?></td>
                            <td><span class="badge bg-<?= $f['is_active'] ? 'success' : 'secondary' ?>"><?= $f['is_active'] ? 'aktiv' : 'inaktiv' ?></span></td>
                            <td class="text-end">
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="farm_id" value="<?= (int) $f['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary"><?= $f['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
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
