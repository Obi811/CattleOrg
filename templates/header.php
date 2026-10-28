<?php
/**
 * templates/header.php
 * -----------------------------------------------------------------------------
 * Gemeinsamer Seitenkopf: HTML-Grundgerüst, Navigation mit Betriebsname,
 * Betriebswechsler und rollenbasierten Menüpunkten.
 *
 * Erwartet, dass bootstrap.php bereits eingebunden wurde. Optional kann vor dem
 * Include $pageTitle gesetzt werden.
 */

if (!defined('APP_NAME')) {
    // Sicherheitsnetz: ohne Bootstrap kein direkter Aufruf
    http_response_code(500);
    exit('Interner Fehler: Bootstrap nicht geladen.');
}

$pageTitle   = $pageTitle ?? APP_NAME;
$loggedIn    = isLoggedIn();
$farmName    = $_SESSION['current_farm_name'] ?? null;
$role        = currentRole();
$isDemo      = isDemoBetrieb();
$currentFile = basename($_SERVER['PHP_SELF']);

/** Hilfsfunktion: aktive Navigation hervorheben. */
function navActive(string $file, string $current): string
{
    return $file === $current ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f5f6f8; }
        .navbar-brand { font-weight: 600; }
        .farm-badge { font-size: .8rem; }
        .table-responsive { background: #fff; border-radius: .5rem; }
        .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        footer { color: #888; font-size: .85rem; }
    </style>
</head>
<body>
<?php if ($loggedIn && $farmName !== null): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= e(url('index.php')) ?>">
            <i class="bi bi-clipboard2-pulse"></i> <?= e(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link<?= navActive('index.php', $currentFile) ?>" href="<?= e(url('index.php')) ?>">
                        <i class="bi bi-house"></i> Start
                    </a>
                </li>
                <?php if (hasPermission('cattle.read')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('cattle_list.php', $currentFile) ?>" href="<?= e(url('cattle_list.php')) ?>">
                        <i class="bi bi-list-ul"></i> Tiere
                    </a>
                </li>
                <?php endif; ?>
                <?php if (hasPermission('pregnancy.write') || hasPermission('cattle.read')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('pending_pregnancy.php', $currentFile) ?>" href="<?= e(url('pending_pregnancy.php')) ?>">
                        <i class="bi bi-hourglass-split"></i> Trächtigkeit
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('calendar.php', $currentFile) ?>" href="<?= e(url('calendar.php')) ?>">
                        <i class="bi bi-calendar3"></i> Kalender
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('todo_list.php', $currentFile) ?>" href="<?= e(url('todo_list.php')) ?>">
                        <i class="bi bi-check2-square"></i> Aufgaben
                    </a>
                </li>
                <?php if (hasPermission('medications.manage')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('add_medications.php', $currentFile) ?>" href="<?= e(url('add_medications.php')) ?>">
                        <i class="bi bi-capsule"></i> Medikamente
                    </a>
                </li>
                <?php endif; ?>
                <?php if (hasPermission('users.manage')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('farm_users.php', $currentFile) ?>" href="<?= e(url('admin/farm_users.php')) ?>">
                        <i class="bi bi-people"></i> Benutzer
                    </a>
                </li>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-shield-lock"></i> Admin
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= e(url('admin/farm_manage.php')) ?>">Betriebe verwalten</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('admin/user_list.php')) ?>">Benutzer (System)</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('admin/medications.php')) ?>">Medikamente (global)</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-secondary farm-badge">
                    <i class="bi bi-buildings"></i> <?= e($farmName) ?>
                    <?php if ($isDemo): ?><span class="badge bg-warning text-dark">Demo</span><?php endif; ?>
                    <span class="text-light-emphasis">(<?= e($role ?? '-') ?>)</span>
                </span>
                <a class="btn btn-outline-light btn-sm" href="<?= e(url('farm_select.php')) ?>" title="Betrieb wechseln">
                    <i class="bi bi-arrow-left-right"></i>
                </a>
                <a class="btn btn-outline-light btn-sm" href="<?= e(url('profile.php')) ?>" title="Mein Profil / Passwort ändern">
                    <i class="bi bi-person-circle"></i>
                </a>
                <a class="btn btn-outline-light btn-sm" href="<?= e(url('logout.php')) ?>">
                    <i class="bi bi-box-arrow-right"></i> Abmelden
                </a>
            </div>
        </div>
    </div>
</nav>
<?php endif; ?>
<main class="container-fluid py-4">
<?php
// Flash-Nachrichten ausgeben (einheitlich)
foreach (['error', 'success', 'info', 'login'] as $flashKey) {
    $f = flash($flashKey);
    if ($f !== null) {
        $type = e($f['type']);
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
            . e($f['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}
?>
