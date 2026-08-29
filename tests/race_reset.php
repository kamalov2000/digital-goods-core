<?php

declare(strict_types=1);

/**
 * Puts the database back to "seeded, nothing sold" so tests/race.sh is repeatable.
 * Only ever run against a local test database.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;

$pdo = Db::pdo();
$pdo->beginTransaction();

Db::run('TRUNCATE supplier_issues, issue_requests, payment_events, ledger');
Db::run('UPDATE key_pool SET order_id = NULL, reserved_at = NULL WHERE order_id IS NOT NULL');
Db::run('DELETE FROM orders');

$pdo->commit();

$free = Db::one('SELECT count(*) AS n FROM key_pool WHERE order_id IS NULL');
printf("reset: 0 orders, %d keys available\n", (int) $free['n']);
