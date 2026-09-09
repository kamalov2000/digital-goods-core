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
            "SELECT i.order_id, i.id AS item_id, i.code, i.supplier
             FROM order_items i
             WHERE i.status = 'delivered'
               AND NOT EXISTS (
                   SELECT 1 FROM payment_events e
                   WHERE e.order_id = i.order_id AND e.status = 'paid'
                     AND e.applied AND e.result = 'applied'
               )
             LIMIT 500",
        );

        // Keys that left the pool for an order that never got delivered - the residue of a
        // supplier call whose answer was lost. Recovery clears these by replaying request_id.
        $orphanedKeys = Db::all(
            'SELECT k.code, k.order_id, k.reserved_at
             FROM key_pool k
             WHERE k.order_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM order_items i WHERE i.code = k.code)
             ORDER BY k.reserved_at
             LIMIT 500',
        );

        // Materialised stock counter vs the pool it is supposed to mirror (stage 5). A counter
        // that can drift silently is worse than no counter, so the drift is reported here.
        $stockDrift = Db::all(
            'SELECT s.sku, s.available AS counter, count(k.code) AS actual
             FROM stock s
             LEFT JOIN key_pool k ON k.sku = s.sku AND k.order_id IS NULL
             GROUP BY s.sku, s.available
             HAVING s.available <> count(k.code)
             LIMIT 500',
        );

        // What the supplier got wrong and has not been put right yet. Detection is automatic
        // and so is the resolution, so a non-empty list here means something is still in
        // flight, not that somebody has to go and fix it by hand.
        $openDiscrepancies = Db::all(
            'SELECT kind, supplier, order_id, item_id, code, detail, detected_at
             FROM supplier_discrepancies
             WHERE resolved_at IS NULL
             ORDER BY detected_at
             LIMIT 500',
        );

        return [
            'generated_at' => gmdate('c'),
            'stale_after_minutes' => $staleMinutes,
            'paid_not_delivered' => ['count' => count($paidNotDelivered), 'orders' => $paidNotDelivered],
            'delivered_not_paid' => ['count' => count($deliveredNotPaid), 'orders' => $deliveredNotPaid],
            'orphaned_keys' => ['count' => count($orphanedKeys), 'keys' => $orphanedKeys],
            'stock_drift' => ['count' => count($stockDrift), 'skus' => $stockDrift],
            'open_discrepancies' => ['count' => count($openDiscrepancies), 'items' => $openDiscrepancies],
        ];
    }

    /**
     * The identity behind task 1: for every order that reached a final state,
     * paid = delivered + refunded. The comparison has teeth because the journal stores the
     * amount the gateway reported, not the order's own amount.
     *
     * @return list<array<string, mixed>>
     */
    public static function ledgerDiscrepancies(): array
    {
        return Db::all(
            "SELECT o.id, o.status, o.amount AS order_amount,
                    COALESCE(sum(l.amount) FILTER (WHERE l.type = 'payment_received'), 0) AS paid,
                    COALESCE(sum(l.amount) FILTER (WHERE l.type = 'revenue_recognised'), 0) AS delivered,
                    COALESCE(sum(l.amount) FILTER (WHERE l.type = 'refund_issued'), 0) AS refunded
             FROM orders o
             LEFT JOIN ledger l ON l.order_id = o.id
             WHERE o.status IN ('delivered', 'partially_delivered', 'refunded')
             GROUP BY o.id, o.status, o.amount
             HAVING COALESCE(sum(l.amount) FILTER (WHERE l.type = 'payment_received'), 0)
                 <> COALESCE(sum(l.amount) FILTER (WHERE l.type = 'revenue_recognised'), 0)
                  + COALESCE(sum(l.amount) FILTER (WHERE l.type = 'refund_issued'), 0)
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
