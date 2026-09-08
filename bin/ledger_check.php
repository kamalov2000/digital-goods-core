<?php

declare(strict_types=1);

/**
 * Does the money journal add up?
 *
 *   php bin/ledger_check.php
 *
 * For every order that reached a final state: paid = delivered + refunded.
 * Exits 1 on any discrepancy.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Reconcile;

$discrepancies = Reconcile::ledgerDiscrepancies();
$stray = Reconcile::ledgerWithoutPayment();

// Totals over finished orders only: an order still in flight has money in and nothing
// settled yet, so its lines legitimately do not add up.
$totals = Db::one(
    "SELECT (SELECT count(*) FROM ledger) AS entries,
            COALESCE(sum(l.amount) FILTER (WHERE l.type = 'payment_received'), 0) AS paid,
            COALESCE(sum(l.amount) FILTER (WHERE l.type = 'revenue_recognised'), 0) AS delivered,
            COALESCE(sum(l.amount) FILTER (WHERE l.type = 'refund_issued'), 0) AS refunded
     FROM ledger l
     JOIN orders o ON o.id = l.order_id
     WHERE o.status IN ('delivered', 'partially_delivered', 'refunded')"
);

$paid = (int) $totals['paid'];
$delivered = (int) $totals['delivered'];
$refunded = (int) $totals['refunded'];

echo json_encode([
    'entries' => (int) $totals['entries'],
    'final_orders' => [
        'paid' => $paid,
        'delivered' => $delivered,
        'refunded' => $refunded,
    ],
    'balanced' => $paid === $delivered + $refunded,
    'orders_not_matching' => $discrepancies,
    'ledger_without_payment' => $stray,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit($discrepancies === [] && $stray === [] && $paid === $delivered + $refunded ? 0 : 1);
