<?php

declare(strict_types=1);

namespace App;

/**
 * Reconciliation report, shared by bin/reconcile.php and GET /api/admin/reconcile.
 */
final class Reconcile
{
    /**
     * @return array<string, mixed>
     */
    public static function report(int $staleMinutes): array
    {
        // Money in, goods not out. Everything past 'created' that has not reached a code yet
        // and has been sitting still long enough to not simply be in flight.
        $paidNotDelivered = Db::all(
            "SELECT o.id, o.status, o.amount, o.sku, o.updated_at
             FROM orders o
             WHERE o.status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed')
               AND o.updated_at < now() - make_interval(mins => ?)
             ORDER BY o.updated_at
             LIMIT 500",
            [$staleMinutes],
        );

        // Goods out, money not in. This list is an assertion, not a report: a code is only
        // ever issued from 'delivering', which is only reachable from 'paid', which only an
        // applied payment event can produce. Anything here means the invariant broke.
        $deliveredNotPaid = Db::all(
            "SELECT o.id, o.status, r.code, r.supplier
             FROM orders o
             JOIN issue_requests r ON r.order_id = o.id AND r.status = 'issued'
             WHERE NOT EXISTS (
                 SELECT 1 FROM payment_events e
                 WHERE e.order_id = o.id AND e.status = 'paid' AND e.applied AND e.result = 'applied'
             )
             LIMIT 500",
        );

        // Keys that left the pool for an order that never got delivered - the residue of a
        // supplier call whose answer was lost. Recovery clears these by replaying request_id.
        $orphanedKeys = Db::all(
            "SELECT k.code, k.order_id, o.status
             FROM key_pool k
             JOIN orders o ON o.id = k.order_id
             WHERE o.status <> 'delivered'
             ORDER BY k.reserved_at
             LIMIT 500",
        );

        return [
            'generated_at' => gmdate('c'),
            'stale_after_minutes' => $staleMinutes,
            'paid_not_delivered' => ['count' => count($paidNotDelivered), 'orders' => $paidNotDelivered],
            'delivered_not_paid' => ['count' => count($deliveredNotPaid), 'orders' => $deliveredNotPaid],
            'orphaned_keys' => ['count' => count($orphanedKeys), 'keys' => $orphanedKeys],
        ];
    }

    /**
     * Every order that was paid must carry ledger entries summing to exactly its amount.
     * The comparison has teeth because the ledger stores the amount the gateway reported,
     * not the order's own amount.
     *
     * @return list<array<string, mixed>>
     */
    public static function ledgerDiscrepancies(): array
    {
        return Db::all(
            "SELECT o.id, o.status, o.amount AS order_amount,
                    COALESCE(sum(l.amount), 0) AS ledger_amount
             FROM orders o
             LEFT JOIN ledger l ON l.order_id = o.id
             WHERE o.status NOT IN ('created', 'payment_failed')
             GROUP BY o.id, o.status, o.amount
             HAVING COALESCE(sum(l.amount), 0) <> o.amount
             LIMIT 500",
        );
    }

    /**
     * The mirror image: money booked for an order that was never paid.
     *
     * @return list<array<string, mixed>>
     */
    public static function ledgerWithoutPayment(): array
    {
        return Db::all(
            "SELECT l.order_id, l.type, l.amount, l.ref
             FROM ledger l
             JOIN orders o ON o.id = l.order_id
             WHERE o.status IN ('created', 'payment_failed')
             LIMIT 500",
        );
    }
}
