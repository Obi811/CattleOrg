<?php
/**
 * public/index.php
 * -----------------------------------------------------------------------------
 * Dashboard des aktuellen Betriebs: Kennzahlen und Schnellzugriffe.
 * Alle Abfragen sind strikt auf den aktiven Betrieb (farm_id) beschränkt.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/task_queries.php';
require_once __DIR__ . '/../includes/task_actions.php';

requireLogin();
requireFarmAccess();
$farmId = getCurrentFarmId();

// Gemeinsame POST-Verarbeitung der Aufgaben-Schnellaktionen (leitet selbst um)
handleTaskPost($db, $farmId, url('index.php'));

$canWrite = hasPermission('treatments.write') && !isDemoBetrieb();
$canPreg  = hasPermission('pregnancy.write')  && !isDemoBetrieb();
$heute    = date('Y-m-d');

// Kennzahlen ermitteln (immer mit farm_id gefiltert)
$stats = [];

$stmt = $db->prepare('SELECT COUNT(*) FROM cattle WHERE farm_id = :f AND status = :s');
$stmt->execute([':f' => $farmId, ':s' => 'Aktiv']);
$stats['aktiv'] = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM cattle WHERE farm_id = :f AND status = :s');
$stmt->execute([':f' => $farmId, ':s' => 'Inaktiv']);
$stats['inaktiv'] = (int) $stmt->fetchColumn();

// Offene Trächtigkeitskontrollen: besamt, aber noch keine Kontrolle danach
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

// --- Aufgaben-Kennzahlen für den Überblick ---
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

// Weiterbehandlungen: Behandlungen von gestern (außer Trockenstellen)
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

// --- Aufgaben für die getrennten Aufgaben-Karten laden (gleiche Logik wie todo_list.php) ---
$tasks = loadTasks($db, $farmId);
$nachbehandlungen = $tasks['nachbehandlungen'];
$vorbehandlungen  = $tasks['vorbehandlungen'];
$hemmFaellig      = $tasks['hemm_faellig'];
$hemmSentToday    = $tasks['hemm_sent_today'];
$hemmVersendet    = $tasks['hemm_versendet'];
$hemmResultToday  = $tasks['hemm_result_today'];
$traechtigOffen   = $tasks['traechtig_offen'];
$traechtigToday   = $tasks['traechtig_today'];
$letzteBehandlungen = $tasks['letzte_behandlungen'];
$offenGesamt = $tasks['counts']['nachbehandlungen'] + $tasks['counts']['hemm_faellig']
             + $tasks['counts']['hemm_versendet'] + $tasks['counts']['traechtig_offen'];

// Dringlichkeits-Marker (rotes Abzeichen) je aufklappbarem Abschnitt
$aufgabenUrgent = $tasks['counts']['hemm_faellig'] > 0;          // überfällige Hemmstoffproben
$lbCount        = $tasks['counts']['letzte_behandlungen'];
$lbUrgent       = $tasks['counts']['letzte_behandlungen_wz'] > 0; // mind. eine aktive Wartezeit

$pageTitle = 'Start';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Übersicht &ndash; <?= e($_SESSION['current_farm_name']) ?></h1>
    <?php if ($isDemo ?? isDemoBetrieb()): ?>
        <span class="badge bg-warning text-dark">Demo-Betrieb (nur Lesezugriff)</span>
    <?php endif; ?>
</div>

<style>
/* Aufklappbare Abschnitte auf der Startseite (native <details>) */
details.js-collapsible > summary.collapse-summary {
    list-style: none;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .5rem;
    padding: .75rem 1rem;
    background: #fff;
    border-radius: .5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
    font-weight: 600;
}
details.js-collapsible > summary.collapse-summary::-webkit-details-marker { display: none; }
details.js-collapsible[open] > summary.collapse-summary {
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}
.collapse-chevron { transition: transform .2s ease; }
details.js-collapsible[open] .collapse-chevron { transform: rotate(180deg); }
.collapse-body { padding-top: .75rem; }
</style>

<!-- Übersicht / Kennzahlen: immer sichtbar -->
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
            <div class="text-muted">Offene Trächtigkeit</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6"><?= $stats['behandlungen_30'] ?></div>
            <div class="text-muted">Behandlungen (30 T)</div>
        </div></div>
    </div>
</div>

<!-- Aufgaben: aufklappbar, standardmäßig geschlossen -->
<details class="mb-3 js-collapsible" data-collapse-key="dash.aufgaben">
    <summary class="collapse-summary">
        <span>
            <i class="bi bi-list-check text-primary"></i> Aufgaben
            <span class="badge bg-secondary ms-1"><?= $offenGesamt ?></span>
            <?php if ($aufgabenUrgent): ?>
                <span class="badge bg-danger ms-1" title="Überfällige Aufgaben"><i class="bi bi-exclamation-triangle-fill"></i></span>
            <?php endif; ?>
        </span>
        <span class="d-flex align-items-center gap-2">
            <a class="btn btn-outline-primary btn-sm" href="<?= e(url('todo_list.php')) ?>" onclick="event.stopPropagation();"><i class="bi bi-list-check"></i> Alle Aufgaben</a>
            <i class="bi bi-chevron-down collapse-chevron"></i>
        </span>
    </summary>
    <div class="collapse-body">
        <?php if ($offenGesamt === 0 && empty($hemmSentToday) && empty($hemmResultToday) && empty($traechtigToday)): ?>
            <div class="card"><div class="card-body">
                <p class="text-muted mb-0"><i class="bi bi-check2-circle text-success"></i> Aktuell keine offenen Aufgaben.</p>
            </div></div>
        <?php else: ?>
            <?php
            // Jedes Thema als eigene, klar getrennte Karte mit Inline-Erfassung
            require __DIR__ . '/../templates/task_cards.php';
            ?>
        <?php endif; ?>
    </div>
</details>

<!-- Letzte Behandlungen: aufklappbar, standardmäßig geschlossen -->
<details class="mb-4 js-collapsible" data-collapse-key="dash.letzte_behandlungen">
    <summary class="collapse-summary">
        <span>
            <i class="bi bi-clipboard2-pulse text-primary"></i> Letzte Behandlungen
            <span class="badge bg-secondary ms-1"><?= $lbCount ?></span>
            <?php if ($lbUrgent): ?>
                <span class="badge bg-danger ms-1" title="Aktive Wartezeit"><i class="bi bi-exclamation-triangle-fill"></i></span>
            <?php endif; ?>
            <small class="text-muted fw-normal ms-1">(letzte 7 Tage)</small>
        </span>
        <i class="bi bi-chevron-down collapse-chevron"></i>
    </summary>
    <div class="collapse-body">
        <div class="card shadow-sm">
            <?php
            // Karte ohne eigene Kopfzeile – die Kopfzeile liefert bereits der Abschnitt
            $showRecentCardHeader = false;
            require __DIR__ . '/../templates/recent_treatments.php';
            ?>
        </div>
    </div>
</details>

<!-- Schnellzugriff: immer sichtbar -->
<div class="card mb-4"><div class="card-body">
    <h2 class="h5">Schnellzugriff</h2>
    <div class="row g-2">
        <?php if (hasPermission('cattle.read')): ?>
            <div class="col-12 col-md-6"><a class="btn btn-outline-primary w-100" href="<?= e(url('cattle_list.php')) ?>"><i class="bi bi-list-ul"></i> Tierliste</a></div>
        <?php endif; ?>
        <?php if (hasPermission('cattle.write')): ?>
            <div class="col-12 col-md-6"><a class="btn btn-outline-success w-100" href="<?= e(url('cattle_add.php')) ?>"><i class="bi bi-plus-circle"></i> Tier anlegen</a></div>
        <?php endif; ?>
        <div class="col-12 col-md-6"><a class="btn btn-outline-secondary w-100" href="<?= e(url('pending_pregnancy.php')) ?>"><i class="bi bi-hourglass-split"></i> Offene Trächtigkeitskontrollen</a></div>
        <div class="col-12 col-md-6"><a class="btn btn-outline-secondary w-100" href="<?= e(url('calendar.php')) ?>"><i class="bi bi-calendar3"></i> Kalender</a></div>
    </div>
</div></div>

<script>
// Aufklappbare Abschnitte: Zustand je Abschnitt im localStorage merken.
// Standard: geschlossen (kein "open"-Attribut im Markup). Ein gespeicherter
// Zustand hat Vorrang und wird beim Laden wiederhergestellt.
(function () {
    document.querySelectorAll('details.js-collapsible').forEach(function (d) {
        var key = 'collapse:' + d.getAttribute('data-collapse-key');
        try {
            var saved = localStorage.getItem(key);
            if (saved === 'open') { d.open = true; }
            else if (saved === 'closed') { d.open = false; }
        } catch (e) { /* localStorage nicht verfügbar – Standard beibehalten */ }
        d.addEventListener('toggle', function () {
            try { localStorage.setItem(key, d.open ? 'open' : 'closed'); } catch (e) {}
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
