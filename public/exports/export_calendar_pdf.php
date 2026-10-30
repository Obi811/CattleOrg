<?php
/**
 * exports/export_calendar_pdf.php
 * -----------------------------------------------------------------------------
 * PDF-Export der Monatsereignisse (Behandlungen, Besamungen,
 * Trächtigkeitskontrollen) eines Betriebs. Strikt betriebsbezogen.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

requireLogin();
requirePermission('export.access');
$farmId = getCurrentFarmId();

$year  = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) { $month = (int) date('n'); }
if ($year < 2000 || $year > 2100) { $year = (int) date('Y'); }
$first = sprintf('%04d-%02d-01', $year, $month);
$last  = date('Y-m-t', strtotime($first));

$events = [];
$t = $db->prepare("SELECT treatment_date d, 'Behandlung' typ, CONCAT(medication,' (',type,')') info, cattle_id FROM treatments WHERE farm_id=:f AND treatment_date BETWEEN :a AND :b");
$t->execute([':f' => $farmId, ':a' => $first, ':b' => $last]);
$events = array_merge($events, $t->fetchAll());
$i = $db->prepare("SELECT date d, 'Besamung' typ, COALESCE(bull_name,'') info, cattle_id FROM inseminations WHERE farm_id=:f AND date BETWEEN :a AND :b");
$i->execute([':f' => $farmId, ':a' => $first, ':b' => $last]);
$events = array_merge($events, $i->fetchAll());
$p = $db->prepare("SELECT check_date d, 'Trächtigkeit' typ, result info, cattle_id FROM pregnancy_checks WHERE farm_id=:f AND check_date BETWEEN :a AND :b");
$p->execute([':f' => $farmId, ':a' => $first, ':b' => $last]);
$events = array_merge($events, $p->fetchAll());

// Ohrmarken nachladen
$earTags = [];
if ($events) {
    $ids = array_values(array_unique(array_map(fn($e) => (int) $e['cattle_id'], $events)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $c = $db->prepare("SELECT id, ear_tag FROM cattle WHERE farm_id = ? AND id IN ($in)");
    $c->execute(array_merge([$farmId], $ids));
    foreach ($c->fetchAll() as $row) { $earTags[(int) $row['id']] = $row['ear_tag']; }
}

usort($events, fn($a, $b) => strcmp((string) $a['d'], (string) $b['d']));

$monthNames = [1=>'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
$farmName = $_SESSION['current_farm_name'] ?? 'Betrieb';

ob_start();
?>
<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">
<style>
    body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222;}
    h1{font-size:16px;margin:0 0 4px;}
    .meta{color:#666;font-size:10px;margin-bottom:10px;}
    table{width:100%;border-collapse:collapse;}
    th,td{border:1px solid #999;padding:4px 6px;text-align:left;}
    th{background:#eee;}
</style></head><body>
    <h1>Kalender &ndash; <?= e($monthNames[$month]) ?> <?= $year ?> (<?= e($farmName) ?>)</h1>
    <div class="meta">Erstellt am <?= e(date('d.m.y H:i')) ?> &middot; <?= count($events) ?> Ereignisse</div>
    <table>
        <thead><tr><th>Datum</th><th>Ohrmarke</th><th>Typ</th><th>Info</th></tr></thead>
        <tbody>
        <?php if (empty($events)): ?>
            <tr><td colspan="4">Keine Ereignisse in diesem Monat.</td></tr>
        <?php else: foreach ($events as $ev): ?>
            <tr>
                <td><?= e(formatDate($ev['d'])) ?></td>
                <td><?= e(formatEarTag($earTags[(int) $ev['cattle_id']] ?? '')) ?></td>
                <td><?= e($ev['typ']) ?></td>
                <td><?= e($ev['info']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</body></html>
<?php
$html = ob_get_clean();

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists('Dompdf\\Dompdf')) {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('kalender_' . $year . '_' . $month . '.pdf', ['Attachment' => true]);
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
echo '<script>window.print();</script>';
