<?php

declare(strict_types=1);

/**
 * Load-test data for stage 5: 5000 SKUs and 200k keys bound to them.
 *
 *   php bin/seed_load.php [--skus=5000] [--keys-per-sku=40]
 *
 * Rows go in as multi-row INSERTs of 1000 at a time. Only the placeholder list is built as a
 * string - every value is still bound, so nothing is concatenated into SQL.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Stock;

$skuCount = 5000;
$keysPerSku = 40;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--skus=')) {
        $skuCount = max(1, (int) substr($arg, 7));
    }
    if (str_starts_with($arg, '--keys-per-sku=')) {
        $keysPerSku = max(0, (int) substr($arg, 15));
    }
}

$types = ['topup', 'key', 'subscription', 'giftcard'];
$pdo = Db::pdo();

/** @param list<array<int, mixed>> $rows */
function insertBatch(string $sql, int $columns, array $rows): void
{
    if ($rows === []) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($rows), '(' . implode(', ', array_fill(0, $columns, '?')) . ')'));
    Db::run($sql . ' VALUES ' . $placeholders . ' ON CONFLICT DO NOTHING', array_merge(...$rows));
}

$started = microtime(true);
$pdo->beginTransaction();

$batch = [];
for ($i = 1; $i <= $skuCount; $i++) {
    $sku = sprintf('LOAD-%05d', $i);
    $batch[] = [$sku, 'Load test item ' . $i, $types[$i % 4], 100 + ($i % 4900), 'RUB'];

    if (count($batch) === 1000) {
        insertBatch('INSERT INTO products (sku, name, type, price, currency)', 5, $batch);
        $batch = [];
    }
}
insertBatch('INSERT INTO products (sku, name, type, price, currency)', 5, $batch);
echo "products: {$skuCount}\n";

$batch = [];
$total = 0;
for ($i = 1; $i <= $skuCount; $i++) {
    $sku = sprintf('LOAD-%05d', $i);
    for ($k = 0; $k < $keysPerSku; $k++) {
        $batch[] = [sprintf('LK%05d-%04d-%s', $i, $k, strtoupper(bin2hex(random_bytes(2)))), $sku];
        $total++;

        if (count($batch) === 1000) {
            insertBatch('INSERT INTO key_pool (code, sku)', 2, $batch);
            $batch = [];
        }
    }
}
insertBatch('INSERT INTO key_pool (code, sku)', 2, $batch);
echo "keys: {$total}\n";

Stock::recompute();
$pdo->commit();

// ANALYZE outside the transaction: the planner needs fresh stats or every EXPLAIN below lies
$pdo->exec('ANALYZE products');
$pdo->exec('ANALYZE key_pool');
$pdo->exec('ANALYZE stock');

printf("done in %.1fs\n", microtime(true) - $started);
