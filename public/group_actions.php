<?php
/**
 * public/group_actions.php
 * -----------------------------------------------------------------------------
 * Gruppenaktionen pro Box/Stall: Eine Box auswählen, die darin befindlichen
 * aktiven Tiere sehen (einzelne abwählbar) und dann eine Aktion für die Auswahl
 * ausführen:
 *   - umziehen        : alle in eine andere Box umstallen (wie Einzel-Stallwechsel)
 *   - inaktivieren    : alle als Abgang melden (Abgangsdatum + Status Inaktiv)
 *   - treat_now       : für alle eine Behandlung erfassen (Tabelle treatments)
 *   - treat_plan      : für alle eine Behandlung planen (Tabelle planned_treatments)
 *
 * Strikt betriebsbezogen, CSRF-geschützt, Transaktion je Aktion, Demo-Betrieb
 * schreibgeschützt. Umziehen/Inaktivieren erfordern cattle.write, Behandlungen
 * zusätzlich treatments.write.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/planned_treatments.php';

requireLogin();
requirePermission('cattle.write');
$farmId = getCurrentFarmId();

$canWrite     = hasPermission('cattle.write')     && !isDemoBetrieb();
$canTreat     = hasPermission('treatments.write') && !isDemoBetrieb();

$stalls = getStallList($db, $farmId);
$meds   = $db->query('SELECT name FROM medications ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

// Aktuell gewählte Box
$selectedStall = trim((string) ($_GET['stall'] ?? $_POST['stall'] ?? ''));
if ($selectedStall !== '' && !in_array($selectedStall, $stalls, true)) {
    $selectedStall = '';
}

$errors = [];

/**
 * Lädt die aktiven Tiere, die aktuell in der angegebenen Box sind
 * (offene stall_history-Zeile mit stall_name = Box).
 */
function loadAnimalsInStall(PDO $db, int $farmId, string $stall): array
{
    $sql = "SELECT c.id, c.ear_tag
            FROM cattle c
            JOIN stall_history sh ON sh.cattle_id = c.id AND sh.farm_id = c.farm_id
                 AND sh.end_date IS NULL AND sh.stall_name = :s
            WHERE c.farm_id = :f AND c.status = 'Aktiv'
            ORDER BY c.ear_tag";
    $st = $db->prepare($sql);
    $st->execute([':f' => $farmId, ':s' => $stall]);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['group_action'])) {
    requireCsrf();

    $action    = (string) $_POST['group_action'];
    $cattleIds = array_values(array_unique(array_map('intval', (array) ($_POST['cattle_ids'] ?? []))));

    // Nur Tiere akzeptieren, die wirklich aktiv, betriebszugehörig und in der Box sind
    $validIds = [];
    if ($selectedStall !== '' && !empty($cattleIds)) {
        $inBox = array_column(loadAnimalsInStall($db, $farmId, $selectedStall), 'id');
        $inBox = array_map('intval', $inBox);
        $validIds = array_values(array_intersect($cattleIds, $inBox));
    }

    if (empty($validIds)) {
        $errors[] = 'Bitte mindestens ein Tier aus der Box auswählen.';
    }

    if (empty($errors)) {
        // --- Umziehen -------------------------------------------------------
        if ($action === 'move') {
            $target = trim((string) ($_POST['target_stall'] ?? ''));
            $date   = trim((string) ($_POST['move_date'] ?? '')) ?: date('Y-m-d');
            if (!$canWrite) {
                $errors[] = 'Keine Berechtigung zum Umstallen.';
            } elseif ($target === '' || !in_array($target, $stalls, true)) {
                $errors[] = 'Bitte eine gültige Ziel-Box wählen.';
            } elseif ($target === $selectedStall) {
                $errors[] = 'Ziel-Box entspricht der aktuellen Box.';
            } else {
                $db->beginTransaction();
                try {
                    $close = $db->prepare('UPDATE stall_history SET end_date = :d WHERE cattle_id = :c AND farm_id = :f AND end_date IS NULL');
                    $ins   = $db->prepare('INSERT INTO stall_history (farm_id, cattle_id, stall_name, start_date) VALUES (:f, :c, :s, :d)');
                    foreach ($validIds as $cid) {
                        $close->execute([':d' => $date, ':c' => $cid, ':f' => $farmId]);
                        $ins->execute([':f' => $farmId, ':c' => $cid, ':s' => $target, ':d' => $date]);
                    }
                    $db->commit();
                    flash('success', count($validIds) . ' Tier(e) in die Box „' . $target . '" umgestallt.', 'success');
                    redirect(url('group_actions.php') . '?stall=' . urlencode($target));
                } catch (Throwable $ex) {
                    $db->rollBack();
                    $errors[] = 'Umstallen fehlgeschlagen.';
                }
            }
        }
        // --- Inaktivieren (Abgang) -----------------------------------------
        elseif ($action === 'inactivate') {
            $date  = trim((string) ($_POST['abgang_date'] ?? '')) ?: date('Y-m-d');
            $grund = trim((string) ($_POST['abgang_grund'] ?? ''));
            if (!$canWrite) {
                $errors[] = 'Keine Berechtigung für den Abgang.';
            } else {
                $db->beginTransaction();
                try {
                    $upd   = $db->prepare('UPDATE cattle SET abgang = :d, status = :s, abgang_grund = :g WHERE id = :id AND farm_id = :f');
                    $close = $db->prepare('UPDATE stall_history SET end_date = :d WHERE cattle_id = :c AND farm_id = :f AND end_date IS NULL');
                    foreach ($validIds as $cid) {
                        $upd->execute([':d' => $date, ':s' => 'Inaktiv', ':g' => ($grund !== '' ? $grund : null), ':id' => $cid, ':f' => $farmId]);
                        $close->execute([':d' => $date, ':c' => $cid, ':f' => $farmId]);
                    }
                    $db->commit();
                    flash('success', count($validIds) . ' Tier(e) als Abgang gemeldet (Status Inaktiv).', 'success');
                    redirect(url('group_actions.php'));
                } catch (Throwable $ex) {
                    $db->rollBack();
                    $errors[] = 'Abgang fehlgeschlagen.';
                }
            }
        }
        // --- Behandlung erfassen -------------------------------------------
        elseif ($action === 'treat_now') {
            $date = trim((string) ($_POST['treat_date'] ?? '')) ?: date('Y-m-d');
            $med  = trim((string) ($_POST['medication'] ?? ''));
            $type = trim((string) ($_POST['type'] ?? 'Sonstige'));
            $dose = trim((string) ($_POST['dosage'] ?? ''));
            $note = trim((string) ($_POST['notes'] ?? ''));
            if (!$canTreat) {
                $errors[] = 'Keine Berechtigung zum Erfassen von Behandlungen.';
            } elseif ($med === '' || !in_array($med, $meds, true)) {
                $errors[] = 'Bitte ein gültiges Medikament aus dem Katalog wählen.';
            } else {
                if (!in_array($type, treatmentTypes(), true)) { $type = 'Sonstige'; }
                $db->beginTransaction();
                try {
                    $ins = $db->prepare('INSERT INTO treatments (farm_id, cattle_id, treatment_date, medication, dosage, type, notes) VALUES (:f, :c, :d, :m, :dos, :t, :n)');
                    foreach ($validIds as $cid) {
                        $ins->execute([':f' => $farmId, ':c' => $cid, ':d' => $date, ':m' => $med, ':dos' => ($dose !== '' ? $dose : null), ':t' => $type, ':n' => ($note !== '' ? $note : null)]);
                    }
                    $db->commit();
                    flash('success', 'Behandlung für ' . count($validIds) . ' Tier(e) erfasst.', 'success');
                    redirect(url('group_actions.php') . '?stall=' . urlencode($selectedStall));
                } catch (Throwable $ex) {
                    $db->rollBack();
                    $errors[] = 'Behandlung fehlgeschlagen.';
                }
            }
        }
        // --- Behandlung planen ---------------------------------------------
        elseif ($action === 'treat_plan') {
            $date = trim((string) ($_POST['plan_date'] ?? ''));
            $med  = trim((string) ($_POST['plan_medication'] ?? ''));
            $type = trim((string) ($_POST['plan_type'] ?? 'Sonstige'));
            $dose = trim((string) ($_POST['plan_dosage'] ?? ''));
            $note = trim((string) ($_POST['plan_notes'] ?? ''));
            if (!$canTreat) {
                $errors[] = 'Keine Berechtigung zum Planen von Behandlungen.';
            } elseif ($med === '' || !in_array($med, $meds, true)) {
                $errors[] = 'Bitte ein gültiges Medikament aus dem Katalog wählen.';
            } elseif ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $errors[] = 'Bitte ein gültiges geplantes Datum angeben.';
            } else {
                $ok = 0; $err = null;
                $db->beginTransaction();
                try {
                    foreach ($validIds as $cid) {
                        $pid = createPlannedTreatment($db, $farmId, $cid, $date, $med, $type, $dose, $note, currentUserId(), $err);
                        if ($pid !== null) { $ok++; }
                    }
                    $db->commit();
                    flash('success', 'Behandlung für ' . $ok . ' Tier(e) geplant.', 'success');
                    redirect(url('group_actions.php') . '?stall=' . urlencode($selectedStall));
                } catch (Throwable $ex) {
                    $db->rollBack();
                    $errors[] = 'Planen fehlgeschlagen.';
                }
            }
        }
    }
}

// Tiere der gewählten Box für die Anzeige laden
$animals = ($selectedStall !== '') ? loadAnimalsInStall($db, $farmId, $selectedStall) : [];

$pageTitle = 'Gruppenaktionen';
require_once __DIR__ . '/../templates/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-grid-3x3-gap"></i> Gruppenaktionen pro Box</h1>

<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<?php if (empty($stalls)): ?>
    <div class="alert alert-warning">Für diesen Betrieb sind noch keine Ställe/Boxen hinterlegt.</div>
<?php endif; ?>

<!-- Box auswählen -->
<div class="card mb-3"><div class="card-body">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-sm-8">
            <label class="form-label mb-0">Box / Stall wählen</label>
            <select name="stall" class="form-select" onchange="this.form.submit()">
                <option value="">-- Box wählen --</option>
                <?php foreach ($stalls as $s): ?>
                    <option value="<?= e($s) ?>"<?= $s === $selectedStall ? ' selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-sm-4">
            <button class="btn btn-outline-primary w-100"><i class="bi bi-search"></i> Anzeigen</button>
        </div>
    </form>
</div></div>

<?php if ($selectedStall !== ''): ?>
    <?php if (empty($animals)): ?>
        <div class="alert alert-info">In der Box „<?= e($selectedStall) ?>" befinden sich aktuell keine aktiven Tiere.</div>
    <?php else: ?>
    <form method="post" id="groupForm">
        <?= csrfField() ?>
        <input type="hidden" name="stall" value="<?= e($selectedStall) ?>">

        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Tiere in Box „<?= e($selectedStall) ?>" (<?= count($animals) ?>)</h2>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" onclick="document.querySelectorAll('.cb-tier').forEach(c=>c.checked=true)">Alle</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="document.querySelectorAll('.cb-tier').forEach(c=>c.checked=false)">Keine</button>
                </div>
            </div>
            <div class="row g-2">
                <?php foreach ($animals as $a): ?>
                    <div class="col-12 col-sm-6 col-md-4">
                        <div class="form-check">
                            <input class="form-check-input cb-tier" type="checkbox" name="cattle_ids[]" value="<?= (int) $a['id'] ?>" id="cb<?= (int) $a['id'] ?>" checked>
                            <label class="form-check-label" for="cb<?= (int) $a['id'] ?>"><?= e(formatEarTag($a['ear_tag'])) ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="small text-muted mt-2 mb-0">Die Aktion wird nur auf die angehakten Tiere angewendet.</p>
        </div></div>

        <!-- Umziehen -->
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6"><i class="bi bi-house-door"></i> Alle ausgewählten umstallen</h2>
            <div class="row g-2 align-items-end">
                <div class="col-12 col-sm-6">
                    <label class="form-label mb-0 small">Ziel-Box</label>
                    <select name="target_stall" class="form-select">
                        <option value="">-- Ziel-Box wählen --</option>
                        <?php foreach ($stalls as $s): if ($s === $selectedStall) continue; ?>
                            <option value="<?= e($s) ?>"><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Datum</label>
                    <input type="date" name="move_date" value="<?= e(date('Y-m-d')) ?>" class="form-control">
                </div>
                <div class="col-6 col-sm-3">
                    <button name="group_action" value="move" class="btn btn-primary w-100"
                        onclick="return confirmGroup('umstallen');"><i class="bi bi-arrow-right"></i> Umstallen</button>
                </div>
            </div>
        </div></div>

        <!-- Inaktivieren -->
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6"><i class="bi bi-box-arrow-right"></i> Alle ausgewählten als Abgang melden (Inaktiv)</h2>
            <div class="row g-2 align-items-end">
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Abgangsdatum</label>
                    <input type="date" name="abgang_date" value="<?= e(date('Y-m-d')) ?>" class="form-control">
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label mb-0 small">Grund (optional)</label>
                    <input type="text" name="abgang_grund" class="form-control" placeholder="z. B. Verkauf, Schlachtung">
                </div>
                <div class="col-6 col-sm-3">
                    <button name="group_action" value="inactivate" class="btn btn-warning w-100"
                        onclick="return confirmGroup('als Abgang melden (Inaktiv)');"><i class="bi bi-box-arrow-right"></i> Abgang</button>
                </div>
            </div>
        </div></div>

        <?php if ($canTreat): ?>
        <!-- Behandlung erfassen -->
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6"><i class="bi bi-capsule"></i> Behandlung für alle ausgewählten erfassen</h2>
            <div class="row g-2 align-items-end">
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Datum</label>
                    <input type="date" name="treat_date" value="<?= e(date('Y-m-d')) ?>" class="form-control">
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Medikament</label>
                    <select name="medication" class="form-select">
                        <option value="">-- wählen --</option>
                        <?php foreach ($meds as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Art</label>
                    <select name="type" class="form-select">
                        <?php foreach (treatmentTypes() as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Dosierung</label>
                    <input type="text" name="dosage" class="form-control">
                </div>
                <div class="col-12 col-sm-9">
                    <label class="form-label mb-0 small">Notiz</label>
                    <input type="text" name="notes" class="form-control">
                </div>
                <div class="col-12 col-sm-3">
                    <button name="group_action" value="treat_now" class="btn btn-success w-100"
                        onclick="return confirmGroup('behandeln (Behandlung erfassen)');"><i class="bi bi-check-lg"></i> Erfassen</button>
                </div>
            </div>
        </div></div>

        <!-- Behandlung planen -->
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6"><i class="bi bi-calendar-plus"></i> Behandlung für alle ausgewählten planen</h2>
            <div class="row g-2 align-items-end">
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Geplantes Datum</label>
                    <input type="date" name="plan_date" value="<?= e(date('Y-m-d')) ?>" class="form-control">
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Medikament</label>
                    <select name="plan_medication" class="form-select">
                        <option value="">-- wählen --</option>
                        <?php foreach ($meds as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Art</label>
                    <select name="plan_type" class="form-select">
                        <?php foreach (treatmentTypes() as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label mb-0 small">Dosierung</label>
                    <input type="text" name="plan_dosage" class="form-control">
                </div>
                <div class="col-12 col-sm-9">
                    <label class="form-label mb-0 small">Notiz</label>
                    <input type="text" name="plan_notes" class="form-control">
                </div>
                <div class="col-12 col-sm-3">
                    <button name="group_action" value="treat_plan" class="btn btn-outline-success w-100"
                        onclick="return confirmGroup('Behandlung planen');"><i class="bi bi-calendar-plus"></i> Planen</button>
                </div>
            </div>
        </div></div>
        <?php else: ?>
            <div class="alert alert-secondary small">Für das Erfassen/Planen von Behandlungen fehlt die Berechtigung <code>treatments.write</code> (oder Demo-Betrieb).</div>
        <?php endif; ?>
    </form>

    <script>
    function confirmGroup(aktion) {
        var n = document.querySelectorAll('.cb-tier:checked').length;
        if (n === 0) { alert('Bitte mindestens ein Tier auswählen.'); return false; }
        return confirm(n + ' Tier(e) ' + aktion + '?');
    }
    </script>
    <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
