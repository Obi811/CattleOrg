<?php
/**
 * public/import_bestand.php
 * -----------------------------------------------------------------------------
 * CSV-Import des Tierbestands. Behebt die Altlasten-Sicherheitsluecke:
 * HIER mit Authentifizierung, import.access-Berechtigung, Demo-Sperre und CSRF.
 * Alle importierten Tiere werden serverseitig dem aktuellen Betrieb zugeordnet.
 *
 * Erwartetes CSV-Format (Semikolon- oder Komma-getrennt), Kopfzeile:
 *   ear_tag;birth_date;rasse;geschlecht;mutter;status
 */

require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();
requirePermission('import.access');
requireWriteAccess();
$farmId = getCurrentFarmId();

$result = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Bitte eine gueltige CSV-Datei hochladen.';
    } else {
        $tmp = $_FILES['csv']['tmp_name'];
        $handle = fopen($tmp, 'r');
        if ($handle === false) {
            $errors[] = 'Datei konnte nicht gelesen werden.';
        } else {
            // Trennzeichen automatisch erkennen
            $firstLine = fgets($handle);
            $delimiter = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';
            rewind($handle);

            $header = fgetcsv($handle, 0, $delimiter);
            $header = array_map(fn($h) => strtolower(trim((string) $h)), $header ?: []);

            $imported = 0; $skipped = 0; $line = 1;
            $db->beginTransaction();
            try {
                $ins = $db->prepare(
                    'INSERT INTO cattle (farm_id, ear_tag, birth_date, rasse, geschlecht, mutter, status)
                     VALUES (:f, :e, :b, :r, :g, :m, :s)'
                );
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $line++;
                    $data = array_combine($header, array_pad($row, count($header), null));
                    $earTag = normalizeEarTag($data['ear_tag'] ?? '');
                    if ($earTag === '') { $skipped++; continue; }

                    // Duplikate im Betrieb ueberspringen
                    $chk = $db->prepare('SELECT COUNT(*) FROM cattle WHERE farm_id=:f AND ear_tag=:e');
                    $chk->execute([':f' => $farmId, ':e' => $earTag]);
                    if ((int) $chk->fetchColumn() > 0) { $skipped++; continue; }

                    $rasse = trim((string) ($data['rasse'] ?? ''));
                    if ($rasse !== '' && !in_array($rasse, cattleRaces(), true)) { $rasse = ''; }
                    $geschlecht = strtoupper(trim((string) ($data['geschlecht'] ?? '')));
                    if (!in_array($geschlecht, ['M', 'W'], true)) { $geschlecht = ''; }
                    $status = trim((string) ($data['status'] ?? 'Aktiv')) === 'Inaktiv' ? 'Inaktiv' : 'Aktiv';

                    $ins->execute([
                        ':f' => $farmId, ':e' => $earTag,
                        ':b' => !empty($data['birth_date']) ? $data['birth_date'] : null,
                        ':r' => $rasse !== '' ? $rasse : null,
                        ':g' => $geschlecht !== '' ? $geschlecht : null,
                        ':m' => !empty($data['mutter']) ? normalizeEarTag($data['mutter']) : null,
                        ':s' => $status,
                    ]);
                    $imported++;
                }
                $db->commit();
                $result = ['imported' => $imported, 'skipped' => $skipped];
                flash('success', "Import abgeschlossen: $imported angelegt, $skipped uebersprungen.", 'success');
            } catch (Throwable $ex) {
                $db->rollBack();
                $errors[] = 'Import fehlgeschlagen in Zeile ' . $line . '.';
            }
            fclose($handle);
        }
    }
}

$pageTitle = 'Bestand importieren';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-8">
    <h1 class="h3 mb-3">Tierbestand importieren (CSV)</h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <?php if ($result): ?>
        <div class="alert alert-success">
            <?= (int) $result['imported'] ?> Tiere importiert, <?= (int) $result['skipped'] ?> uebersprungen.
        </div>
    <?php endif; ?>
    <div class="card card-body">
        <p class="text-muted small">
            Erwartetes Format (Kopfzeile erforderlich, Trennzeichen <code>;</code> oder <code>,</code>):<br>
            <code>ear_tag;birth_date;rasse;geschlecht;mutter;status</code>
        </p>
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div class="mb-3">
                <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
            </div>
            <button class="btn btn-primary"><i class="bi bi-upload"></i> Importieren</button>
            <a href="<?= e(url('cattle_list.php')) ?>" class="btn btn-outline-secondary">Zur Tierliste</a>
        </form>
    </div>
</div></div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
