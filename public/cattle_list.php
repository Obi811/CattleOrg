<?php
/**
 * public/cattle_list.php
 * -----------------------------------------------------------------------------
 * Tierliste des aktuellen Betriebs mit Filtern (Status, Rasse, Suche) und
 * Pagination. Alle Abfragen sind strikt auf farm_id beschränkt.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.read');
$farmId = getCurrentFarmId();

// ---- Filter / Pagination einlesen ----
$status     = $_GET['status'] ?? 'Aktiv';
$rasse      = trim($_GET['rasse'] ?? '');
$q          = trim($_GET['q'] ?? '');
$geschlecht = trim($_GET['geschlecht'] ?? '');
$minAge     = trim($_GET['min_age'] ?? '');   // Alter in Monaten (mind.)
$maxAge     = trim($_GET['max_age'] ?? '');   // Alter in Monaten (max.)
$stall      = trim($_GET['stall'] ?? '');     // aktueller Stall (offene Stallhistorie)
$hasCalving = isset($_GET['has_calving']) && $_GET['has_calving'] === '1';
$insemFrom  = trim($_GET['insem_from'] ?? ''); // Besamung von
$insemTo    = trim($_GET['insem_to'] ?? '');   // Besamung bis
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Stall-Auswahl (aktive Ställe des Betriebs)
$stallOptions = getStallList($db, $farmId);

// ---- Dynamische, parametrisierte WHERE-Bedingung (Alias c = cattle) ----
$where = ['c.farm_id = :farm_id'];
$params = [':farm_id' => $farmId];

if ($status === 'Aktiv' || $status === 'Inaktiv') {
    $where[] = 'c.status = :status';
    $params[':status'] = $status;
}
if ($rasse !== '') {
    $where[] = 'c.rasse = :rasse';
    $params[':rasse'] = $rasse;
}
if ($geschlecht === 'W' || $geschlecht === 'M') {
    $where[] = 'c.geschlecht = :geschlecht';
    $params[':geschlecht'] = $geschlecht;
}
if ($q !== '') {
    // Freitextsuche ausschließlich in der Ohrmarke des Tieres (nicht Mutter).
    $where[] = 'c.ear_tag LIKE :q';
    $params[':q'] = '%' . $q . '%';
}
// Alter in Monaten -> über Geburtsdatum
if ($minAge !== '' && ctype_digit($minAge)) {
    $where[] = 'c.birth_date IS NOT NULL AND TIMESTAMPDIFF(MONTH, c.birth_date, CURDATE()) >= :min_age';
    $params[':min_age'] = (int) $minAge;
}
if ($maxAge !== '' && ctype_digit($maxAge)) {
    $where[] = 'c.birth_date IS NOT NULL AND TIMESTAMPDIFF(MONTH, c.birth_date, CURDATE()) <= :max_age';
    $params[':max_age'] = (int) $maxAge;
}
// Aktueller Stall (offene Stallhistorie: end_date IS NULL)
if ($stall !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM stall_history sh WHERE sh.cattle_id = c.id AND sh.farm_id = c.farm_id AND sh.end_date IS NULL AND sh.stall_name = :stall)';
    $params[':stall'] = $stall;
}
// Hat bereits gekalbt: lebendes Kalb (Mutter über mutter_id ODER Ohrmarke)
// ODER eine erfasste Totgeburt. Gleiche Logik wie die Kennzahl "Milchkühe".
if ($hasCalving) {
    $where[] = '('
        . 'EXISTS (SELECT 1 FROM cattle k WHERE k.farm_id = c.farm_id AND k.id <> c.id'
        . '        AND (k.mutter_id = c.id OR (k.mutter IS NOT NULL AND k.mutter <> "" AND k.mutter = c.ear_tag)))'
        . ' OR EXISTS (SELECT 1 FROM stillbirths s WHERE s.farm_id = c.farm_id AND s.mother_cattle_id = c.id)'
        . ')';
}
// Besamungsdatum-Bereich
if ($insemFrom !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM inseminations i WHERE i.cattle_id = c.id AND i.farm_id = c.farm_id AND i.date >= :insem_from)';
    $params[':insem_from'] = $insemFrom;
}
if ($insemTo !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM inseminations i WHERE i.cattle_id = c.id AND i.farm_id = c.farm_id AND i.date <= :insem_to)';
    $params[':insem_to'] = $insemTo;
}
$whereSql = implode(' AND ', $where);

// ---- Gesamtanzahl ----
$countStmt = $db->prepare("SELECT COUNT(*) FROM cattle c WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

// ---- Datensätze (LIMIT/OFFSET sicher als int gebunden) ----
$sql = "SELECT c.id, c.ear_tag, c.birth_date, c.status, c.rasse, c.geschlecht, c.mutter
        FROM cattle c WHERE $whereSql
        ORDER BY c.ear_tag
        LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$cattle = $stmt->fetchAll();

/** Baut Query-Strings für Pagination-Links unter Beibehaltung der Filter. */
function pageUrl(int $p): string
{
    $params = $_GET;
    $params['page'] = $p;
    return url('cattle_list.php') . '?' . http_build_query($params);
}

$pageTitle = 'Tiere';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Tiere <span class="badge bg-secondary"><?= $total ?></span></h1>
    <div class="d-flex gap-2">
        <?php if (hasPermission('export.access')): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('exports/export_cattle_pdf.php')) ?>?<?= e(http_build_query($_GET)) ?>">
                <i class="bi bi-file-earmark-pdf"></i> PDF
            </a>
        <?php endif; ?>
        <?php if (hasPermission('cattle.write')): ?>
            <a class="btn btn-success btn-sm" href="<?= e(url('cattle_add.php')) ?>"><i class="bi bi-plus-circle"></i> Tier anlegen</a>
        <?php endif; ?>
    </div>
</div>

<?php
// Sind erweiterte Filter aktiv? Dann Bereich aufgeklappt anzeigen.
$erweitertAktiv = ($geschlecht !== '' || $minAge !== '' || $maxAge !== '' || $stall !== '' || $hasCalving || $insemFrom !== '' || $insemTo !== '');
?>
<form class="card card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label mb-0">Suche (Ohrmarke)</label>
            <input type="text" name="q" value="<?= e($q) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
            <label class="form-label mb-0">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Alle</option>
                <option value="Aktiv"   <?= $status === 'Aktiv' ? 'selected' : '' ?>>Aktiv</option>
                <option value="Inaktiv" <?= $status === 'Inaktiv' ? 'selected' : '' ?>>Inaktiv</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label mb-0">Geschlecht</label>
            <select name="geschlecht" class="form-select form-select-sm">
                <option value="">Alle</option>
                <option value="W" <?= $geschlecht === 'W' ? 'selected' : '' ?>>Weiblich</option>
                <option value="M" <?= $geschlecht === 'M' ? 'selected' : '' ?>>Männlich</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> Filtern</button>
        </div>
    </div>

    <div class="mt-2">
        <a class="small text-decoration-none" data-bs-toggle="collapse" href="#erweiterteFilter" role="button" aria-expanded="<?= $erweitertAktiv ? 'true' : 'false' ?>">
            <i class="bi bi-sliders"></i> Erweiterte Filter <?= $erweitertAktiv ? '(aktiv)' : '' ?>
        </a>
    </div>
    <div class="collapse <?= $erweitertAktiv ? 'show' : '' ?>" id="erweiterteFilter">
        <div class="row g-2 align-items-end mt-1">
            <div class="col-md-4">
                <label class="form-label mb-0">Rasse</label>
                <select name="rasse" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach (cattleRaces() as $r): ?>
                        <option value="<?= e($r) ?>" <?= $rasse === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label mb-0">Aktueller Stall</label>
                <select name="stall" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach ($stallOptions as $s): ?>
                        <option value="<?= e($s) ?>" <?= $stall === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-0">Alter min. (Mon.)</label>
                <input type="number" min="0" name="min_age" value="<?= e($minAge) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-0">Alter max. (Mon.)</label>
                <input type="number" min="0" name="max_age" value="<?= e($maxAge) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-0">Besamung von</label>
                <input type="date" name="insem_from" value="<?= e($insemFrom) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-0">Besamung bis</label>
                <input type="date" name="insem_to" value="<?= e($insemTo) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 pt-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="has_calving" value="1" id="hasCalving" <?= $hasCalving ? 'checked' : '' ?>>
                    <label class="form-check-label" for="hasCalving">Nur Tiere mit Kalbung</label>
                </div>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> Filtern</button>
                <a href="<?= e(url('cattle_list.php')) ?>" class="btn btn-outline-secondary btn-sm">Zurücksetzen</a>
            </div>
        </div>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-striped table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Ohrmarke</th><th>Geburtsdatum</th><th>Alter</th>
                <th>Rasse</th><th>Geschl.</th><th>Status</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($cattle)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Keine Tiere gefunden.</td></tr>
            <?php else: ?>
                <?php foreach ($cattle as $c): ?>
                    <tr>
                        <td><?= cattleLink((int) $c['id'], $c['ear_tag']) ?></td>
                        <td><?= e(formatDate($c['birth_date'])) ?></td>
                        <td><?= e(calcAge($c['birth_date'])) ?></td>
                        <td><small><?= e($c['rasse']) ?></small></td>
                        <td><?= e($c['geschlecht']) ?></td>
                        <td>
                            <span class="badge bg-<?= $c['status'] === 'Aktiv' ? 'success' : 'secondary' ?>">
                                <?= e($c['status']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('cattle_show.php')) ?>?id=<?= (int) $c['id'] ?>"><i class="bi bi-eye"></i></a>
                            <?php if (hasPermission('cattle.write')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('cattle_edit.php')) ?>?id=<?= (int) $c['id'] ?>"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e(pageUrl(max(1, $page - 1))) ?>">Zurück</a>
        </li>
        <li class="page-item disabled"><span class="page-link">Seite <?= $page ?> / <?= $totalPages ?></span></li>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e(pageUrl(min($totalPages, $page + 1))) ?>">Weiter</a>
        </li>
    </ul>
</nav>
<?php endif; ?>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
