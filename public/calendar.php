<?php
/**
 * public/calendar.php
 * -----------------------------------------------------------------------------
 * Monatskalender mit relevanten Ereignissen (Behandlungen, Besamungen,
 * Traechtigkeitskontrollen) des aktuellen Betriebs. Betriebsbezogen.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.read');
$farmId = getCurrentFarmId();

// Monat / Jahr bestimmen (Default: aktueller Monat)
$year  = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) { $month = (int) date('n'); }
if ($year < 2000 || $year > 2100) { $year = (int) date('Y'); }

$first = sprintf('%04d-%02d-01', $year, $month);
$last  = date('Y-m-t', strtotime($first));

// Ereignisse je Tag sammeln. Jedes Ereignis speichert die verlinkte Ohrmarke
// (cattleLink) plus einen kurzen Beschreibungstext, damit vom Kalender aus
// direkt auf das Tier-Profil gesprungen werden kann.
$events = [];
$add = function (string $date, int $cattleId, ?string $earTag, string $text) use (&$events) {
    $events[$date][] = [
        'cattle_id' => $cattleId,
        'ear_tag'   => $earTag,
        'text'      => $text,
    ];
};

$t = $db->prepare('SELECT t.treatment_date AS d, t.medication, c.id AS cattle_id, c.ear_tag
                   FROM treatments t JOIN cattle c ON c.id = t.cattle_id AND c.farm_id = t.farm_id
                   WHERE t.farm_id=:f AND t.treatment_date BETWEEN :a AND :b');
$t->execute([':f' => $farmId, ':a' => $first, ':b' => $last]);
foreach ($t->fetchAll() as $r) { $add($r['d'], (int) $r['cattle_id'], $r['ear_tag'], 'Behandlung: ' . $r['medication']); }

$i = $db->prepare('SELECT i.date AS d, i.bull_name, c.id AS cattle_id, c.ear_tag
                   FROM inseminations i JOIN cattle c ON c.id = i.cattle_id AND c.farm_id = i.farm_id
                   WHERE i.farm_id=:f AND i.date BETWEEN :a AND :b');
$i->execute([':f' => $farmId, ':a' => $first, ':b' => $last]);
foreach ($i->fetchAll() as $r) { $add($r['d'], (int) $r['cattle_id'], $r['ear_tag'], 'Besamung'); }

$p = $db->prepare('SELECT p.check_date AS d, p.result, c.id AS cattle_id, c.ear_tag
                   FROM pregnancy_checks p JOIN cattle c ON c.id = p.cattle_id AND c.farm_id = p.farm_id
                   WHERE p.farm_id=:f AND p.check_date BETWEEN :a AND :b');
$p->execute([':f' => $farmId, ':a' => $first, ':b' => $last]);
foreach ($p->fetchAll() as $r) { $add($r['d'], (int) $r['cattle_id'], $r['ear_tag'], 'Traechtigkeit: ' . $r['result']); }

// Kalender-Grid aufbauen
$daysInMonth = (int) date('t', strtotime($first));
$startWeekday = (int) date('N', strtotime($first)); // 1 = Mo
$prevMonth = $month === 1 ? 12 : $month - 1;
$prevYear  = $month === 1 ? $year - 1 : $year;
$nextMonth = $month === 12 ? 1 : $month + 1;
$nextYear  = $month === 12 ? $year + 1 : $year;
$monthNames = [1=>'Januar','Februar','Maerz','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

$pageTitle = 'Kalender';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= e($monthNames[$month]) ?> <?= $year ?></h1>
    <div class="btn-group">
        <a class="btn btn-outline-secondary btn-sm" href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('calendar.php')) ?>">Heute</a>
        <a class="btn btn-outline-secondary btn-sm" href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>"><i class="bi bi-chevron-right"></i></a>
    </div>
</div>
<div class="card"><div class="card-body p-2">
    <table class="table table-bordered mb-0 text-center align-top" style="table-layout:fixed;">
        <thead><tr>
            <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $wd): ?><th class="small"><?= $wd ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <tr>
            <?php
            // Leerzellen vor dem 1.
            for ($i = 1; $i < $startWeekday; $i++) { echo '<td class="bg-light"></td>'; }
            $col = $startWeekday - 1;
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $isToday = $dateStr === date('Y-m-d');
                echo '<td style="height:90px;" class="' . ($isToday ? 'table-primary' : '') . '">';
                echo '<div class="fw-semibold small">' . $d . '</div>';
                if (!empty($events[$dateStr])) {
                    foreach (array_slice($events[$dateStr], 0, 3) as $ev) {
                        $cowLink = cattleLink($ev['cattle_id'], $ev['ear_tag'], 'text-decoration-none');
                        echo '<div class="badge bg-info text-dark text-truncate d-block mb-1 text-start" style="max-width:100%;" title="' . e($ev['text']) . '">'
                            . $cowLink . ' <span class="fw-normal">' . e($ev['text']) . '</span></div>';
                    }
                    if (count($events[$dateStr]) > 3) {
                        echo '<div class="small text-muted">+' . (count($events[$dateStr]) - 3) . ' mehr</div>';
                    }
                }
                echo '</td>';
                $col++;
                if ($col % 7 === 0 && $d < $daysInMonth) { echo '</tr><tr>'; }
            }
            // Leerzellen nach dem Monatsende
            while ($col % 7 !== 0) { echo '<td class="bg-light"></td>'; $col++; }
            ?>
        </tr>
        </tbody>
    </table>
</div></div>
<?php if (hasPermission('export.access')): ?>
<div class="mt-3">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('exports/export_calendar_pdf.php')) ?>?year=<?= $year ?>&month=<?= $month ?>">
        <i class="bi bi-file-earmark-pdf"></i> Monat als PDF
    </a>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
