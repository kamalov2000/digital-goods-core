<?php

declare(strict_types=1);

namespace App;

/**
 * The append-only side of the system: what happened, when, and what that adds up to.
 *
 * State comes from order_events, money from the ledger. Both are sealed against UPDATE and
 * DELETE at the database level (see migrations/009_history.sql), so any answer given here is
 * reproducible - asking the same question twice about the same past instant cannot change.
 */
final class History
{
    /**
     * Appends one state change. Always called inside the transaction that made the change, so
     * the log cannot disagree with the tables it describes.
     */
    public static function record(
        string $type,
        string $orderId,
        ?string $itemId,
        ?string $from,
        string $to,
        ?int $amount = null,
    ): void {
        Db::run(
            'INSERT INTO order_events (order_id, item_id, type, from_state, to_state, amount)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, $itemId, $type, $from, $to, $amount],
        );
    }

    /**
     * What did this order look like at that instant?
     *
     * @return array<string, mixed>
     */
    public static function stateAt(string $orderId, string $at): array
    {
        $created = Db::one(
            "SELECT to_state, amount, occurred_at FROM order_events
             WHERE order_id = ? AND type = 'order.created' AND occurred_at <= ?
             ORDER BY occurred_at, id LIMIT 1",
            [$orderId, $at],
        );

        // the order had not been placed yet - the honest answer is "it did not exist"
        if ($created === null) {
            return ['as_of' => $at, 'order_id' => $orderId, 'existed' => false];
        }

        $status = Db::one(
            "SELECT to_state FROM order_events
             WHERE order_id = ? AND type IN ('order.created', 'order.status') AND occurred_at <= ?
             ORDER BY occurred_at DESC, id DESC LIMIT 1",
            [$orderId, $at],
        );

        // last word on each line as of that instant
        $items = Db::all(
            "SELECT DISTINCT ON (item_id) item_id, to_state AS status, amount
             FROM order_events
             WHERE order_id = ? AND type = 'item.status' AND occurred_at <= ?
             ORDER BY item_id, occurred_at DESC, id DESC",
            [$orderId, $at],
        );

        $money = ['payment_received' => 0, 'revenue_recognised' => 0, 'refund_issued' => 0];
        foreach (Db::all(
            'SELECT type, COALESCE(sum(amount), 0) AS total FROM ledger
             WHERE order_id = ? AND created_at <= ? GROUP BY type',
            [$orderId, $at],
        ) as $row) {
            $money[(string) $row['type']] = (int) $row['total'];
        }

        return [
            'as_of' => $at,
            'order_id' => $orderId,
            'existed' => true,
            'status' => $status === null ? null : (string) $status['to_state'],
            'amount' => (int) $created['amount'],
            'items' => array_map(
                static fn (array $i): array => [
                    'item_id' => $i['item_id'],
                    'status' => $i['status'],
                    'amount' => (int) $i['amount'],
                ],
                $items,
            ),
            'money' => $money + [
                // what we were still holding on the customer's behalf at that moment
                'unsettled' => $money['payment_received'] - $money['revenue_recognised'] - $money['refund_issued'],
            ],
        ];
    }

    /**
     * Totals for a period, computed from the logs alone.
     *
     * The closing balance has to equal the opening balance plus the movements inside the window.
     * That is an identity, not a hope: all three numbers are sums over the same append-only
     * table, so if it ever fails, something rewrote history.
     *
     * @return array<string, mixed>
     */
    public static function report(string $from, string $to): array
    {
        $types = ['payment_received', 'revenue_recognised', 'refund_issued'];

        $sum = static function (string $sql, array $params) use ($types): array {
            $out = array_fill_keys($types, 0);
            foreach (Db::all($sql, $params) as $row) {
                $out[(string) $row['type']] = (int) $row['total'];
            }

            return $out;
        };

        $opening = $sum(
            'SELECT type, COALESCE(sum(amount), 0) AS total FROM ledger
             WHERE created_at <= ? GROUP BY type',
            [$from],
        );
        $movements = $sum(
            'SELECT type, COALESCE(sum(amount), 0) AS total FROM ledger
             WHERE created_at > ? AND created_at <= ? GROUP BY type',
            [$from, $to],
        );
        $closing = $sum(
            'SELECT type, COALESCE(sum(amount), 0) AS total FROM ledger
             WHERE created_at <= ? GROUP BY type',
            [$to],
        );

        $continuous = true;
        foreach ($types as $type) {
            $continuous = $continuous && $closing[$type] === $opening[$type] + $movements[$type];
        }

        // Orders that reached a final state inside the window. For those, and only those, the
        // money must already have been settled in full.
        $finalised = Db::all(
            "SELECT DISTINCT e.order_id, e.to_state
             FROM order_events e
             WHERE e.type = 'order.status'
               AND e.to_state IN ('delivered', 'partially_delivered', 'refunded', 'payment_failed')
               AND e.occurred_at > ? AND e.occurred_at <= ?",
            [$from, $to],
        );

        $unbalanced = Db::all(
            "SELECT o.id,
                    COALESCE(sum(l.amount) FILTER (WHERE l.type = 'payment_received'), 0) AS paid,
                    COALESCE(sum(l.amount) FILTER (WHERE l.type = 'revenue_recognised'), 0) AS delivered,
                    COALESCE(sum(l.amount) FILTER (WHERE l.type = 'refund_issued'), 0) AS refunded
             FROM orders o
             JOIN order_events e ON e.order_id = o.id AND e.type = 'order.status'
                 AND e.to_state IN ('delivered', 'partially_delivered', 'refunded')
                 AND e.occurred_at > ? AND e.occurred_at <= ?
             LEFT JOIN ledger l ON l.order_id = o.id
             GROUP BY o.id
             HAVING COALESCE(sum(l.amount) FILTER (WHERE l.type = 'payment_received'), 0)
                 <> COALESCE(sum(l.amount) FILTER (WHERE l.type = 'revenue_recognised'), 0)
                  + COALESCE(sum(l.amount) FILTER (WHERE l.type = 'refund_issued'), 0)
             LIMIT 100",
            [$from, $to],
        );

        return [
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'movements' => $movements,
            'closing' => $closing,
            'continuous' => $continuous,
            'orders_finalised' => count($finalised),
            'orders_not_balancing' => ['count' => count($unbalanced), 'orders' => $unbalanced],
            'balanced' => $continuous && $unbalanced === [],
        ];
    }
}
