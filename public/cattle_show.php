<?php
/**
 * public/cattle_show.php
 * -----------------------------------------------------------------------------
 * Detailansicht eines Tieres inkl. Behandlungen, Besamungen,
 * Trächtigkeitskontrollen und Stallhistorie. Strikt betriebsbezogen.
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

// --- POST-Handler: Hemmstoffproben (anlegen / Status aktualisieren) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (hasPermission('treatments.write') && !isDemoBetrieb()) {
        // Neue Hemmstoffprobe als "versendet" anlegen
        if (isset($_POST['action']) && $_POST['action'] === 'probe_add') {
            $nummer = trim($_POST['probe_nummer'] ?? '');
            $pdate  = trim($_POST['probe_date'] ?? '') ?: date('Y-m-d');
            if ($nummer !== '') {
                $ins = $db->prepare('INSERT INTO hemmstoff_probes (farm_id, cattle_id, probe_date, status, nummer) VALUES (:f, :c, :d, :s, :n)');
                $ins->execute([':f' => $farmId, ':c' => $id, ':d' => $pdate, ':s' => 'versendet', ':n' => $nummer]);
                flash('success', 'Hemmstoffprobe wurde als "versendet" gespeichert.', 'success');
            } else {
                flash('error', 'Bitte eine Probennummer angeben.', 'danger');
            }
            redirect(url('cattle_show.php') . '?id=' . $id);
        }
        // Status einer Hemmstoffprobe aktualisieren
        if (isset($_POST['action']) && $_POST['action'] === 'probe_status') {
            $probeId = (int) ($_POST['probe_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';
            if (in_array($newStatus, ['frei', 'nicht frei'], true)) {
                $upd = $db->prepare('UPDATE hemmstoff_probes SET status = :s WHERE id = :id AND farm_id = :f AND cattle_id = :c');
                $upd->execute([':s' => $newStatus, ':id' => $probeId, ':f' => $farmId, ':c' => $id]);
                flash('success', 'Status der Hemmstoffprobe aktualisiert.', 'success');
            }
            redirect(url('cattle_show.php') . '?id=' . $id);
        }
    }

    // --- Bemerkungen: anlegen / löschen (Berechtigung cattle.write) ---
    if (hasPermission('cattle.write') && !isDemoBetrieb()) {
        if (isset($_POST['action']) && $_POST['action'] === 'remark_add') {
            $rdate = trim($_POST['remark_date'] ?? '') ?: date('Y-m-d');
            $body  = trim($_POST['remark_body'] ?? '');
            if ($body !== '') {
                $ins = $db->prepare(
                    'INSERT INTO cattle_remarks (farm_id, cattle_id, remark_date, body, created_by)
                     VALUES (:f, :c, :d, :b, :u)'
                );
                $ins->execute([':f' => $farmId, ':c' => $id, ':d' => $rdate, ':b' => $body, ':u' => currentUserId()]);
                flash('success', 'Bemerkung gespeichert.', 'success');
            } else {
                flash('error', 'Bitte einen Text für die Bemerkung eingeben.', 'danger');
            }
            redirect(url('cattle_show.php') . '?id=' . $id);
        }
        if (isset($_POST['action']) && $_POST['action'] === 'remark_delete') {
            $remarkId = (int) ($_POST['remark_id'] ?? 0);
            $del = $db->prepare('DELETE FROM cattle_remarks WHERE id = :id AND farm_id = :f AND cattle_id = :c');
            $del->execute([':id' => $remarkId, ':f' => $farmId, ':c' => $id]);
            flash('success', 'Bemerkung gelöscht.', 'success');
            redirect(url('cattle_show.php') . '?id=' . $id);
        }
    }
}

// Zugehörige Daten (alle mit farm_id gefiltert)
// Behandlungen inkl. Wartezeiten (Milch/Fleisch) aus dem globalen Medikamentenkatalog
$tr = $db->prepare(
    'SELECT t.*, m.wz_milk, m.wz_fleisch
     FROM treatments t
     LEFT JOIN medications m ON m.name = t.medication
     WHERE t.cattle_id = :id AND t.farm_id = :f
     ORDER BY t.treatment_date DESC, t.id DESC'
);
$tr->execute([':id' => $id, ':f' => $farmId]);
$treatments = $tr->fetchAll();

// --- Wartezeit-Auswertung: läuft aktuell noch eine Wartezeit? ---
$warnhinweis = false;         // true, wenn irgendeine Wartezeit noch läuft
$warnEndeMax = null;          // spätestes Wartezeit-Ende (für Anzeige)
$today = new DateTime('today');
foreach ($treatments as &$t) {
    $t['wz_ende'] = null;      // Datum, bis zu dem eine Wartezeit läuft
    $t['wz_aktiv'] = false;    // läuft die Wartezeit dieser Behandlung noch?
    if (empty($t['treatment_date'])) {
        continue;
    }
    try {
        $bd  = new DateTime($t['treatment_date']);
    } catch (Exception $e) {
        continue;
    }
    $wzm = (int) ($t['wz_milk'] ?? 0);
    $wzf = (int) ($t['wz_fleisch'] ?? 0);
    $maxWz = max($wzm, $wzf);
    if ($maxWz > 0) {
        $ende = (clone $bd)->modify("+{$maxWz} days");
        $t['wz_ende'] = $ende->format('Y-m-d');
        if ($today < $ende) {
            $t['wz_aktiv'] = true;
            $warnhinweis = true;
            if ($warnEndeMax === null || $ende > $warnEndeMax) {
                $warnEndeMax = $ende;
            }
        }
    }
}
unset($t);

// Hemmstoffproben dieses Tieres (betriebsbezogen)
$hp = $db->prepare('SELECT * FROM hemmstoff_probes WHERE cattle_id = :id AND farm_id = :f ORDER BY probe_date DESC, id DESC');
$hp->execute([':id' => $id, ':f' => $farmId]);
$hemmstoffProbes = $hp->fetchAll();

$in = $db->prepare('SELECT * FROM inseminations WHERE cattle_id = :id AND farm_id = :f ORDER BY date DESC, id DESC');
$in->execute([':id' => $id, ':f' => $farmId]);
$inseminations = $in->fetchAll();

$pr = $db->prepare('SELECT * FROM pregnancy_checks WHERE cattle_id = :id AND farm_id = :f ORDER BY check_date DESC, id DESC');
$pr->execute([':id' => $id, ':f' => $farmId]);
$pregnancy = $pr->fetchAll();

$st = $db->prepare('SELECT * FROM stall_history WHERE cattle_id = :id AND farm_id = :f ORDER BY start_date DESC, id DESC');
$st->execute([':id' => $id, ':f' => $farmId]);
$stallHistory = $st->fetchAll();

// --- Kalbungen / Nachkommen ---
// Kalbungen sind in diesem System keine eigene Tabelle, sondern ergeben sich aus
// den Tieren, deren Mutter dieses Tier ist. Verknüpfung über mutter_id (FK auf
// cattle.id) ODER über die Ohrmarke (Feld mutter = ear_tag dieses Tieres).
// Deshalb wurden Kalbungen bisher auf der Profilseite nicht angezeigt – es fehlte
// schlicht diese Abfrage/Sektion. Beides betriebsbezogen.
$off = $db->prepare(
    'SELECT id, ear_tag, birth_date, geschlecht, rasse, mehrling
     FROM cattle
     WHERE farm_id = :f AND id <> :id2
       AND (mutter_id = :id OR (mutter IS NOT NULL AND mutter <> "" AND mutter = :eartag))
     ORDER BY birth_date DESC, id DESC'
);
$off->execute([':f' => $farmId, ':id' => $id, ':id2' => $id, ':eartag' => $cattle['ear_tag']]);
$offspring = $off->fetchAll();

// --- Bemerkungen (neueste zuerst) ---
$rm = $db->prepare(
    'SELECT r.id, r.remark_date, r.body, r.created_at, u.username AS author
     FROM cattle_remarks r
     LEFT JOIN users u ON u.id = r.created_by
     WHERE r.cattle_id = :id AND r.farm_id = :f
     ORDER BY r.remark_date DESC, r.id DESC'
);
$rm->execute([':id' => $id, ':f' => $farmId]);
$remarks = $rm->fetchAll();

$pageTitle = 'Tier ' . formatEarTag($cattle['ear_tag']);
require_once __DIR__ . '/../templates/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= e(formatEarTag($cattle['ear_tag'])) ?>
        <span class="badge bg-<?= $cattle['status'] === 'Aktiv' ? 'success' : 'secondary' ?>"><?= e($cattle['status']) ?></span>
    </h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('cattle_timeline.php')) ?>?id=<?= $id ?>"><i class="bi bi-clock-history"></i> Verlauf</a>
        <?php if (hasPermission('cattle.write')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('cattle_edit.php')) ?>?id=<?= $id ?>"><i class="bi bi-pencil"></i> Bearbeiten</a>
            <?php if ($cattle['status'] === 'Aktiv'): ?>
                <a class="btn btn-warning btn-sm" href="<?= e(url('cattle_abgang.php')) ?>?id=<?= $id ?>"><i class="bi bi-box-arrow-right"></i> Abgang melden</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($warnhinweis): ?>
<div class="alert alert-danger d-flex align-items-center" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
    <div>
        <strong>Achtung &ndash; Wartezeit!</strong>
        Dieses Tier befindet sich aktuell in der Wartezeit nach einer Behandlung.
        <?php if ($warnEndeMax): ?>
            Wartezeit-Ende: <strong><?= e($warnEndeMax->format('d.m.Y')) ?></strong>.
        <?php endif; ?>
        Milch/Fleisch dieses Tieres darf bis dahin nicht vermarktet werden.
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card"><div class="card-body">
            <h2 class="h6 text-muted">Stammdaten</h2>
            <dl class="row mb-0 small">
                <dt class="col-5">Geburtsdatum</dt><dd class="col-7"><?= e(formatDate($cattle['birth_date'], 'd.m.y', '-')) ?></dd>
                <dt class="col-5">Alter</dt><dd class="col-7"><?= e(calcAge($cattle['birth_date'])) ?></dd>
                <dt class="col-5">Rasse</dt><dd class="col-7"><?= e($cattle['rasse'] ?: '-') ?></dd>
                <dt class="col-5">Geschlecht</dt><dd class="col-7"><?= e($cattle['geschlecht'] ?: '-') ?></dd>
                <dt class="col-5">Mutter</dt><dd class="col-7"><?php
                    if (!empty($cattle['mutter_id'])) {
                        echo cattleLink((int) $cattle['mutter_id'], $cattle['mutter']);
                    } else {
                        echo e(formatEarTag($cattle['mutter']) ?: '-');
                    }
                ?></dd>
                <dt class="col-5">Mehrling</dt><dd class="col-7"><?= e($cattle['mehrling']) ?></dd>
                <dt class="col-5">Zugang</dt><dd class="col-7"><?= e(formatDate($cattle['zugang'], 'd.m.y', '-')) ?></dd>
                <dt class="col-5">Abgang</dt><dd class="col-7"><?= e(formatDate($cattle['abgang'], 'd.m.y', '-')) ?></dd>
                <dt class="col-5">Verendung</dt><dd class="col-7"><?= e(formatDate($cattle['verendung'], 'd.m.y', '-')) ?></dd>
            </dl>
        </div></div>
        <?php if (hasPermission('cattle.write')): ?>
        <div class="card mt-3"><div class="card-body d-grid gap-2">
            <a class="btn btn-outline-success btn-sm" href="<?= e(url('add_treatment.php')) ?>?cattle_id=<?= $id ?>"><i class="bi bi-capsule"></i> Behandlung</a>
            <a class="btn btn-outline-success btn-sm" href="<?= e(url('insemination_add.php')) ?>?cattle_id=<?= $id ?>"><i class="bi bi-gender-female"></i> Besamung</a>
            <a class="btn btn-outline-success btn-sm" href="<?= e(url('add_pregnancy.php')) ?>?cattle_id=<?= $id ?>"><i class="bi bi-clipboard-check"></i> Trächtigkeitskontrolle</a>
            <a class="btn btn-outline-success btn-sm" href="<?= e(url('change_stall.php')) ?>?cattle_id=<?= $id ?>"><i class="bi bi-house-door"></i> Stallwechsel</a>
        </div></div>
        <?php endif; ?>
    </div>

    <div class="col-md-8">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Behandlungen (<?= count($treatments) ?>)</h2>
            <?php if (empty($treatments)): ?><p class="text-muted small mb-0">Keine Behandlungen.</p><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Datum</th><th>Medikament</th><th>Art</th><th>Dosis</th><th title="Wartezeit Milch (Tage)">WZ&nbsp;Milch</th><th title="Wartezeit Fleisch (Tage)">WZ&nbsp;Fleisch</th><th>Wartezeit bis</th><th>Notiz</th></tr></thead>
                    <tbody><?php foreach ($treatments as $t): ?>
                        <tr<?= !empty($t['wz_aktiv']) ? ' class="table-danger"' : '' ?>>
                            <td><?= e(formatDate($t['treatment_date'])) ?></td>
                            <td><?= e($t['medication']) ?></td>
                            <td><?= e($t['type']) ?></td>
                            <td><?= e($t['dosage']) ?></td>
                            <td><?= $t['wz_milk'] !== null ? (int) $t['wz_milk'] : '-' ?></td>
                            <td><?= $t['wz_fleisch'] !== null ? (int) $t['wz_fleisch'] : '-' ?></td>
                            <td>
                                <?php if (!empty($t['wz_ende'])): ?>
                                    <?= e(formatDate($t['wz_ende'])) ?>
                                    <?php if (!empty($t['wz_aktiv'])): ?><span class="badge bg-danger">läuft</span><?php else: ?><span class="badge bg-success">frei</span><?php endif; ?>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td><small><?= e($t['notes']) ?></small></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Besamungen (<?= count($inseminations) ?>)</h2>
            <?php if (empty($inseminations)): ?><p class="text-muted small mb-0">Keine Besamungen.</p><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr><th>Datum</th><th>Bulle</th><th>Art</th></tr></thead>
                    <tbody><?php foreach ($inseminations as $i): ?>
                        <tr><td><?= e(formatDate($i['date'])) ?></td><td><?= e($i['bull_name']) ?></td><td><?= e($i['type']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Trächtigkeitskontrollen (<?= count($pregnancy) ?>)</h2>
            <?php if (empty($pregnancy)): ?><p class="text-muted small mb-0">Keine Kontrollen.</p><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr><th>Datum</th><th>Ergebnis</th><th>Kommentar</th></tr></thead>
                    <tbody><?php foreach ($pregnancy as $p): ?>
                        <tr><td><?= e(formatDate($p['check_date'])) ?></td><td><?= e($p['result']) ?></td><td><?= e($p['comment']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Kalbungen / Nachkommen (<?= count($offspring) ?>)</h2>
            <?php if (empty($offspring)): ?><p class="text-muted small mb-0">Keine Kalbungen / Nachkommen erfasst.</p><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr><th>Geburtsdatum</th><th>Kalb (Ohrmarke)</th><th>Geschlecht</th><th>Rasse</th><th>Mehrling</th></tr></thead>
                    <tbody><?php foreach ($offspring as $kalb): ?>
                        <tr>
                            <td><?= e(formatDate($kalb['birth_date'], 'd.m.y', '-')) ?></td>
                            <td><?= cattleLink((int) $kalb['id'], $kalb['ear_tag']) ?></td>
                            <td><?= e($kalb['geschlecht'] ?: '-') ?></td>
                            <td><?= e($kalb['rasse'] ?: '-') ?></td>
                            <td><?= e($kalb['mehrling']) ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Hemmstoffproben (<?= count($hemmstoffProbes) ?>)</h2>
            </div>
            <?php if (empty($hemmstoffProbes)): ?><p class="text-muted small mb-0">Keine Hemmstoffproben.</p><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Datum</th><th>Nummer</th><th>Status</th><?php if (hasPermission('treatments.write')): ?><th></th><?php endif; ?></tr></thead>
                    <tbody><?php foreach ($hemmstoffProbes as $probe): ?>
                        <tr>
                            <td><?= e(formatDate($probe['probe_date'])) ?></td>
                            <td><?= e($probe['nummer']) ?></td>
                            <td>
                                <?php
                                    $stBadge = $probe['status'] === 'frei' ? 'success' : ($probe['status'] === 'nicht frei' ? 'danger' : 'info');
                                ?>
                                <span class="badge bg-<?= $stBadge ?>"><?= e($probe['status']) ?></span>
                            </td>
                            <?php if (hasPermission('treatments.write')): ?>
                            <td class="text-end">
                                <?php if ($probe['status'] === 'versendet' && !isDemoBetrieb()): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="probe_status">
                                        <input type="hidden" name="probe_id" value="<?= (int) $probe['id'] ?>">
                                        <button name="new_status" value="frei" class="btn btn-outline-success btn-sm">frei</button>
                                        <button name="new_status" value="nicht frei" class="btn btn-outline-danger btn-sm">nicht frei</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
            <?php if (hasPermission('treatments.write') && !isDemoBetrieb()): ?>
                <form method="post" class="row g-2 align-items-end mt-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="probe_add">
                    <div class="col-5"><label class="form-label mb-0 small">Probennummer</label><input name="probe_nummer" class="form-control form-control-sm" required></div>
                    <div class="col-4"><label class="form-label mb-0 small">Datum</label><input type="date" name="probe_date" value="<?= e(date('Y-m-d')) ?>" class="form-control form-control-sm"></div>
                    <div class="col-3"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-droplet"></i> Probe senden</button></div>
                </form>
            <?php endif; ?>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Stallhistorie (<?= count($stallHistory) ?>)</h2>
            <?php if (empty($stallHistory)): ?><p class="text-muted small mb-0">Keine Einträge.</p><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr><th>Stall</th><th>Von</th><th>Bis</th></tr></thead>
                    <tbody><?php foreach ($stallHistory as $s): ?>
                        <tr><td><?= e($s['stall_name']) ?></td><td><?= e(formatDate($s['start_date'])) ?></td><td><?= e(formatDate($s['end_date'], 'd.m.y', 'aktuell')) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div></div>

        <!-- ================= Bemerkungen ================= -->
        <div class="card"><div class="card-body">
            <h2 class="h6">Bemerkungen (<?= count($remarks) ?>)</h2>
            <?php if (hasPermission('cattle.write') && !isDemoBetrieb()): ?>
                <form method="post" class="row g-2 align-items-end mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="remark_add">
                    <div class="col-12 col-sm-3">
                        <label class="form-label mb-0 small">Datum</label>
                        <input type="date" name="remark_date" value="<?= e(date('Y-m-d')) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-12 col-sm-7">
                        <label class="form-label mb-0 small">Bemerkung</label>
                        <input type="text" name="remark_body" class="form-control form-control-sm" maxlength="2000" placeholder="z. B. Beobachtung, Auffälligkeit ..." required>
                    </div>
                    <div class="col-12 col-sm-2">
                        <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-plus-lg"></i> Hinzufügen</button>
                    </div>
                </form>
            <?php endif; ?>
            <?php if (empty($remarks)): ?>
                <p class="text-muted small mb-0">Keine Bemerkungen.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($remarks as $r): ?>
                        <li class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div><?= nl2br(e($r['body'])) ?></div>
                                    <div class="small text-muted">
                                        <?= e(formatDate($r['remark_date'])) ?>
                                        <?= $r['author'] ? ' &middot; ' . e($r['author']) : '' ?>
                                    </div>
                                </div>
                                <?php if (hasPermission('cattle.write') && !isDemoBetrieb()): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="remark_delete">
                                        <input type="hidden" name="remark_id" value="<?= (int) $r['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm" title="Bemerkung löschen"
                                            onclick="return confirm('Diese Bemerkung wirklich löschen?');">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<a href="<?= e(url('cattle_list.php')) ?>" class="btn btn-link">&laquo; Zurück zur Liste</a>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
