<?php
/**
 * admin/user_edit.php
 * -----------------------------------------------------------------------------
 * System-Admin: Detailseite eines Benutzers.
 *   - Stammdaten (Benutzername, E-Mail) bearbeiten
 *   - Betriebszuordnungen verwalten (zuordnen, Rolle ändern, entfernen)
 *   - Benutzer löschen (mit Schutzregeln, in einer Transaktion)
 *
 * Bestehende Muster: requireAdmin(), csrfField()/requireCsrf(), flash(),
 * redirect(url(...)), e(). Kein Framework, kein Router.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

requireAdmin();

$roles = ['owner', 'manager', 'worker', 'viewer'];
$errors = [];

$userId = (int) ($_GET['id'] ?? $_POST['user_id'] ?? 0);
if ($userId <= 0) {
    flash('error', 'Kein Benutzer angegeben.', 'danger');
    redirect(url('admin/user_list.php'));
}

// Zielbenutzer laden
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();
if (!$user) {
    flash('error', 'Benutzer nicht gefunden.', 'danger');
    redirect(url('admin/user_list.php'));
}

/** Anzahl der verbleibenden System-Admins (zum Schutz des letzten Admins). */
function countSystemAdmins(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------------------------
    // Stammdaten bearbeiten (Benutzername + E-Mail)
    // -------------------------------------------------------------------
    if ($action === 'update_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($username === '') {
            $errors[] = 'Benutzername darf nicht leer sein.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }

        if (empty($errors)) {
            // Eindeutigkeit Benutzername (außer dem Benutzer selbst)
            $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE username = :u AND id <> :id');
            $chk->execute([':u' => $username, ':id' => $userId]);
            if ((int) $chk->fetchColumn() > 0) {
                $errors[] = 'Benutzername bereits vergeben.';
            }
        }
        if (empty($errors) && $email !== '') {
            // Eindeutigkeit E-Mail (außer dem Benutzer selbst)
            $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE email = :e AND id <> :id');
            $chk->execute([':e' => $email, ':id' => $userId]);
            if ((int) $chk->fetchColumn() > 0) {
                $errors[] = 'E-Mail-Adresse bereits vergeben.';
            }
        }

        if (empty($errors)) {
            $upd = $db->prepare('UPDATE users SET username = :u, email = :e WHERE id = :id');
            $upd->execute([
                ':u' => $username,
                ':e' => $email !== '' ? $email : null,
                ':id' => $userId,
            ]);
            flash('success', 'Stammdaten gespeichert.', 'success');
            redirect(url('admin/user_edit.php?id=' . $userId));
        }

    // -------------------------------------------------------------------
    // Betrieb zuordnen
    // -------------------------------------------------------------------
    } elseif ($action === 'assign_farm') {
        $farmId = (int) ($_POST['farm_id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'worker';

        $fChk = $db->prepare('SELECT COUNT(*) FROM farms WHERE id = :f');
        $fChk->execute([':f' => $farmId]);
        if ($farmId <= 0 || (int) $fChk->fetchColumn() === 0) {
            $errors[] = 'Bitte einen gültigen Betrieb auswählen.';
        } else {
            // Duplikate vermeiden: vorhandene Zuordnung wird aktualisiert
            $link = $db->prepare(
                'INSERT INTO farm_users (farm_id, user_id, role) VALUES (:f, :u, :r)
                 ON DUPLICATE KEY UPDATE role = VALUES(role)'
            );
            $link->execute([':f' => $farmId, ':u' => $userId, ':r' => $role]);
            flash('success', 'Betrieb zugeordnet.', 'success');
            redirect(url('admin/user_edit.php?id=' . $userId));
        }

    // -------------------------------------------------------------------
    // Rolle einer Zuordnung ändern
    // -------------------------------------------------------------------
    } elseif ($action === 'change_role') {
        $fuId = (int) ($_POST['farm_user_id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'worker';
        $upd = $db->prepare('UPDATE farm_users SET role = :r WHERE id = :id AND user_id = :u');
        $upd->execute([':r' => $role, ':id' => $fuId, ':u' => $userId]);
        flash('success', 'Rolle aktualisiert.', 'success');
        redirect(url('admin/user_edit.php?id=' . $userId));

    // -------------------------------------------------------------------
    // Zuordnung entfernen
    // -------------------------------------------------------------------
    } elseif ($action === 'remove_farm') {
        $fuId = (int) ($_POST['farm_user_id'] ?? 0);
        $del = $db->prepare('DELETE FROM farm_users WHERE id = :id AND user_id = :u');
        $del->execute([':id' => $fuId, ':u' => $userId]);
        flash('success', 'Zuordnung entfernt.', 'success');
        redirect(url('admin/user_edit.php?id=' . $userId));

    // -------------------------------------------------------------------
    // Benutzer löschen (mit Schutzregeln, in einer Transaktion)
    // -------------------------------------------------------------------
    } elseif ($action === 'delete_user') {
        if ($userId === currentUserId()) {
            flash('error', 'Sie können Ihr eigenes Konto nicht löschen.', 'danger');
            redirect(url('admin/user_edit.php?id=' . $userId));
        }
        if ((int) $user['is_admin'] === 1 && countSystemAdmins($db) <= 1) {
            flash('error', 'Der letzte System-Admin kann nicht gelöscht werden.', 'danger');
            redirect(url('admin/user_edit.php?id=' . $userId));
        }

        try {
            $db->beginTransaction();
            // Abhängige Datensätze zuerst entfernen. Die Fremdschlüssel sind
            // zwar ON DELETE CASCADE, wir löschen aber explizit, um auch bei
            // abweichender DB-Konfiguration keinen Fatal Error zu riskieren.
            // Individuelle Permission-Overrides (hängen an farm_users).
            $db->prepare(
                'DELETE p FROM permissions p
                 JOIN farm_users fu ON fu.id = p.farm_user_id
                 WHERE fu.user_id = :u'
            )->execute([':u' => $userId]);
            // Betriebszuordnungen
            $db->prepare('DELETE FROM farm_users WHERE user_id = :u')->execute([':u' => $userId]);
            // API-Tokens
            $db->prepare('DELETE FROM api_tokens WHERE user_id = :u')->execute([':u' => $userId]);
            // Geräte-Tokens + Push-Log (sofern Tabellen vorhanden)
            foreach (['device_tokens', 'push_log'] as $optTable) {
                try {
                    $db->prepare("DELETE FROM {$optTable} WHERE user_id = :u")->execute([':u' => $userId]);
                } catch (PDOException $ignore) {
                    // Tabelle existiert in dieser Installation evtl. nicht -> ignorieren
                }
            }
            // Zuletzt den Benutzer selbst
            $db->prepare('DELETE FROM users WHERE id = :u')->execute([':u' => $userId]);
            $db->commit();
            flash('success', 'Benutzer wurde gelöscht.', 'success');
            redirect(url('admin/user_list.php'));
        } catch (PDOException $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash('error', 'Benutzer konnte nicht gelöscht werden, da noch abhängige Daten bestehen.', 'danger');
            redirect(url('admin/user_edit.php?id=' . $userId));
        }
    }
}

// Daten für die Anzeige laden
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

// Betriebszuordnungen des Benutzers
$fuStmt = $db->prepare(
    'SELECT fu.id AS farm_user_id, fu.role, f.id AS farm_id, f.name, f.is_demo, f.is_active
     FROM farm_users fu JOIN farms f ON f.id = fu.farm_id
     WHERE fu.user_id = :u ORDER BY f.name'
);
$fuStmt->execute([':u' => $userId]);
$assignments = $fuStmt->fetchAll();

// Noch nicht zugeordnete Betriebe (für das Dropdown)
$assignedIds = array_map(static fn ($a) => (int) $a['farm_id'], $assignments);
$allFarms = $db->query('SELECT id, name, is_demo, is_active FROM farms ORDER BY name')->fetchAll();
$availableFarms = array_filter($allFarms, static fn ($f) => !in_array((int) $f['id'], $assignedIds, true));

$pageTitle = 'Benutzer bearbeiten';
require_once __DIR__ . '/../../templates/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Benutzer bearbeiten <span class="badge bg-danger">System-Admin</span></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('admin/user_list.php')) ?>"><i class="bi bi-arrow-left"></i> Zur Liste</a>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card card-body mb-3">
            <h2 class="h6">Stammdaten</h2>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                <div class="mb-2"><label class="form-label mb-0">Benutzername *</label>
                    <input name="username" class="form-control form-control-sm" value="<?= e($user['username']) ?>" required></div>
                <div class="mb-2"><label class="form-label mb-0">E-Mail</label>
                    <input type="email" name="email" class="form-control form-control-sm" value="<?= e($user['email']) ?>"></div>
                <div class="mb-2 small text-muted">
                    System-Admin: <?= (int) $user['is_admin'] === 1 ? '<span class="badge bg-danger">Ja</span>' : '<span class="badge bg-secondary">Nein</span>' ?>
                    &middot; Letzter Login: <?= e(formatDate($user['last_login'], 'd.m.Y H:i', '-')) ?>
                </div>
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg"></i> Speichern</button>
            </form>
        </div>

        <div class="card card-body border-danger">
            <h2 class="h6 text-danger">Benutzer löschen</h2>
            <p class="small text-muted mb-2">Entfernt den Benutzer samt aller Betriebszuordnungen und Tokens. Das kann nicht rückgängig gemacht werden.</p>
            <form method="post" onsubmit="return confirm('Diesen Benutzer wirklich unwiderruflich löschen?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                <button class="btn btn-danger btn-sm w-100"
                    <?= $userId === currentUserId() ? 'disabled title="Eigenes Konto kann nicht gelöscht werden"' : '' ?>>
                    <i class="bi bi-trash"></i> Benutzer löschen
                </button>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card card-body">
            <h2 class="h6">Betriebszuordnungen</h2>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead><tr><th>Betrieb</th><th>Rolle</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($assignments)): ?>
                            <tr><td colspan="3" class="text-muted">Keine Betriebe zugeordnet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($assignments as $a): ?>
                            <tr>
                                <td><?= e($a['name']) ?>
                                    <?php if ((int) $a['is_demo'] === 1): ?><span class="badge bg-warning text-dark">Demo</span><?php endif; ?>
                                    <?php if ((int) $a['is_active'] !== 1): ?><span class="badge bg-secondary">inaktiv</span><?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" class="d-flex gap-1">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <input type="hidden" name="farm_user_id" value="<?= (int) $a['farm_user_id'] ?>">
                                        <select name="role" class="form-select form-select-sm" style="width:auto;">
                                            <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $a['role'] === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check"></i></button>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <form method="post" onsubmit="return confirm('Zuordnung wirklich entfernen?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="remove_farm">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <input type="hidden" name="farm_user_id" value="<?= (int) $a['farm_user_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h3 class="h6">Betrieb hinzufügen</h3>
            <?php if (empty($availableFarms)): ?>
                <p class="text-muted small mb-0">Der Benutzer ist bereits allen Betrieben zugeordnet.</p>
            <?php else: ?>
                <form method="post" class="row g-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="assign_farm">
                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                    <div class="col-sm-6">
                        <select name="farm_id" class="form-select form-select-sm" required>
                            <option value="">Betrieb wählen...</option>
                            <?php foreach ($availableFarms as $f): ?>
                                <option value="<?= (int) $f['id'] ?>"><?= e($f['name']) ?><?= (int) $f['is_demo'] === 1 ? ' (Demo)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <select name="role" class="form-select form-select-sm">
                            <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $r === 'worker' ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <button class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
