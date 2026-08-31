<?php

declare(strict_types=1);

/**
 * Runs EXPLAIN (ANALYZE, BUFFERS) over the storefront query variants and writes
 * docs/stage5_explain.md. Called by tests/bench_catalog.sh after the load seeding.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;

$variants = [
    'A. naive: aggregate the whole pool, then paginate' => [
        'sql' => "SELECT p.sku, p.name, p.price, COALESCE(a.available, 0) AS available
                  FROM products p
                  LEFT JOIN (
                      SELECT sku, count(*) AS available
                      FROM key_pool
                      WHERE order_id IS NULL
                      GROUP BY sku
                  ) a ON a.sku = p.sku
                  ORDER BY p.sku
                  LIMIT 50 OFFSET 2000",
        'note' => 'What you write when there is no counter. The GROUP BY has to touch every free key '
            . 'in the pool before a single row of the page can be returned, so its cost is set by the '
            . 'size of the inventory and not by the size of the page.',
    ],
    'B. correlated subquery per row of the page' => [
        'sql' => "SELECT p.sku, p.name, p.price,
                         (SELECT count(*) FROM key_pool k WHERE k.sku = p.sku AND k.order_id IS NULL) AS available
                  FROM products p
                  ORDER BY p.sku
                  LIMIT 50 OFFSET 2000",
        'note' => 'Much better for one plain page - only 50 index lookups. It degrades the moment the '
            . 'storefront wants to filter or sort BY availability, because then the count has to be '
            . 'evaluated for every product before anything can be discarded.',
    ],
    'C. materialised counter' => [
        'sql' => "SELECT p.sku, p.name, p.price, s.available
                  FROM products p
                  JOIN stock s ON s.sku = p.sku
                  ORDER BY p.sku
                  LIMIT 50 OFFSET 2000",
        'note' => 'The page is read from an index-ordered join of two small tables. Cost depends on the '
            . 'page, not on the inventory, and it stays flat as the pool grows.',
    ],
    'D. materialised counter, in-stock filter' => [
        'sql' => "SELECT p.sku, p.name, p.price, s.available
                  FROM products p
                  JOIN stock s ON s.sku = p.sku
                  WHERE s.available > 0
                  ORDER BY p.sku
                  LIMIT 50 OFFSET 2000",
        'note' => 'The case that decides it: the filter is a column, so stock_available_idx answers it '
            . 'directly. Variant A would have to aggregate the entire pool first.',
    ],
];

$counts = Db::one(
    'SELECT (SELECT count(*) FROM products) AS products,
            (SELECT count(*) FROM key_pool) AS keys_total,
            (SELECT count(*) FROM key_pool WHERE order_id IS NULL) AS keys_free'
);

$out = [];
$out[] = '# Stage 5: storefront query plans';
$out[] = '';
$out[] = sprintf(
    'Measured on %d products / %d keys (%d free), postgres 16, `EXPLAIN (ANALYZE, BUFFERS)`.',
    (int) $counts['products'],
    (int) $counts['keys_total'],
    (int) $counts['keys_free'],
);
$out[] = '';

$timings = [];
foreach ($variants as $title => $variant) {
    $plan = [];
    foreach (Db::all('EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) ' . $variant['sql']) as $row) {
        $plan[] = $row['QUERY PLAN'];
    }
    $text = implode("\n", $plan);

    preg_match('/Execution Time: ([\d.]+) ms/', $text, $m);
    $timings[$title] = $m[1] ?? '?';

    $out[] = '## ' . $title;
    $out[] = '';
    $out[] = '```sql';
    $out[] = preg_replace('/^ {18}/m', '  ', trim($variant['sql']));
    $out[] = '```';
    $out[] = '';
    $out[] = '```';
    $out[] = $text;
    $out[] = '```';
    $out[] = '';
    $out[] = $variant['note'];
    $out[] = '';
}

$out[] = '## Why it is built this way';
$out[] = '';
foreach ($timings as $title => $ms) {
    $out[] = sprintf('- **%s** - %s ms', $title, $ms);
}
$out[] = '';
$out[] = '1. The partial index `key_pool (sku) WHERE order_id IS NULL` is what makes reserving a key '
    . 'cheap and what keeps the free-key count off the dead rows, and it is enough for a single '
    . 'lookup by sku (variant B). It is not enough for the storefront, because an index cannot '
    . 'return a count without walking every matching entry - the work still scales with inventory.';
$out[] = '2. So availability is materialised into `stock` and decremented inside the very transaction '
    . 'that reserves the key (`suppliers/supplier.php`). There is no window in which a key is gone '
    . 'from the pool but still advertised.';
$out[] = '3. That turns the hot query into a join of two small, index-ordered tables: cost tracks the '
    . 'page size, not the number of keys, and filtering by availability becomes an indexed column '
    . 'predicate instead of a post-aggregation filter.';
$out[] = '4. A materialised counter can drift, so `bin/reconcile.php` compares every `stock.available` '
    . 'against the real pool and reports any sku where the two disagree.';
$out[] = '';

$path = dirname(__DIR__) . '/docs/stage5_explain.md';
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0777, true);
}
file_put_contents($path, implode("\n", $out) . "\n");

echo "wrote docs/stage5_explain.md\n";
foreach ($timings as $title => $ms) {
    printf("  %-52s %8s ms\n", substr($title, 0, 52), $ms);
}
