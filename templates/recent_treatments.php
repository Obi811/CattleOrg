<?php
/**
 * templates/recent_treatments.php
 * -----------------------------------------------------------------------------
 * Gemeinsame Darstellung der Karte "Letzte Behandlungen" (letzte 7 Tage,
 * max. 20 Eintraege). Wird von der Aufgabenliste (todo_list.php) und vom
 * Dashboard (index.php) eingebunden.
 *
 * Erwartete Variablen (vom einbindenden Skript gesetzt):
 *   $letzteBehandlungen (array)  – Zeilen aus loadTasks()['letzte_behandlungen']
 *
 * Optional:
 *   $showRecentCardHeader (bool) – wenn false, wird der Karten-Rahmen samt
 *       Kopfzeile weggelassen (z. B. wenn die Karte bereits in einem
 *       aufklappbaren Abschnitt mit eigener Kopfzeile steckt). Default true.
 */
if (!isset($letzteBehandlungen)) { return; }
$showRecentCardHeader = $showRecentCardHeader ?? true;
?>
<?php if ($showRecentCardHeader): ?>
<div class="card mb-4 shadow-sm">
    <div class="card-header fw-semibold">
        <i class="bi bi-clipboard2-pulse"></i> Letzte Behandlungen
        <span class="badge bg-secondary ms-1"><?= count($letzteBehandlungen) ?></span>
        <small class="text-muted fw-normal ms-1">(letzte 7 Tage)</small>
    </div>
<?php endif; ?>
    <?php if (empty($letzteBehandlungen)): ?>
        <div class="card-body"><p class="text-muted mb-0">In den letzten 7 Tagen keine Behandlungen erfasst.</p></div>
    <?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($letzteBehandlungen as $b): ?>
            <li class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold">
                            <?= cattleLink((int) $b['cattle_id'], $b['ear_tag']) ?>
                            <span class="text-muted small ms-1"><?= e(formatDate($b['treatment_date'])) ?></span>
                        </div>
                        <div class="small text-muted">
                            <?= e($b['medication']) ?> (<?= e($b['type']) ?>)<?= $b['dosage'] ? ', ' . e($b['dosage']) : '' ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <?php if (!empty($b['wartezeit_aktiv'])): ?>
                            <span class="badge bg-danger" title="Milch/Fleisch gesperrt bis zum Ende der Wartezeit">
                                <i class="bi bi-exclamation-triangle"></i>
                                Wartezeit bis <?= e(formatDate($b['wartezeit_ende'])) ?>
                            </span>
                        <?php elseif (!empty($b['wartezeit_ende'])): ?>
                            <span class="badge bg-success-subtle text-success-emphasis" title="Wartezeit beendet">
                                <i class="bi bi-check2"></i> Wartezeit beendet
                            </span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted" title="Keine Wartezeit hinterlegt">keine Wartezeit</span>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php if ($showRecentCardHeader): ?>
</div>
<?php endif; ?>
