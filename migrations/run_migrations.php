<?php
/**
 * run_migrations.php
 * -----------------------------------------------------------------------------
 * Einfacher Migrations-Runner. Fuehrt alle *.sql-Dateien in migrations/ in
 * numerischer Reihenfolge gegen die in .env konfigurierte Datenbank aus und
 * protokolliert bereits ausgefuehrte Migrationen in der Tabelle `migrations`.
 *
 * Nutzung (CLI):
 *   php migrations/run_migrations.php            # offene Migrationen ausfuehren
 *   php migrations/run_migrations.php --status   # Status anzeigen
 *   php migrations/run_migrations.php --rollback # letzte Migration zurueckrollen
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur ueber die Kommandozeile ausfuehrbar.');
}

require_once __DIR__ . '/../includes/config.php'; // baut $db auf, laedt .env

$migrationsDir = __DIR__;
$rollbackDir   = __DIR__ . '/rollback';

// Migrations-Trackingtabelle sicherstellen
$db->exec(
    'CREATE TABLE IF NOT EXISTS `migrations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(255) NOT NULL UNIQUE,
        `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

function appliedMigrations(PDO $db): array
{
    return $db->query('SELECT filename FROM migrations ORDER BY filename')
              ->fetchAll(PDO::FETCH_COLUMN);
}

function migrationFiles(string $dir): array
{
    $files = glob($dir . '/[0-9]*.sql') ?: [];
    sort($files);
    return $files;
}

$arg = $argv[1] ?? '';

if ($arg === '--status') {
    $applied = appliedMigrations($db);
    echo "Migrationsstatus:\n";
    foreach (migrationFiles($migrationsDir) as $file) {
        $name = basename($file);
        $mark = in_array($name, $applied, true) ? '[x]' : '[ ]';
        echo "  $mark $name\n";
    }
    exit(0);
}

if ($arg === '--rollback') {
    $applied = appliedMigrations($db);
    if (!$applied) {
        exit("Keine Migration zum Zurueckrollen.\n");
    }
    $last = end($applied);
    $rollbackFile = $rollbackDir . '/' . preg_replace('/\.sql$/', '_rollback.sql', $last);
    // Dateinamen wie 008_seed_demo_and_admin.sql -> 008_rollback.sql
    $num = substr($last, 0, 3);
    $rollbackFile = $rollbackDir . '/' . $num . '_rollback.sql';
    if (!is_readable($rollbackFile)) {
        exit("Rollback-Datei nicht gefunden: $rollbackFile\n");
    }
    echo "Rolle zurueck: $last (via " . basename($rollbackFile) . ")\n";
    $db->exec(file_get_contents($rollbackFile));
    $stmt = $db->prepare('DELETE FROM migrations WHERE filename = ?');
    $stmt->execute([$last]);
    echo "Fertig.\n";
    exit(0);
}

// Standard: offene Migrationen ausfuehren
$applied = appliedMigrations($db);
$count = 0;
foreach (migrationFiles($migrationsDir) as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "Fuehre aus: $name ... ";
    try {
        $sql = file_get_contents($file);
        $db->exec($sql);
        $stmt = $db->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $stmt->execute([$name]);
        echo "OK\n";
        $count++;
    } catch (Throwable $e) {
        echo "FEHLER\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
echo $count === 0 ? "Keine offenen Migrationen.\n" : "$count Migration(en) ausgefuehrt.\n";
