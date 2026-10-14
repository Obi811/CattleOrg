<?php
/**
 * public/pending_pregnancy.php
 * -----------------------------------------------------------------------------
 * Offene Traechtigkeitskontrollen: Tiere, die besamt wurden, aber seit der
 * letzten Besamung noch keine Traechtigkeitskontrolle haben. Betriebsbezogen.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.read');
$farmId = getCurrentFarmId();

// Letzte Besamung je Tier ohne nachfolgende Kontrolle
$sql = "
    SELECT c.id, c.ear_tag, i.date AS last_insem, i.bull_name,
           DATEDIFF(CURDATE(), i.date) AS tage
    FROM cattle c
    JOIN inseminations i ON i.cattle_id = c.id AND i.farm_id = c.farm_id
    JOIN (
        SELECT cattle_id, MAX(date) AS maxdate
        FROM inseminations WHERE farm_id = :f1 GROUP BY cattle_id
    ) lm ON lm.cattle_id = i.cattle_id AND lm.maxdate = i.date
    WHERE c.farm_id = :f2 AND c.status = 'Aktiv'
      AND NOT EXISTS (
          SELECT 1 FROM pregnancy_checks p
          WHERE p.cattle_id = c.id AND p.farm_id = c.farm_id AND p.check_date >= i.date
      )
    ORDER BY i.date ASC
";
$stmt = $db->prepare($sql);
$stmt->execute([':f1' => $farmId, ':f2' => $farmId]);
$rows = $stmt->fetchAll();

$pageTitle = 'Offene Traechtigkeit';
require_once __DIR__ . '/../templates/header.php';
?>
<h1 class="h3 mb-3">Offene Traechtigkeitskontrollen <span class="badge bg-secondary"><?= count($rows) ?></span></h1>
<div class="table-responsive">
    <table class="table table-striped align-middle mb-0">
        <thead><tr><th>Ohrmarke</th><th>Letzte Besamung</th><th>Bulle</th><th>Tage seither</th><th></th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Keine offenen Kontrollen.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><a href="<?= e(url('cattle_show.php')) ?>?id=<?= (int) $r['id'] ?>"><?= e(formatEarTag($r['ear_tag'])) ?></a></td>
                    <td><?= e($r['last_insem']) ?></td>
                    <td><?= e($r['bull_name']) ?></td>
                    <td><span class="badge bg-<?= $r['tage'] > 42 ? 'warning text-dark' : 'secondary' ?>"><?= (int) $r['tage'] ?> Tage</span></td>
                    <td class="text-end">
                        <?php if (hasPermission('pregnancy.write')): ?>
                            <a class="btn btn-sm btn-outline-success" href="<?= e(url('add_pregnancy.php')) ?>?cattle_id=<?= (int) $r['id'] ?>">
                                <i class="bi bi-clipboard-check"></i> Kontrolle erfassen
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
