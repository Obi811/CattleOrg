<?php
/**
 * public/cattle_abgang.php
 * -----------------------------------------------------------------------------
 * Schnellerfassung "Abgang melden": setzt Abgangs-/Verendungsdatum und setzt
 * das Tier automatisch auf den Status "Inaktiv". Strikt betriebsbezogen.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.write');
requireWriteAccess();
$farmId = getCurrentFarmId();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Tier streng betriebsbezogen laden
$stmt = $db->prepare('SELECT * FROM cattle WHERE id = :id AND farm_id = :f');
$stmt->execute([':id' => $id, ':f' => $farmId]);
$cattle = $stmt->fetch();
if (!$cattle) {
    http_response_code(404);
    flash('error', 'Tier nicht gefunden.', 'danger');
    redirect(url('cattle_list.php'));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $art       = $_POST['art'] ?? 'abgang'; // "abgang" (Verkauf/Umstallung) oder "verendung"
    $datum     = trim($_POST['datum'] ?? '');
    $grund     = trim($_POST['grund'] ?? '');

    if ($datum === '') {
        $errors[] = 'Bitte ein Datum angeben.';
    }

    if (empty($errors)) {
        // Notiz mit Grund an die vorhandenen Notizen anhaengen (falls Spalte vorhanden ist,
        // wird der Grund als Behandlungsnotiz gespeichert - hier nur informativ in Flash).
        if ($art === 'verendung') {
            $upd = $db->prepare(
                'UPDATE cattle SET verendung = :d, status = :s WHERE id = :id AND farm_id = :f'
            );
            $upd->execute([':d' => $datum, ':s' => 'Inaktiv', ':id' => $id, ':f' => $farmId]);
            $msg = 'Verendung gemeldet - Tier wurde auf "Inaktiv" gesetzt.';
        } else {
            $upd = $db->prepare(
                'UPDATE cattle SET abgang = :d, status = :s WHERE id = :id AND farm_id = :f'
            );
            $upd->execute([':d' => $datum, ':s' => 'Inaktiv', ':id' => $id, ':f' => $farmId]);
            $msg = 'Abgang gemeldet - Tier wurde auf "Inaktiv" gesetzt.';
        }

        // Offene Stallhistorie schliessen (aktuellen Stallaufenthalt beenden)
        $closeStall = $db->prepare(
            'UPDATE stall_history SET end_date = :d WHERE cattle_id = :id AND farm_id = :f AND end_date IS NULL'
        );
        $closeStall->execute([':d' => $datum, ':id' => $id, ':f' => $farmId]);

        if ($grund !== '') {
            $msg .= ' Grund: ' . $grund;
        }
        flash('success', $msg, 'success');
        redirect(url('cattle_show.php') . '?id=' . $id);
    }
}

$pageTitle = 'Abgang melden';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-6">
    <h1 class="h3 mb-3">Abgang melden &ndash; <?= e(formatEarTag($cattle['ear_tag'])) ?></h1>

    <div class="alert alert-info small">
        <i class="bi bi-info-circle"></i> Beim Melden eines Abgangs oder einer Verendung wird das Tier
        automatisch auf den Status <strong>Inaktiv</strong> gesetzt und der laufende Stallaufenthalt beendet.
    </div>

    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" class="card card-body">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="mb-3">
            <label class="form-label">Art des Abgangs *</label>
            <select name="art" class="form-select" id="artSelect">
                <option value="abgang">Abgang (Verkauf / Umstallung)</option>
                <option value="verendung">Verendung / Tod</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Datum *</label>
            <input type="date" name="datum" value="<?= e(date('Y-m-d')) ?>" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Grund / Bemerkung (optional)</label>
            <input type="text" name="grund" class="form-control" placeholder="z. B. Verkauf, Schlachtung, Krankheit">
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-warning"><i class="bi bi-box-arrow-right"></i> Abgang speichern</button>
            <a href="<?= e(url('cattle_show.php')) ?>?id=<?= $id ?>" class="btn btn-outline-secondary">Abbrechen</a>
        </div>
    </form>
</div></div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
