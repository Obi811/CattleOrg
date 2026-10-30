<?php
/**
 * public/profile.php
 * -----------------------------------------------------------------------------
 * Benutzerprofil: eigenes Passwort ändern und Konto-Informationen anzeigen.
 * Jeder eingeloggte Nutzer darf sein Passwort selbst ändern.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
$userId = currentUserId();

// Eigene Benutzerdaten laden
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();
if (!$user) {
    flash('error', 'Benutzerkonto nicht gefunden.', 'danger');
    redirect(url('index.php'));
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validierung
        if (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'Das aktuelle Passwort ist nicht korrekt.';
        }
        if (strlen($newPassword) < 8) {
            $errors[] = 'Das neue Passwort muss mindestens 8 Zeichen haben.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Die Passwort-Wiederholung stimmt nicht überein.';
        }

        if (empty($errors)) {
            $upd = $db->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
            $upd->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
            $success = true;
            flash('success', 'Ihr Passwort wurde erfolgreich geändert.', 'success');
            redirect(url('profile.php'));
        }
    } elseif ($action === 'change_email') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newEmail = trim($_POST['new_email'] ?? '');

        // Zur Sicherheit aktuelles Passwort bestätigen
        if (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'Das aktuelle Passwort ist nicht korrekt.';
        }
        if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }
        if (empty($errors) && $newEmail !== '') {
            // Eindeutigkeit der E-Mail prüfen (außer eigenem Konto)
            $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE email = :e AND id <> :id');
            $chk->execute([':e' => $newEmail, ':id' => $userId]);
            if ((int) $chk->fetchColumn() > 0) {
                $errors[] = 'Diese E-Mail-Adresse wird bereits verwendet.';
            }
        }

        if (empty($errors)) {
            $upd = $db->prepare('UPDATE users SET email = :e WHERE id = :id');
            $upd->execute([':e' => $newEmail !== '' ? $newEmail : null, ':id' => $userId]);
            flash('success', 'Ihre E-Mail-Adresse wurde aktualisiert.', 'success');
            redirect(url('profile.php'));
        }
    }
}

// Betriebszuordnungen laden
$farms = getUserFarms($db, $userId);

$pageTitle = 'Mein Profil';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="h3 mb-3"><i class="bi bi-person-circle"></i> Mein Profil</h1>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3">Konto-Informationen</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Benutzername</dt>
                    <dd class="col-sm-8"><?= e($user['username']) ?></dd>
                    <dt class="col-sm-4">E-Mail</dt>
                    <dd class="col-sm-8"><?= e($user['email'] ?: '-') ?></dd>
                    <dt class="col-sm-4">Erstellt am</dt>
                    <dd class="col-sm-8"><?= e(formatDate($user['created_at'], 'd.m.y H:i', '-')) ?></dd>
                    <dt class="col-sm-4">Letzter Login</dt>
                    <dd class="col-sm-8"><?= e($user['last_login'] ?: '-') ?></dd>
                    <dt class="col-sm-4">System-Admin</dt>
                    <dd class="col-sm-8">
                        <?php if (isAdmin()): ?>
                            <span class="badge bg-danger">Ja</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Nein</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3">Meine Betriebe</h2>
                <?php if (empty($farms)): ?>
                    <p class="text-muted mb-0">Sie sind aktuell keinem Betrieb zugeordnet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Betrieb</th><th>Rolle</th><th>Demo</th></tr></thead>
                            <tbody>
                                <?php foreach ($farms as $f): ?>
                                    <tr>
                                        <td><?= e($f['name']) ?></td>
                                        <td><span class="badge bg-secondary"><?= e($f['role']) ?></span></td>
                                        <td><?= (int) $f['is_demo'] === 1 ? '<span class="badge bg-warning text-dark">Demo</span>' : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><?= e($err) ?></div>
        <?php endforeach; ?>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3"><i class="bi bi-envelope"></i> E-Mail ändern</h2>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_email">
                    <div class="mb-3">
                        <label class="form-label">Neue E-Mail-Adresse</label>
                        <input type="email" name="new_email" class="form-control" value="<?= e($user['email']) ?>">
                        <small class="text-muted">Leer lassen, um die E-Mail-Adresse zu entfernen.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Aktuelles Passwort * <small class="text-muted">(zur Bestätigung)</small></label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> E-Mail speichern</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="h5 mb-3"><i class="bi bi-key"></i> Passwort ändern</h2>
                <?php if ($success): ?>
                    <div class="alert alert-success">Ihr Passwort wurde erfolgreich geändert.</div>
                <?php endif; ?>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label">Aktuelles Passwort *</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Neues Passwort * <small class="text-muted">(mind. 8 Zeichen)</small></label>
                        <input type="password" name="new_password" class="form-control" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Neues Passwort wiederholen *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8">
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Passwort ändern</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
