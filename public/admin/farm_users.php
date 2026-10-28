<?php
/**
 * admin/farm_users.php
 * -----------------------------------------------------------------------------
 * Benutzerverwaltung INNERHALB eines Betriebs (Subaccounts). Erfordert die
 * Berechtigung users.manage (Rolle owner, bzw. System-Admin).
 *
 * Funktionen:
 *   - Mitarbeiter des Betriebs auflisten (mit Rolle)
 *   - neuen Benutzer anlegen ODER bestehenden per Benutzername zuordnen
 *   - Rolle ändern, Zuordnung entfernen
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

requireLogin();
requirePermission('users.manage');
requireWriteAccess();
$farmId = getCurrentFarmId();

$roles = ['owner', 'manager', 'worker', 'viewer'];
$errors = [];

/** Anzahl der Owner eines Betriebs (Schutz des letzten Owners). */
function countFarmOwners(PDO $db, int $farmId): int
{
    $s = $db->prepare("SELECT COUNT(*) FROM farm_users WHERE farm_id = :f AND role = 'owner'");
    $s->execute([':f' => $farmId]);
    return (int) $s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $role = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'worker';
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '') {
            $errors[] = 'Bitte einen Benutzernamen oder eine E-Mail-Adresse eingeben.';
        } else {
            // Bestehenden User per Benutzername ODER E-Mail suchen
            $u = $db->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
            $u->execute([':u' => $username, ':e' => $username]);
            $user = $u->fetch();

            if (!$user) {
                // Neuen User anlegen (Passwort erforderlich)
                if (strlen($password) < 8) {
                    $errors[] = 'Für einen neuen Benutzer ist ein Passwort mit mind. 8 Zeichen nötig.';
                } else {
                    $ins = $db->prepare('INSERT INTO users (username, password_hash, is_admin) VALUES (:u, :p, 0)');
                    $ins->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT)]);
                    $userId = (int) $db->lastInsertId();
                }
            } else {
                $userId = (int) $user['id'];
            }

            if (empty($errors)) {
                // Zuordnung anlegen / aktualisieren
                $link = $db->prepare(
                    'INSERT INTO farm_users (farm_id, user_id, role) VALUES (:f, :u, :r)
                     ON DUPLICATE KEY UPDATE role = VALUES(role)'
                );
                $link->execute([':f' => $farmId, ':u' => $userId, ':r' => $role]);
                flash('success', 'Benutzer wurde dem Betrieb zugeordnet.', 'success');
                redirect(url('admin/farm_users.php'));
            }
        }
    } elseif ($action === 'role') {
        $fuId = (int) ($_POST['farm_user_id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'worker';
        // bestehende Zuordnung dieses Betriebs laden
        $chk = $db->prepare('SELECT role FROM farm_users WHERE id = :id AND farm_id = :f');
        $chk->execute([':id' => $fuId, ':f' => $farmId]);
        $cur = $chk->fetch();
        if ($cur && $cur['role'] === 'owner' && $role !== 'owner' && countFarmOwners($db, $farmId) <= 1) {
            flash('error', 'Der letzte Owner des Betriebs kann nicht herabgestuft werden.', 'danger');
        } else {
            $upd = $db->prepare('UPDATE farm_users SET role = :r WHERE id = :id AND farm_id = :f');
            $upd->execute([':r' => $role, ':id' => $fuId, ':f' => $farmId]);
            flash('success', 'Rolle aktualisiert.', 'success');
        }
        redirect(url('admin/farm_users.php'));
    } elseif ($action === 'remove') {
        $fuId = (int) ($_POST['farm_user_id'] ?? 0);
        $chk = $db->prepare('SELECT user_id, role FROM farm_users WHERE id = :id AND farm_id = :f');
        $chk->execute([':id' => $fuId, ':f' => $farmId]);
        $row = $chk->fetch();
        if ($row && (int) $row['user_id'] === currentUserId()) {
            // Sich selbst nicht entfernen
            flash('error', 'Sie können Ihre eigene Zuordnung nicht entfernen.', 'danger');
        } elseif ($row && $row['role'] === 'owner' && countFarmOwners($db, $farmId) <= 1) {
            // Letzten Owner nicht entfernen
            flash('error', 'Der letzte Owner des Betriebs kann nicht entfernt werden.', 'danger');
        } else {
            $del = $db->prepare('DELETE FROM farm_users WHERE id = :id AND farm_id = :f');
            $del->execute([':id' => $fuId, ':f' => $farmId]);
            flash('success', 'Zuordnung entfernt.', 'success');
        }
        redirect(url('admin/farm_users.php'));
    }
}

// Mitglieder laden
$stmt = $db->prepare(
    'SELECT fu.id AS farm_user_id, fu.role, u.id AS user_id, u.username, u.email, u.last_login
     FROM farm_users fu JOIN users u ON u.id = fu.user_id
     WHERE fu.farm_id = :f ORDER BY u.username'
);
$stmt->execute([':f' => $farmId]);
$members = $stmt->fetchAll();

$pageTitle = 'Benutzer';
require_once __DIR__ . '/../../templates/header.php';
?>
<h1 class="h3 mb-3">Benutzer im Betrieb &ndash; <?= e($_SESSION['current_farm_name']) ?></h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card card-body">
            <h2 class="h6">Benutzer hinzufügen</h2>
            <p class="text-muted small">Bestehenden Benutzer per Benutzername oder E-Mail zuordnen oder neuen anlegen (dann Passwort angeben).</p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="mb-2"><label class="form-label mb-0">Benutzername oder E-Mail *</label><input name="username" class="form-control form-control-sm" required></div>
                <div class="mb-2"><label class="form-label mb-0">Passwort (nur für neue)</label><input type="password" name="password" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label mb-0">Rolle</label>
                    <select name="role" class="form-select form-select-sm">
                        <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $r === 'worker' ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-success btn-sm w-100"><i class="bi bi-person-plus"></i> Hinzufügen</button>
            </form>
            <hr>
            <div class="small text-muted">
                <strong>Rollen:</strong><br>
                <strong>owner</strong> – Vollzugriff inkl. Benutzerverwaltung<br>
                <strong>manager</strong> – alle Daten, keine Benutzer<br>
                <strong>worker</strong> – Tiere/Behandlungen erfassen<br>
                <strong>viewer</strong> – nur Lesezugriff
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>Benutzer</th><th>Rolle</th><th>Letzter Login</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><?= e($m['username']) ?><?php if ($m['email']): ?><br><small class="text-muted"><?= e($m['email']) ?></small><?php endif; ?></td>
                            <td>
                                <form method="post" class="d-flex gap-1">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="farm_user_id" value="<?= (int) $m['farm_user_id'] ?>">
                                    <select name="role" class="form-select form-select-sm" style="width:auto;">
                                        <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $m['role'] === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check"></i></button>
                                </form>
                            </td>
                            <td><small><?= e($m['last_login'] ?: '-') ?></small></td>
                            <td class="text-end">
                                <form method="post" onsubmit="return confirm('Zuordnung wirklich entfernen?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="farm_user_id" value="<?= (int) $m['farm_user_id'] ?>">
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
