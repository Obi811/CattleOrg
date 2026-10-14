<?php
/**
 * public/todo_list.php
 * -----------------------------------------------------------------------------
 * Aufgaben-/Erinnerungsuebersicht ("Ueberblick" wie in der alten App):
 *   1. Weiterbehandlungen (Behandlungen vom Vortag heute fortfuehren)
 *   2. Hemmstoffproben faellig (Wartezeit abgelaufen, Probe noch offen)
 *   3. Hemmstoffproben "versendet" (Ergebnis ausstehend)
 *   4. Offene Traechtigkeitskontrollen (> 35 Tage seit Besamung)
 * Betriebsbezogen. Weiterbehandeln legt eine neue Behandlung fuer heute an.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('cattle.read');
$farmId = getCurrentFarmId();

$canWrite = hasPermission('treatments.write') && !isDemoBetrieb();

// --- POST: "Weiterbehandeln" (Behandlung vom Vortag heute erneut erfassen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'weiterbehandeln') {
    requireCsrf();
    if ($canWrite) {
        $treatmentId = (int) ($_POST['treatment_id'] ?? 0);
        // Ausgangsbehandlung streng betriebsbezogen laden
        $s = $db->prepare('SELECT * FROM treatments WHERE id = :id AND farm_id = :f');
        $s->execute([':id' => $treatmentId, ':f' => $farmId]);
        $orig = $s->fetch();
        if ($orig) {
            $ins = $db->prepare(
                'INSERT INTO treatments (farm_id, cattle_id, treatment_date, medication, dosage, type, notes)
                 VALUES (:f, :c, :d, :m, :dos, :t, :n)'
            );
            $ins->execute([
                ':f' => $farmId, ':c' => $orig['cattle_id'], ':d' => date('Y-m-d'),
                ':m' => $orig['medication'], ':dos' => $orig['dosage'],
                ':t' => $orig['type'], ':n' => $orig['notes'],
            ]);
            flash('success', 'Behandlung wurde fuer heute uebernommen.', 'success');
        }
    }
    redirect(url('todo_list.php'));
}

$gestern = (new DateTime('yesterday'))->format('Y-m-d');
$vor14   = (new DateTime('-14 days'))->format('Y-m-d');

// --- 1. Weiterbehandlungen: Behandlungen von gestern (ausser Trockenstellen) ---
$wsql = "SELECT t.id, t.cattle_id, t.treatment_date, t.type, t.medication, t.dosage, t.notes, c.ear_tag
         FROM treatments t
         JOIN cattle c ON c.id = t.cattle_id AND c.farm_id = t.farm_id
         WHERE t.farm_id = :f AND t.treatment_date = :g AND t.type <> 'Trockenstellen'
         ORDER BY c.ear_tag";
$ws = $db->prepare($wsql);
$ws->execute([':f' => $farmId, ':g' => $gestern]);
$weiter = $ws->fetchAll();

// Vorbehandlungen der letzten 14 Tage je Tier (Kontext-Anzeige)
$vorbehandlungen = [];
$cattleIds = array_unique(array_column($weiter, 'cattle_id'));
if (!empty($cattleIds)) {
    $ph = implode(',', array_fill(0, count($cattleIds), '?'));
    $vsql = "SELECT cattle_id, treatment_date, type, medication, dosage
             FROM treatments
             WHERE farm_id = ? AND cattle_id IN ($ph)
               AND treatment_date < ? AND treatment_date >= ?
             ORDER BY cattle_id, treatment_date DESC";
    $vs = $db->prepare($vsql);
    $vs->execute(array_merge([$farmId], array_values($cattleIds), [$gestern, $vor14]));
    foreach ($vs->fetchAll() as $r) {
        $vorbehandlungen[$r['cattle_id']][] = $r;
    }
}

// --- 2. Hemmstoffproben faellig: Wartezeit (wz_milk) abgelaufen, keine "frei"-Probe danach ---
$hsql = "
    SELECT c.id, c.ear_tag, t.treatment_date, m.wz_milk,
           DATE_ADD(t.treatment_date, INTERVAL COALESCE(m.wz_milk,0) DAY) AS wartezeit_ende
    FROM cattle c
    JOIN (SELECT cattle_id, MAX(treatment_date) AS last_td
          FROM treatments WHERE farm_id = :f1 GROUP BY cattle_id) lt ON lt.cattle_id = c.id
    JOIN treatments t ON t.cattle_id = c.id AND t.treatment_date = lt.last_td AND t.farm_id = :f2
    JOIN medications m ON m.name = t.medication
    WHERE c.farm_id = :f3 AND c.status = 'Aktiv'
      AND COALESCE(m.wz_milk,0) > 0
      AND DATE_ADD(t.treatment_date, INTERVAL COALESCE(m.wz_milk,0) DAY) <= CURDATE()
      AND NOT EXISTS (
          SELECT 1 FROM hemmstoff_probes hp
          WHERE hp.cattle_id = c.id AND hp.farm_id = c.farm_id
            AND hp.status = 'frei'
            AND hp.probe_date >= DATE_ADD(t.treatment_date, INTERVAL COALESCE(m.wz_milk,0) DAY)
      )
      AND NOT EXISTS (
          SELECT 1 FROM hemmstoff_probes hp2
          WHERE hp2.cattle_id = c.id AND hp2.farm_id = c.farm_id AND hp2.status = 'versendet'
      )
    ORDER BY wartezeit_ende ASC";
$hs = $db->prepare($hsql);
$hs->execute([':f1' => $farmId, ':f2' => $farmId, ':f3' => $farmId]);
$hemmFaellig = $hs->fetchAll();

// --- 3. Hemmstoffproben "versendet" (Ergebnis ausstehend) ---
$h = $db->prepare("SELECT hp.id, hp.nummer, hp.probe_date, c.ear_tag, c.id AS cattle_id
                   FROM hemmstoff_probes hp JOIN cattle c ON c.id = hp.cattle_id AND c.farm_id = hp.farm_id
                   WHERE hp.farm_id = :f AND hp.status = 'versendet' ORDER BY hp.probe_date ASC");
$h->execute([':f' => $farmId]);
$hemmVersendet = $h->fetchAll();

// --- 4. Offene Traechtigkeitskontrollen (> 35 Tage seit Besamung) ---
$psql = "
    SELECT c.id, c.ear_tag, i.date AS last_insem, DATEDIFF(CURDATE(), i.date) AS tage
    FROM cattle c
    JOIN inseminations i ON i.cattle_id = c.id AND i.farm_id = c.farm_id
    JOIN (SELECT cattle_id, MAX(date) maxdate FROM inseminations WHERE farm_id=:f1 GROUP BY cattle_id) lm
      ON lm.cattle_id=i.cattle_id AND lm.maxdate=i.date
    WHERE c.farm_id=:f2 AND c.status='Aktiv'
      AND DATEDIFF(CURDATE(), i.date) >= 35
      AND NOT EXISTS (SELECT 1 FROM pregnancy_checks p WHERE p.cattle_id=c.id AND p.farm_id=c.farm_id AND p.check_date >= i.date)
    ORDER BY i.date ASC";
$ps = $db->prepare($psql);
$ps->execute([':f1' => $farmId, ':f2' => $farmId]);
$traechtig = $ps->fetchAll();

$gesamt = count($weiter) + count($hemmFaellig) + count($hemmVersendet) + count($traechtig);

$pageTitle = 'Aufgaben';
require_once __DIR__ . '/../templates/header.php';
?>
<h1 class="h3 mb-3">Aufgaben &amp; Ueberblick <span class="badge bg-secondary"><?= $gesamt ?></span></h1>

<?php if ($gesamt === 0): ?>
    <div class="card"><div class="card-body">
        <p class="text-muted mb-0"><i class="bi bi-check2-circle text-success"></i> Aktuell keine offenen Aufgaben.</p>
    </div></div>
<?php endif; ?>

<!-- 1. Weiterbehandlungen -->
<?php if (!empty($weiter)): ?>
<div class="card mb-3">
    <div class="card-header bg-warning-subtle"><i class="bi bi-arrow-repeat"></i> Weiterbehandlungen (Behandlung von gestern) <span class="badge bg-warning text-dark"><?= count($weiter) ?></span></div>
    <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Ohrmarke</th><th>Medikament</th><th>Art</th><th>Dosis</th><th>Vorbehandlungen (14 T)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($weiter as $w): ?>
                <tr>
                    <td><a href="<?= e(url('cattle_show.php')) ?>?id=<?= (int) $w['cattle_id'] ?>"><?= e(formatEarTag($w['ear_tag'])) ?></a></td>
                    <td><?= e($w['medication']) ?></td>
                    <td><?= e($w['type']) ?></td>
                    <td><?= e($w['dosage']) ?></td>
                    <td><small class="text-muted">
                        <?php
                        $vb = $vorbehandlungen[$w['cattle_id']] ?? [];
                        if (empty($vb)) { echo '-'; }
                        else {
                            $parts = [];
                            foreach ($vb as $v) { $parts[] = e(date('d.m.', strtotime($v['treatment_date'])) . ' ' . $v['medication']); }
                            echo implode('<br>', $parts);
                        }
                        ?>
                    </small></td>
                    <td class="text-end">
                        <?php if ($canWrite): ?>
                        <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="weiterbehandeln">
                            <input type="hidden" name="treatment_id" value="<?= (int) $w['id'] ?>">
                            <button class="btn btn-success btn-sm" onclick="return confirm('Behandlung fuer heute uebernehmen?');"><i class="bi bi-check-lg"></i> Weiterbehandelt</button>
                        </form>
                        <?php endif; ?>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('add_treatment.php')) ?>?cattle_id=<?= (int) $w['cattle_id'] ?>"><i class="bi bi-pencil"></i> Anpassen</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<!-- 2. Hemmstoffproben faellig -->
<?php if (!empty($hemmFaellig)): ?>
<div class="card mb-3">
    <div class="card-header bg-info-subtle"><i class="bi bi-droplet"></i> Hemmstoffprobe faellig (Wartezeit abgelaufen) <span class="badge bg-info text-dark"><?= count($hemmFaellig) ?></span></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($hemmFaellig as $r): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-circle text-info"></i>
                    <strong><?= e(formatEarTag($r['ear_tag'])) ?></strong> &ndash; Wartezeit endete am <?= e(date('d.m.Y', strtotime($r['wartezeit_ende']))) ?>. Jetzt bitte Hemmstoffprobe schicken.
                </span>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('cattle_show.php')) ?>?id=<?= (int) $r['id'] ?>">Oeffnen</a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- 3. Hemmstoffproben versendet -->
<?php if (!empty($hemmVersendet)): ?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-hourglass"></i> Hemmstoffproben &ndash; Ergebnis ausstehend <span class="badge bg-secondary"><?= count($hemmVersendet) ?></span></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($hemmVersendet as $r): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-droplet text-info"></i> Probe <?= e($r['nummer']) ?> (<?= e(formatEarTag($r['ear_tag'])) ?>) &ndash; Ergebnis ausstehend</span>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('cattle_show.php')) ?>?id=<?= (int) $r['cattle_id'] ?>">Oeffnen</a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- 4. Offene Traechtigkeitskontrollen -->
<?php if (!empty($traechtig)): ?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-hourglass-split"></i> Offene Traechtigkeitskontrollen <span class="badge bg-secondary"><?= count($traechtig) ?></span></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($traechtig as $r): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-hourglass-split text-warning"></i> Traechtigkeitskontrolle faellig fuer <strong><?= e(formatEarTag($r['ear_tag'])) ?></strong> (<?= (int) $r['tage'] ?> Tage seit Besamung)</span>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('add_pregnancy.php')) ?>?cattle_id=<?= (int) $r['id'] ?>">Oeffnen</a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
