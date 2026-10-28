<?php
/**
 * exports/export_cattle_pdf.php
 * -----------------------------------------------------------------------------
 * PDF-Export der Tierliste. Behebt den Altlasten-Bug (Export verwendete nicht
 * existierende Spalten name/birthdate/owner) und nutzt die REALEN Spalten.
 * Strikt betriebsbezogen, export.access erforderlich.
 *
 * Nutzt dompdf, wenn via composer installiert. Fallback: HTML-Druckansicht.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

requireLogin();
requirePermission('export.access');
$farmId = getCurrentFarmId();

// Filter analog zur Tierliste
$status = $_GET['status'] ?? 'Aktiv';
$rasse  = trim($_GET['rasse'] ?? '');
$q      = trim($_GET['q'] ?? '');

$where = ['farm_id = :farm_id'];
$params = [':farm_id' => $farmId];
if ($status === 'Aktiv' || $status === 'Inaktiv') { $where[] = 'status = :status'; $params[':status'] = $status; }
if ($rasse !== '') { $where[] = 'rasse = :rasse'; $params[':rasse'] = $rasse; }
if ($q !== '') { $where[] = '(ear_tag LIKE :q1 OR mutter LIKE :q2)'; $params[':q1'] = '%' . $q . '%'; $params[':q2'] = '%' . $q . '%'; }
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT ear_tag, birth_date, rasse, geschlecht, mutter, status FROM cattle WHERE $whereSql ORDER BY ear_tag");
$stmt->execute($params);
$cattle = $stmt->fetchAll();

$farmName = $_SESSION['current_farm_name'] ?? 'Betrieb';
$generated = date('d.m.Y H:i');

// HTML für den PDF-/Druck-Inhalt bauen (REALE Spalten!)
ob_start();
?>
<!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    h1 { font-size: 16px; margin: 0 0 4px; }
    .meta { color: #666; font-size: 10px; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
    th { background: #eee; }
</style></head><body>
    <h1>Tierliste &ndash; <?= e($farmName) ?></h1>
    <div class="meta">Erstellt am <?= e($generated) ?> &middot; <?= count($cattle) ?> Tiere &middot; Filter-Status: <?= e($status ?: 'alle') ?></div>
    <table>
        <thead><tr><th>Ohrmarke</th><th>Geburtsdatum</th><th>Rasse</th><th>Geschlecht</th><th>Mutter</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($cattle as $c): ?>
            <tr>
                <td><?= e(formatEarTag($c['ear_tag'])) ?></td>
                <td><?= e($c['birth_date']) ?></td>
                <td><?= e($c['rasse']) ?></td>
                <td><?= e($c['geschlecht']) ?></td>
                <td><?= e(formatEarTag($c['mutter'])) ?></td>
                <td><?= e($c['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body></html>
<?php
$html = ob_get_clean();

// dompdf verwenden, falls vorhanden
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists('Dompdf\\Dompdf')) {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream('tierliste_' . date('Ymd') . '.pdf', ['Attachment' => true]);
        exit;
    }
}

// Fallback: HTML-Druckansicht (Browser -> "Als PDF speichern")
header('Content-Type: text/html; charset=utf-8');
echo $html;
echo '<script>window.print();</script>';
