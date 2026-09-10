<?php

declare(strict_types=1);

/**
 * Puts the database back to "seeded, nothing sold" so tests/race.sh is repeatable.
 * Only ever run against a local test database.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Stock;

$pdo = Db::pdo();
$pdo->beginTransaction();

// Rate-limit bookkeeping and the discrepancy log have to go too: rows left from a previous
// run sit inside the current sliding window and make both the limiter and the assertions
// about it measure two runs at once.
Db::run('TRUNCATE supplier_issues, issue_requests, order_items, payment_events, ledger,
                 supplier_discrepancies, supplier_calls, delivery_slots');
Db::run('UPDATE key_pool SET order_id = NULL, reserved_at = NULL WHERE order_id IS NOT NULL');
Db::run('DELETE FROM orders');
Stock::recompute();

$pdo->commit();

$free = Db::one('SELECT count(*) AS n FROM key_pool WHERE order_id IS NULL');
printf("reset: 0 orders, %d keys available\n", (int) $free['n']);
