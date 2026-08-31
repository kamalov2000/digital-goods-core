<?php

declare(strict_types=1);

/**
 * Does the money journal add up?
 *
 *   php bin/ledger_check.php
 *
 * For every order that was paid, the ledger entries must sum to exactly the order amount.
 * Exits 1 on any discrepancy.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Reconcile;

$discrepancies = Reconcile::ledgerDiscrepancies();
$stray = Reconcile::ledgerWithoutPayment();

$totals = Db::one(
    "SELECT (SELECT count(*) FROM ledger) AS entries,
            (SELECT COALESCE(sum(amount), 0) FROM ledger) AS ledger_total,
            (SELECT COALESCE(sum(amount), 0) FROM orders
             WHERE status NOT IN ('created', 'payment_failed')) AS orders_total"
);

echo json_encode([
    'entries' => (int) $totals['entries'],
    'ledger_total' => (int) $totals['ledger_total'],
    'paid_orders_total' => (int) $totals['orders_total'],
    'balanced' => (int) $totals['ledger_total'] === (int) $totals['orders_total'],
    'orders_not_matching' => $discrepancies,
    'ledger_without_payment' => $stray,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit($discrepancies === [] && $stray === [] ? 0 : 1);
