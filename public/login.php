<?php
/**
 * public/login.php
 * -----------------------------------------------------------------------------
 * Anmeldung. Nach erfolgreichem Login wird die Mandantenzuordnung ausgewertet:
 *   - 0 Betriebe (kein Admin) -> Fehlermeldung
 *   - genau 1 Betrieb         -> automatisch wählen, weiter zur Tierliste
 *   - mehrere Betriebe        -> farm_select.php
 * Zusätzlich: Demo-Button für den schreibgeschützten Demo-Betrieb.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Bereits angemeldet? -> weiter
if (isLoggedIn() && !empty($_SESSION['current_farm_id'])) {
    redirect(url('index.php'));
}

/** Gemeinsame Login-Routine: setzt Session + leitet abhängig von Betrieben um. */
function performLogin(PDO $db, array $user): void
{
    loginUser($user);

    // System-Admin ohne Betriebszuordnung -> Betriebsauswahl (kann jeden wählen)
    $farms = getUserFarms($db, (int) $user['id']);

    if (isAdmin()) {
        redirect(url('farm_select.php'));
    }
    if (count($farms) === 0) {
        logoutUser();
        flash('error', 'Ihrem Konto ist kein Betrieb zugeordnet. Bitte den Betriebsinhaber kontaktieren.', 'danger');
        redirect(url('login.php'));
    }
    if (count($farms) === 1) {
        setCurrentFarm($db, (int) $user['id'], (int) $farms[0]['id']);
        redirect(url('cattle_list.php'));
    }
    redirect(url('farm_select.php'));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'login';

    if ($action === 'demo') {
        // Demo-Login: festen Demo-Benutzer laden
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => 'demo']);
        $user = $stmt->fetch();
        if ($user) {
            performLogin($db, $user);
        } else {
            $error = 'Demo-Konto ist nicht eingerichtet.';
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Bitte Benutzername und Passwort eingeben.';
        } else {
            $stmt = $db->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // last_login aktualisieren
                $upd = $db->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
                $upd->execute([':id' => (int) $user['id']]);
                performLogin($db, $user);
            } else {
                // Bewusst generische Fehlermeldung (keine User-Enumeration)
                $error = 'Benutzername oder Passwort ist falsch.';
            }
        }
    }
}

$pageTitle = 'Anmeldung';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>body{background:#212529;}</style>
</head>
<body>
<div class="container" style="max-width:420px;margin-top:8vh;">
    <div class="card shadow">
        <div class="card-body p-4">
            <h1 class="h4 text-center mb-4">
                <i class="bi bi-clipboard2-pulse"></i> <?= e(APP_NAME) ?>
            </h1>
            <?php if ($error !== null): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>
            <?php $f = flash('error'); if ($f): ?>
                <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="login">
                <div class="mb-3">
                    <label class="form-label">Benutzername</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Passwort</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right"></i> Anmelden
                </button>
            </form>
            <hr>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="demo">
                <button type="submit" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-eye"></i> Demo-Betrieb ansehen (nur Lesen)
                </button>
            </form>
        </div>
    </div>
    <p class="text-center text-secondary mt-3 small">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
