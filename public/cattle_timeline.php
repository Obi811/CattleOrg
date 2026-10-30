<?php
/**
 * public/cattle_timeline.php
 * -----------------------------------------------------------------------------
 * Chronologischer Gesamtverlauf eines Tieres (Behandlungen, Besamungen,
 * Trächtigkeitskontrollen, Stallwechsel) in einer Zeitleiste. Betriebsbezogen.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.read');
$farmId = getCurrentFarmId();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM cattle WHERE id = :id AND farm_id = :f');
$stmt->execute([':id' => $id, ':f' => $farmId]);
$cattle = $stmt->fetch();
if (!$cattle) {
    http_response_code(404);
    flash('error', 'Tier nicht gefunden.', 'danger');
    redirect(url('cattle_list.php'));
}

// Ereignisse einsammeln
$events = [];

$tr = $db->prepare('SELECT treatment_date AS d, medication, type FROM treatments WHERE cattle_id=:id AND farm_id=:f');
$tr->execute([':id' => $id, ':f' => $farmId]);
foreach ($tr->fetchAll() as $r) {
    $events[] = ['date' => $r['d'], 'icon' => 'capsule', 'color' => 'success',
        'title' => 'Behandlung', 'text' => $r['medication'] . ' (' . $r['type'] . ')'];
}

$in = $db->prepare('SELECT date AS d, bull_name, type FROM inseminations WHERE cattle_id=:id AND farm_id=:f');
$in->execute([':id' => $id, ':f' => $farmId]);
foreach ($in->fetchAll() as $r) {
    $events[] = ['date' => $r['d'], 'icon' => 'gender-female', 'color' => 'primary',
        'title' => 'Besamung', 'text' => trim(($r['bull_name'] ?? '') . ' (' . $r['type'] . ')')];
}

$pr = $db->prepare('SELECT check_date AS d, result, comment FROM pregnancy_checks WHERE cattle_id=:id AND farm_id=:f');
$pr->execute([':id' => $id, ':f' => $farmId]);
foreach ($pr->fetchAll() as $r) {
    $events[] = ['date' => $r['d'], 'icon' => 'clipboard-check', 'color' => 'info',
        'title' => 'Trächtigkeitskontrolle', 'text' => 'Ergebnis: ' . $r['result'] . ($r['comment'] ? ' – ' . $r['comment'] : '')];
}

$sh = $db->prepare('SELECT start_date AS d, stall_name FROM stall_history WHERE cattle_id=:id AND farm_id=:f');
$sh->execute([':id' => $id, ':f' => $farmId]);
foreach ($sh->fetchAll() as $r) {
    $events[] = ['date' => $r['d'], 'icon' => 'house-door', 'color' => 'secondary',
        'title' => 'Stallwechsel', 'text' => 'Stall: ' . $r['stall_name']];
}

// Nach Datum absteigend sortieren
usort($events, fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

$pageTitle = 'Verlauf ' . formatEarTag($cattle['ear_tag']);
require_once __DIR__ . '/../templates/header.php';
?>
<h1 class="h3 mb-3">Verlauf &ndash; <?= e(formatEarTag($cattle['ear_tag'])) ?></h1>
<div class="card"><div class="card-body">
    <?php if (empty($events)): ?>
        <p class="text-muted mb-0">Keine Ereignisse erfasst.</p>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($events as $ev): ?>
                <li class="list-group-item d-flex align-items-start gap-3">
                    <span class="badge bg-<?= e($ev['color']) ?> rounded-pill"><i class="bi bi-<?= e($ev['icon']) ?>"></i></span>
                    <div>
                        <div class="fw-semibold"><?= e($ev['title']) ?> <small class="text-muted">&middot; <?= e(formatDate($ev['date'])) ?></small></div>
                        <div class="small"><?= e($ev['text']) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div></div>
<a href="<?= e(url('cattle_show.php')) ?>?id=<?= $id ?>" class="btn btn-link mt-2">&laquo; Zurück zum Tier</a>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
