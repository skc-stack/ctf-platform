<?php
declare(strict_types=1);

/**
 * bin/migrate.php — Run all SQL migrations in ctf-server/database/migrations/.
 *
 * Each .sql file is applied in lexical order. The file 000_init.sql drops and
 * recreates everything; later files should be incremental.
 *
 * Usage:
 *   php bin/migrate.php
 */

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Support\Logger;

$migrationsDir = __DIR__ . '/../database/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations dir not found: {$migrationsDir}\n");
    exit(1);
}

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    echo "No migrations found.\n";
    exit(0);
}

$pdo = Connection::pdo();
echo "Running " . count($files) . " migration(s)...\n";

foreach ($files as $file) {
    $name = basename($file);
    echo "  - {$name} ... ";
    $sql = (string)file_get_contents($file);
    try {
        // Split on semicolons at end of line; MariaDB driver does not accept
        // multiple statements in a single prepare()/exec() reliably across
        // drivers, so use exec() on the whole file (works because we're a CLI).
        $pdo->exec($sql);
        echo "ok\n";
    } catch (\Throwable $e) {
        echo "FAIL\n";
        Logger::get()->critical('Migration failed', [
            'file' => $name,
            'error' => $e->getMessage(),
        ]);
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(2);
    }
}

echo "Done.\n";
