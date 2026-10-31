<?php
/**
 * public/todo_list.php
 * -----------------------------------------------------------------------------
 * Aufgaben-/Erinnerungsübersicht. Jedes Thema als eigene, klar getrennte
 * Karte mit Inline-Erfassung direkt aus der Liste (mobil-tauglich):
 *   1. Nachbehandlungen (gestern behandelt) – heute nachbehandeln? Ja / Nicht nötig
 *   2. Hemmstoffprobe fällig – direkt "weggeschickt" + laufende Nummer erfassen
 *   3. Hemmstoffproben verschickt – Ergebnis frei / nicht frei vor Ort erfassen
 *   4. Trächtigkeitskontrollen – Ergebnis trächtig / nicht trächtig vor Ort
 * Alles betriebsbezogen. Abfragen/Aktionen liegen in includes/task_*.php.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/task_queries.php';
require_once __DIR__ . '/../includes/task_actions.php';

requireLogin();
requirePermission('cattle.read');
$farmId = getCurrentFarmId();

$canWrite = hasPermission('treatments.write') && !isDemoBetrieb();
$canPreg  = hasPermission('pregnancy.write')  && !isDemoBetrieb();

// Gemeinsame POST-Verarbeitung (leitet bei erkannter Aktion selbst um)
handleTaskPost($db, $farmId, url('todo_list.php'));

$tasks = loadTasks($db, $farmId);
$nachbehandlungen = $tasks['nachbehandlungen'];
$vorbehandlungen  = $tasks['vorbehandlungen'];
$hemmFaellig      = $tasks['hemm_faellig'];
$hemmSentToday    = $tasks['hemm_sent_today'];
$hemmVersendet    = $tasks['hemm_versendet'];
$hemmResultToday  = $tasks['hemm_result_today'];
$traechtigOffen   = $tasks['traechtig_offen'];
$traechtigToday   = $tasks['traechtig_today'];
$geplanteFaellig  = $tasks['geplante_faellig'];
$letzteBehandlungen = $tasks['letzte_behandlungen'];

$offenGesamt = $tasks['counts']['nachbehandlungen'] + $tasks['counts']['hemm_faellig']
             + $tasks['counts']['hemm_versendet'] + $tasks['counts']['traechtig_offen']
             + $tasks['counts']['geplante_faellig'];

$heute = date('Y-m-d');

$pageTitle = 'Aufgaben';
require_once __DIR__ . '/../templates/header.php';
?>
<h1 class="h3 mb-3">Aufgaben &amp; Überblick <span class="badge bg-secondary"><?= $offenGesamt ?></span></h1>

<?php if ($offenGesamt === 0 && empty($hemmSentToday) && empty($hemmResultToday) && empty($traechtigToday)): ?>
    <div class="card"><div class="card-body">
        <p class="text-muted mb-0"><i class="bi bi-check2-circle text-success"></i> Aktuell keine offenen Aufgaben.</p>
    </div></div>
<?php endif; ?>

<?php
// Gemeinsame Aufgaben-Karten (werden auch auf dem Dashboard eingebunden)
require __DIR__ . '/../templates/task_cards.php';

// Thema "Letzte Behandlungen" (letzte 7 Tage) – hier dauerhaft ausgeklappt
require __DIR__ . '/../templates/recent_treatments.php';
?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
