<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Db;

$pdo = Db::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   text PRIMARY KEY,
    applied_at timestamptz NOT NULL DEFAULT now()
)');

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($files);

$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "cannot read {$name}\n");
        exit(1);
    }

    // whole file in one transaction: postgres has transactional DDL, so a broken
    // migration leaves nothing half-applied
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        Db::run('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "migration {$name} failed: {$e->getMessage()}\n");
        exit(1);
    }

    echo "applied {$name}\n";
    $count++;
}

echo $count === 0 ? "nothing to migrate\n" : "{$count} migration(s) applied\n";
