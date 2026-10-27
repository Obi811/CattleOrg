<?php
/**
 * templates/task_cards.php
 * -----------------------------------------------------------------------------
 * Gemeinsame Darstellung der Aufgaben-Karten (Inline-Erfassung). Wird vom
 * Dashboard (index.php) und von der Aufgabenliste (todo_list.php) eingebunden.
 *
 * Erwartete Variablen (vom einbindenden Skript gesetzt):
 *   $nachbehandlungen, $vorbehandlungen, $hemmFaellig, $hemmSentToday,
 *   $hemmVersendet, $hemmResultToday, $traechtigOffen, $traechtigToday
 *   $canWrite (bool), $canPreg (bool), $heute (Y-m-d)
 */
if (!isset($nachbehandlungen)) { return; }
?>
<!-- ================= 1. Nachbehandlungen ================= -->
<?php if (!empty($nachbehandlungen)): ?>
<div class="card mb-4 shadow-sm">
    <div class="card-header bg-warning-subtle fw-semibold">
        <i class="bi bi-arrow-repeat"></i> Nachbehandlungen &ndash; heute nachbehandeln?
        <span class="badge bg-warning text-dark ms-1"><?= count($nachbehandlungen) ?></span>
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($nachbehandlungen as $w): ?>
            <li class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold"><?= cattleLink((int) $w['cattle_id'], $w['ear_tag']) ?></div>
                        <div class="small text-muted">
                            Gestern: <?= e($w['medication']) ?> (<?= e($w['type']) ?>)<?= $w['dosage'] ? ', ' . e($w['dosage']) : '' ?>
                        </div>
                        <?php $vb = $vorbehandlungen[$w['cattle_id']] ?? []; if (!empty($vb)): ?>
                            <div class="small text-muted mt-1">
                                Vorbehandlungen (14 T):
                                <?php
                                    $parts = [];
                                    foreach ($vb as $v) { $parts[] = e(date('d.m.', strtotime($v['treatment_date'])) . ' ' . $v['medication']); }
                                    echo implode(', ', $parts);
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canWrite): ?>
                    <div class="d-flex flex-wrap gap-1 justify-content-end">
                        <a class="btn btn-success btn-sm"
                           href="<?= e(url('add_treatment.php')) ?>?cattle_id=<?= (int) $w['cattle_id'] ?>&medication=<?= urlencode($w['medication']) ?>&dosage=<?= urlencode((string) $w['dosage']) ?>&type=<?= urlencode($w['type']) ?>">
                            <i class="bi bi-check-lg"></i> Ja, nachbehandeln
                        </a>
                        <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="weiterbehandeln">
                            <input type="hidden" name="treatment_id" value="<?= (int) $w['id'] ?>">
                            <button class="btn btn-outline-success btn-sm" title="Gestrige Behandlung 1:1 fuer heute uebernehmen"
                                onclick="return confirm('Gestrige Behandlung unveraendert fuer heute uebernehmen?');">
                                <i class="bi bi-copy"></i> Uebernehmen
                            </button>
                        </form>
                        <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="followup_dismiss">
                            <input type="hidden" name="treatment_id" value="<?= (int) $w['id'] ?>">
                            <button class="btn btn-outline-secondary btn-sm"
                                onclick="return confirm('Keine Nachbehandlung noetig? Vorschlag fuer heute ausblenden.');">
                                <i class="bi bi-x-lg"></i> Nicht noetig
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ================= 2. Hemmstoffprobe faellig ================= -->
<?php if (!empty($hemmFaellig) || !empty($hemmSentToday)): ?>
<div class="card mb-4 shadow-sm">
    <div class="card-header bg-info-subtle fw-semibold">
        <i class="bi bi-send"></i> Hemmstoffprobe wegschicken (Wartezeit abgelaufen)
        <span class="badge bg-info text-dark ms-1"><?= count($hemmFaellig) ?></span>
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($hemmFaellig as $r): ?>
            <li class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold"><?= cattleLink((int) $r['id'], $r['ear_tag']) ?></div>
                        <div class="small text-muted">Wartezeit endete am <?= e(date('d.m.Y', strtotime($r['wartezeit_ende']))) ?>. Bitte Probe wegschicken.</div>
                    </div>
                    <?php if ($canWrite): ?>
                    <form method="post" class="row g-1 align-items-end" style="max-width:320px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="hemm_sent">
                        <input type="hidden" name="cattle_id" value="<?= (int) $r['id'] ?>">
                        <div class="col-6">
                            <label class="form-label mb-0 small">Lfd. Nummer</label>
                            <input name="nummer" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label mb-0 small">Datum</label>
                            <input type="date" name="sent_date" value="<?= e($heute) ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 mt-1">
                            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-send"></i> Weggeschickt</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
        <?php foreach ($hemmSentToday as $r): ?>
            <li class="list-group-item list-group-item-light d-flex justify-content-between align-items-center">
                <span class="small">
                    <i class="bi bi-check2 text-success"></i>
                    <?= cattleLink((int) $r['cattle_id'], $r['ear_tag']) ?> &ndash;
                    heute weggeschickt, Nr. <strong><?= e($r['nummer']) ?></strong>
                    <span class="text-muted">(<?= e(date('H:i', strtotime($r['sent_at']))) ?> Uhr)</span>
                </span>
                <span class="badge bg-secondary">erledigt</span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ================= 3. Hemmstoffproben verschickt – Ergebnis ================= -->
<?php if (!empty($hemmVersendet) || !empty($hemmResultToday)): ?>
<div class="card mb-4 shadow-sm">
    <div class="card-header fw-semibold">
        <i class="bi bi-droplet"></i> Hemmstoffproben &ndash; Ergebnis erfassen
        <span class="badge bg-secondary ms-1"><?= count($hemmVersendet) ?></span>
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($hemmVersendet as $r): ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    <i class="bi bi-droplet text-info"></i>
                    Probe <strong><?= e($r['nummer']) ?></strong> (<?= cattleLink((int) $r['cattle_id'], $r['ear_tag']) ?>) &ndash; Ergebnis?
                </span>
                <?php if ($canWrite): ?>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="hemm_result">
                    <input type="hidden" name="probe_id" value="<?= (int) $r['id'] ?>">
                    <button name="new_status" value="frei" class="btn btn-success btn-sm"
                        onclick="return confirm('Ergebnis fuer Probe <?= e($r['nummer']) ?>: frei?');"><i class="bi bi-check-lg"></i> frei</button>
                    <button name="new_status" value="nicht frei" class="btn btn-danger btn-sm"
                        onclick="return confirm('Ergebnis fuer Probe <?= e($r['nummer']) ?>: nicht frei?');"><i class="bi bi-x-lg"></i> nicht frei</button>
                </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php foreach ($hemmResultToday as $r): ?>
            <li class="list-group-item list-group-item-light d-flex justify-content-between align-items-center">
                <span class="small">
                    <i class="bi bi-check2 text-success"></i>
                    Probe <strong><?= e($r['nummer']) ?></strong> (<?= cattleLink((int) $r['cattle_id'], $r['ear_tag']) ?>) &ndash;
                    Ergebnis heute: <strong><?= e($r['status']) ?></strong>
                    <span class="text-muted">
                        (<?= e(date('H:i', strtotime($r['result_at']))) ?> Uhr<?= $r['result_user'] ? ', ' . e($r['result_user']) : '' ?>)
                    </span>
                </span>
                <span class="badge bg-<?= $r['status'] === 'frei' ? 'success' : 'danger' ?>"><?= e($r['status']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ================= 4. Traechtigkeitskontrollen ================= -->
<?php if (!empty($traechtigOffen) || !empty($traechtigToday)): ?>
<div class="card mb-4 shadow-sm">
    <div class="card-header fw-semibold">
        <i class="bi bi-hourglass-split"></i> Traechtigkeitskontrollen
        <span class="badge bg-secondary ms-1"><?= count($traechtigOffen) ?></span>
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($traechtigOffen as $r): ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    <i class="bi bi-hourglass-split text-warning"></i>
                    <?= cattleLink((int) $r['id'], $r['ear_tag']) ?> &ndash; <?= (int) $r['tage'] ?> Tage seit Besamung. Ergebnis?
                </span>
                <?php if ($canPreg): ?>
                <div class="d-flex gap-1">
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="preg_result">
                        <input type="hidden" name="cattle_id" value="<?= (int) $r['id'] ?>">
                        <button name="result" value="+" class="btn btn-success btn-sm"
                            onclick="return confirm('Ergebnis: traechtig?');"><i class="bi bi-check-lg"></i> traechtig</button>
                        <button name="result" value="-" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Ergebnis: nicht traechtig?');"><i class="bi bi-x-lg"></i> nicht traechtig</button>
                    </form>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('add_pregnancy.php')) ?>?cattle_id=<?= (int) $r['id'] ?>" title="Mit Kommentar erfassen"><i class="bi bi-pencil"></i></a>
                </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php foreach ($traechtigToday as $r): ?>
            <li class="list-group-item list-group-item-light d-flex justify-content-between align-items-center">
                <span class="small">
                    <i class="bi bi-check2 text-success"></i>
                    <?= cattleLink((int) $r['cattle_id'], $r['ear_tag']) ?> &ndash;
                    Ergebnis heute:
                    <strong><?= $r['result'] === '+' ? 'traechtig' : ($r['result'] === '-' ? 'nicht traechtig' : 'unklar') ?></strong>
                    <?= $r['comment'] ? '<span class="text-muted">(' . e($r['comment']) . ')</span>' : '' ?>
                </span>
                <span class="badge bg-<?= $r['result'] === '+' ? 'success' : ($r['result'] === '-' ? 'danger' : 'secondary') ?>"><?= e($r['result']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

