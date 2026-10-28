<?php
/**
 * public/farm_select.php
 * -----------------------------------------------------------------------------
 * Betriebsauswahl / Betriebswechsel. Listet die Betriebe des Users (bzw. alle
 * aktiven Betriebe für System-Admins) und setzt den aktiven Mandanten.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
$userId = currentUserId();

// Betriebe ermitteln
if (isAdmin()) {
    // Admin sieht alle aktiven Betriebe
    $stmt = $db->query('SELECT id, name, is_demo, betriebsnummer FROM farms WHERE is_active = 1 ORDER BY name');
    $farms = $stmt->fetchAll();
    // Rolle für Anzeige
    foreach ($farms as &$f) { $f['role'] = 'admin'; }
    unset($f);
} else {
    $farms = getUserFarms($db, (int) $userId);
}

// Auswahl verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $farmId = (int) ($_POST['farm_id'] ?? 0);
    if ($farmId > 0 && setCurrentFarm($db, (int) $userId, $farmId)) {
        redirect(url('index.php'));
    }
    flash('error', 'Auswahl ungültig oder kein Zugriff auf diesen Betrieb.', 'danger');
    redirect(url('farm_select.php'));
}

$pageTitle = 'Betrieb wählen';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card">
            <div class="card-body">
                <h1 class="h4 mb-3"><i class="bi bi-buildings"></i> Betrieb wählen</h1>
                <?php if (empty($farms)): ?>
                    <div class="alert alert-warning">
                        Es ist kein Betrieb verfügbar. Bitte den Betriebsinhaber oder Administrator kontaktieren.
                    </div>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($farms as $farm): ?>
                            <form method="post" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <?= csrfField() ?>
                                <input type="hidden" name="farm_id" value="<?= (int) $farm['id'] ?>">
                                <span>
                                    <strong><?= e($farm['name']) ?></strong>
                                    <?php if (!empty($farm['is_demo'])): ?>
                                        <span class="badge bg-warning text-dark">Demo</span>
                                    <?php endif; ?>
                                    <br><small class="text-muted">Rolle: <?= e($farm['role']) ?></small>
                                </span>
                                <button type="submit" class="btn btn-primary btn-sm">
                                    Auswählen <i class="bi bi-arrow-right"></i>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="mt-3 text-end">
                    <a href="<?= e(url('logout.php')) ?>" class="btn btn-link text-muted">Abmelden</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
