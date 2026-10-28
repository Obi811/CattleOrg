<?php
/**
 * admin/user_list.php
 * -----------------------------------------------------------------------------
 * System-Admin: alle Benutzer der Plattform auflisten, neue System-Benutzer
 * anlegen, Passwort zurücksetzen und Admin-Flag setzen.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

requireAdmin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

        if ($username === '' || strlen($password) < 8) {
            $errors[] = 'Benutzername erforderlich, Passwort mind. 8 Zeichen.';
        } else {
            $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
            $chk->execute([':u' => $username]);
            if ((int) $chk->fetchColumn() > 0) {
                $errors[] = 'Benutzername bereits vergeben.';
            } else {
                $ins = $db->prepare('INSERT INTO users (username, email, password_hash, is_admin) VALUES (:u, :e, :p, :a)');
                $ins->execute([
                    ':u' => $username, ':e' => $email !== '' ? $email : null,
                    ':p' => password_hash($password, PASSWORD_DEFAULT), ':a' => $isAdmin,
                ]);
                flash('success', 'Benutzer angelegt.', 'success');
                redirect(url('admin/user_list.php'));
            }
        }
    } elseif ($action === 'reset_pw') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            $errors[] = 'Neues Passwort muss mind. 8 Zeichen haben.';
        } else {
            $upd = $db->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
            $upd->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
            flash('success', 'Passwort zurückgesetzt.', 'success');
            redirect(url('admin/user_list.php'));
        }
    } elseif ($action === 'toggle_admin') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id === currentUserId()) {
            flash('error', 'Eigene Admin-Rechte können nicht entzogen werden.', 'danger');
        } else {
            $upd = $db->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = :id');
            $upd->execute([':id' => $id]);
            flash('success', 'Admin-Status geändert.', 'success');
        }
        redirect(url('admin/user_list.php'));
    }
}

$users = $db->query(
    'SELECT u.*, (SELECT COUNT(*) FROM farm_users fu WHERE fu.user_id = u.id) AS farm_count
     FROM users u ORDER BY u.username'
)->fetchAll();

$pageTitle = 'Benutzer (System)';
require_once __DIR__ . '/../../templates/header.php';
?>
<h1 class="h3 mb-3">Alle Benutzer <span class="badge bg-danger">System-Admin</span></h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card card-body">
            <h2 class="h6">Neuen Benutzer anlegen</h2>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-2"><label class="form-label mb-0">Benutzername *</label><input name="username" class="form-control form-control-sm" required></div>
                <div class="mb-2"><label class="form-label mb-0">E-Mail</label><input type="email" name="email" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label mb-0">Passwort *</label><input type="password" name="password" class="form-control form-control-sm" required></div>
                <div class="form-check mb-2"><input type="checkbox" name="is_admin" value="1" class="form-check-input" id="isAdm"><label class="form-check-label" for="isAdm">System-Admin</label></div>
                <button class="btn btn-success btn-sm w-100"><i class="bi bi-person-plus"></i> Anlegen</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>Benutzer</th><th>Betriebe</th><th>Admin</th><th>Letzter Login</th><th>Passwort</th><th>Aktion</th></tr></thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= e($u['username']) ?><?php if ($u['email']): ?><br><small class="text-muted"><?= e($u['email']) ?></small><?php endif; ?></td>
                            <td><?= (int) $u['farm_count'] ?></td>
                            <td>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_admin">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn btn-sm btn-<?= $u['is_admin'] ? 'danger' : 'outline-secondary' ?>">
                                        <?= $u['is_admin'] ? 'Admin' : 'Nein' ?>
                                    </button>
                                </form>
                            </td>
                            <td><small><?= e($u['last_login'] ?: '-') ?></small></td>
                            <td>
                                <form method="post" class="d-flex gap-1" onsubmit="return confirm('Passwort zurücksetzen?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reset_pw">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="password" name="password" class="form-control form-control-sm" placeholder="Neu" style="width:110px;">
                                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-key"></i></button>
                                </form>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/user_edit.php?id=' . (int) $u['id'])) ?>"><i class="bi bi-pencil"></i> Bearbeiten</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
