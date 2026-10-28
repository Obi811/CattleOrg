<?php
/**
 * public/cattle_add.php
 * -----------------------------------------------------------------------------
 * Neues Tier anlegen. Behebt Altlasten-Bugs:
 *   - korrekte Feldnamen (kein "geschstatuslecht"-Tippfehler)
 *   - CSRF-Schutz, Berechtigungs- und Demo-Prüfung
 *   - saubere Validierung, Ohrmarke wird normalisiert
 *   - farm_id wird serverseitig gesetzt (nicht aus dem Formular)
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.write');
requireWriteAccess();
$farmId = getCurrentFarmId();

$errors = [];
$form = [
    'ear_tag' => '', 'birth_date' => '', 'rasse' => '', 'geschlecht' => '',
    'mutter' => '', 'mehrling' => 'Nein', 'status' => 'Aktiv', 'zugang' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $form['ear_tag']    = normalizeEarTag($_POST['ear_tag'] ?? '');
    $form['birth_date'] = trim($_POST['birth_date'] ?? '');
    $form['rasse']      = trim($_POST['rasse'] ?? '');
    $form['geschlecht'] = trim($_POST['geschlecht'] ?? '');
    $form['mutter']     = normalizeEarTag($_POST['mutter'] ?? '');
    $form['mehrling']   = ($_POST['mehrling'] ?? 'Nein') === 'Ja' ? 'Ja' : 'Nein';
    $form['status']     = ($_POST['status'] ?? 'Aktiv') === 'Inaktiv' ? 'Inaktiv' : 'Aktiv';
    $form['zugang']     = trim($_POST['zugang'] ?? '');

    // Validierung
    if ($form['ear_tag'] === '') {
        $errors[] = 'Bitte eine Ohrmarkennummer eingeben.';
    }
    if ($form['rasse'] !== '' && !in_array($form['rasse'], cattleRaces(), true)) {
        $errors[] = 'Ungültige Rasse.';
    }
    if ($form['geschlecht'] !== '' && !in_array($form['geschlecht'], ['M', 'W'], true)) {
        $errors[] = 'Ungültiges Geschlecht.';
    }

    // Eindeutigkeit innerhalb des Betriebs prüfen
    if ($form['ear_tag'] !== '') {
        $chk = $db->prepare('SELECT COUNT(*) FROM cattle WHERE farm_id = :f AND ear_tag = :e');
        $chk->execute([':f' => $farmId, ':e' => $form['ear_tag']]);
        if ((int) $chk->fetchColumn() > 0) {
            $errors[] = 'Ein Tier mit dieser Ohrmarke existiert bereits in diesem Betrieb.';
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare(
            'INSERT INTO cattle (farm_id, ear_tag, birth_date, rasse, geschlecht, mutter, mehrling, status, zugang)
             VALUES (:f, :ear, :birth, :rasse, :geschlecht, :mutter, :mehrling, :status, :zugang)'
        );
        $stmt->execute([
            ':f' => $farmId,
            ':ear' => $form['ear_tag'],
            ':birth' => $form['birth_date'] !== '' ? $form['birth_date'] : null,
            ':rasse' => $form['rasse'] !== '' ? $form['rasse'] : null,
            ':geschlecht' => $form['geschlecht'] !== '' ? $form['geschlecht'] : null,
            ':mutter' => $form['mutter'] !== '' ? $form['mutter'] : null,
            ':mehrling' => $form['mehrling'],
            ':status' => $form['status'],
            ':zugang' => $form['zugang'] !== '' ? $form['zugang'] : null,
        ]);
        $newId = (int) $db->lastInsertId();
        flash('success', 'Tier wurde angelegt.', 'success');
        redirect(url('cattle_show.php') . '?id=' . $newId);
    }
}

$pageTitle = 'Tier anlegen';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="h3 mb-3">Neues Tier anlegen</h1>
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" class="card card-body">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Ohrmarke *</label>
                    <input type="text" name="ear_tag" value="<?= e($form['ear_tag']) ?>" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Geburtsdatum</label>
                    <input type="date" name="birth_date" value="<?= e($form['birth_date']) ?>" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rasse</label>
                    <select name="rasse" class="form-select">
                        <option value="">-- wählen --</option>
                        <?php foreach (cattleRaces() as $r): ?>
                            <option value="<?= e($r) ?>" <?= $form['rasse'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Geschlecht</label>
                    <select name="geschlecht" class="form-select">
                        <option value="">--</option>
                        <option value="W" <?= $form['geschlecht'] === 'W' ? 'selected' : '' ?>>Weiblich</option>
                        <option value="M" <?= $form['geschlecht'] === 'M' ? 'selected' : '' ?>>Männlich</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Mehrling</label>
                    <select name="mehrling" class="form-select">
                        <option value="Nein" <?= $form['mehrling'] === 'Nein' ? 'selected' : '' ?>>Nein</option>
                        <option value="Ja"   <?= $form['mehrling'] === 'Ja' ? 'selected' : '' ?>>Ja</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mutter (Ohrmarke)</label>
                    <input type="text" name="mutter" value="<?= e($form['mutter']) ?>" class="form-control" id="mutterInput" autocomplete="off">
                    <div id="mutterResults" class="list-group position-absolute" style="z-index:1000;"></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="Aktiv"   <?= $form['status'] === 'Aktiv' ? 'selected' : '' ?>>Aktiv</option>
                        <option value="Inaktiv" <?= $form['status'] === 'Inaktiv' ? 'selected' : '' ?>>Inaktiv</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Zugang</label>
                    <input type="date" name="zugang" value="<?= e($form['zugang']) ?>" class="form-control">
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-success"><i class="bi bi-check-lg"></i> Speichern</button>
                <a href="<?= e(url('cattle_list.php')) ?>" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
<script>
// Live-Suche für das Muttertier (mit Auth + farm_id serverseitig abgesichert)
const mutterInput = document.getElementById('mutterInput');
const mutterResults = document.getElementById('mutterResults');
let timer = null;
mutterInput.addEventListener('input', function () {
    clearTimeout(timer);
    const term = this.value.trim();
    if (term.length < 2) { mutterResults.innerHTML = ''; return; }
    timer = setTimeout(() => {
        fetch('<?= e(url('search_mutter.php')) ?>?q=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(data => {
                mutterResults.innerHTML = '';
                (data.results || []).forEach(item => {
                    const a = document.createElement('a');
                    a.href = '#';
                    a.className = 'list-group-item list-group-item-action';
                    a.textContent = item.ear_tag;
                    a.onclick = (e) => { e.preventDefault(); mutterInput.value = item.ear_tag; mutterResults.innerHTML = ''; };
                    mutterResults.appendChild(a);
                });
            });
    }, 250);
});
</script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
