<?php
/**
 * public/index.php
 * -----------------------------------------------------------------------------
 * Dashboard des aktuellen Betriebs: Kennzahlen und Schnellzugriffe.
 * Alle Abfragen sind strikt auf den aktiven Betrieb (farm_id) beschraenkt.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requireFarmAccess();
$farmId = getCurrentFarmId();

// Kennzahlen ermitteln (immer mit farm_id gefiltert)
$stats = [];

$stmt = $db->prepare('SELECT COUNT(*) FROM cattle WHERE farm_id = :f AND status = :s');
$stmt->execute([':f' => $farmId, ':s' => 'Aktiv']);
$stats['aktiv'] = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM cattle WHERE farm_id = :f AND status = :s');
$stmt->execute([':f' => $farmId, ':s' => 'Inaktiv']);
$stats['inaktiv'] = (int) $stmt->fetchColumn();

// Offene Traechtigkeitskontrollen: besamt, aber noch keine Kontrolle danach
$stmt = $db->prepare(
    'SELECT COUNT(DISTINCT i.cattle_id)
     FROM inseminations i
     WHERE i.farm_id = :f
       AND NOT EXISTS (
         SELECT 1 FROM pregnancy_checks p
         WHERE p.cattle_id = i.cattle_id AND p.farm_id = i.farm_id AND p.check_date >= i.date
       )'
);
$stmt->execute([':f' => $farmId]);
$stats['offene_traechtigkeit'] = (int) $stmt->fetchColumn();

// Behandlungen der letzten 30 Tage
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM treatments WHERE farm_id = :f AND treatment_date >= (CURDATE() - INTERVAL 30 DAY)'
);
$stmt->execute([':f' => $farmId]);
$stats['behandlungen_30'] = (int) $stmt->fetchColumn();

// Letzte Behandlungen
$stmt = $db->prepare(
    'SELECT t.treatment_date, t.medication, t.type, c.ear_tag
     FROM treatments t JOIN cattle c ON c.id = t.cattle_id
     WHERE t.farm_id = :f
     ORDER BY t.treatment_date DESC, t.id DESC
     LIMIT 8'
);
$stmt->execute([':f' => $farmId]);
$lastTreatments = $stmt->fetchAll();

// --- Aufgaben-Kennzahlen fuer den Ueberblick ---
// Tiere aktuell in Wartezeit (Behandlungsdatum + max(wz_milk, wz_fleisch) >= heute)
$stmt = $db->prepare(
    'SELECT COUNT(DISTINCT t.cattle_id)
     FROM treatments t
     JOIN medications m ON m.name = t.medication
     JOIN cattle c ON c.id = t.cattle_id AND c.farm_id = t.farm_id
     WHERE t.farm_id = :f AND c.status = "Aktiv"
       AND DATE_ADD(t.treatment_date, INTERVAL GREATEST(COALESCE(m.wz_milk,0), COALESCE(m.wz_fleisch,0)) DAY) >= CURDATE()'
);
$stmt->execute([':f' => $farmId]);
$stats['in_wartezeit'] = (int) $stmt->fetchColumn();

// Weiterbehandlungen: Behandlungen von gestern (ausser Trockenstellen)
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM treatments
     WHERE farm_id = :f AND treatment_date = (CURDATE() - INTERVAL 1 DAY) AND type <> "Trockenstellen"'
);
$stmt->execute([':f' => $farmId]);
$stats['weiterbehandlungen'] = (int) $stmt->fetchColumn();

// Hemmstoffproben mit Ergebnis ausstehend (versendet)
$stmt = $db->prepare('SELECT COUNT(*) FROM hemmstoff_probes WHERE farm_id = :f AND status = "versendet"');
$stmt->execute([':f' => $farmId]);
$stats['hemmstoff_offen'] = (int) $stmt->fetchColumn();

$pageTitle = 'Start';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Uebersicht &ndash; <?= e($_SESSION['current_farm_name']) ?></h1>
    <?php if ($isDemo ?? isDemoBetrieb()): ?>
        <span class="badge bg-warning text-dark">Demo-Betrieb (nur Lesezugriff)</span>
    <?php endif; ?>
</div>

<?php $aufgabenGesamt = $stats['weiterbehandlungen'] + $stats['offene_traechtigkeit'] + $stats['hemmstoff_offen']; ?>
<div class="card mb-4 border-primary">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h5 mb-1"><i class="bi bi-list-check text-primary"></i> Ueberblick / Aufgaben</h2>
            <div class="d-flex flex-wrap gap-3 small">
                <span><i class="bi bi-arrow-repeat text-warning"></i> Weiterbehandlungen: <strong><?= $stats['weiterbehandlungen'] ?></strong></span>
                <span><i class="bi bi-exclamation-triangle-fill text-danger"></i> Tiere in Wartezeit: <strong><?= $stats['in_wartezeit'] ?></strong></span>
                <span><i class="bi bi-droplet text-info"></i> Hemmstoffproben offen: <strong><?= $stats['hemmstoff_offen'] ?></strong></span>
                <span><i class="bi bi-hourglass-split text-secondary"></i> Offene Traechtigkeit: <strong><?= $stats['offene_traechtigkeit'] ?></strong></span>
            </div>
        </div>
        <a class="btn btn-primary" href="<?= e(url('todo_list.php')) ?>"><i class="bi bi-list-check"></i> Zu den Aufgaben</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6"><?= $stats['aktiv'] ?></div>
            <div class="text-muted">Aktive Tiere</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6"><?= $stats['inaktiv'] ?></div>
            <div class="text-muted">Inaktive Tiere</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6"><?= $stats['offene_traechtigkeit'] ?></div>
            <div class="text-muted">Offene Traechtigkeit</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6"><?= $stats['behandlungen_30'] ?></div>
            <div class="text-muted">Behandlungen (30 T)</div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card"><div class="card-body">
            <h2 class="h5">Schnellzugriff</h2>
            <div class="d-grid gap-2">
                <?php if (hasPermission('cattle.read')): ?>
                    <a class="btn btn-outline-primary" href="<?= e(url('cattle_list.php')) ?>"><i class="bi bi-list-ul"></i> Tierliste</a>
                <?php endif; ?>
                <?php if (hasPermission('cattle.write')): ?>
                    <a class="btn btn-outline-success" href="<?= e(url('cattle_add.php')) ?>"><i class="bi bi-plus-circle"></i> Tier anlegen</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= e(url('pending_pregnancy.php')) ?>"><i class="bi bi-hourglass-split"></i> Offene Traechtigkeitskontrollen</a>
                <a class="btn btn-outline-secondary" href="<?= e(url('calendar.php')) ?>"><i class="bi bi-calendar3"></i> Kalender</a>
            </div>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="card"><div class="card-body">
            <h2 class="h5">Letzte Behandlungen</h2>
            <?php if (empty($lastTreatments)): ?>
                <p class="text-muted mb-0">Noch keine Behandlungen erfasst.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($lastTreatments as $t): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= e(formatEarTag($t['ear_tag'])) ?> &ndash; <?= e($t['medication']) ?> <small class="text-muted">(<?= e($t['type']) ?>)</small></span>
                            <span class="text-muted"><?= e($t['treatment_date']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
