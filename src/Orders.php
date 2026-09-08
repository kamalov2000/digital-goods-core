<?php

declare(strict_types=1);

namespace App;

final class Orders
{
    /**
     * Creates an order from one or more line items.
     *
     * @param  list<array{sku: string, qty: int}> $lines
     * @return array<string, mixed>|null null when any sku is unknown
     */
    public static function create(array $lines): ?array
    {
        if ($lines === []) {
            return null;
        }

        $products = [];
        foreach ($lines as $line) {
            $product = Db::one(
                'SELECT sku, price, currency, supplier FROM products WHERE sku = ?',
                [$line['sku']],
            );
            if ($product === null) {
                return null;
            }
            $products[] = ['product' => $product, 'qty' => $line['qty']];
        }

        $id = 'ord_' . bin2hex(random_bytes(8));
        $total = 0;
        $items = [];
        foreach ($products as $entry) {
            for ($i = 0; $i < $entry['qty']; $i++) {
                $items[] = $entry['product'];
                $total += (int) $entry['product']['price'];
            }
        }

        // orders.sku is only meaningful when the order has exactly one line
        $singleSku = count($items) === 1 ? (string) $items[0]['sku'] : null;
        $currency = (string) $items[0]['currency'];

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        Db::run(
            'INSERT INTO orders (id, sku, amount, currency, status) VALUES (?, ?, ?, ?, ?)',
            [$id, $singleSku, $total, $currency, 'created'],
        );

        foreach ($items as $product) {
            Db::run(
                'INSERT INTO order_items (id, order_id, sku, amount, currency, supplier, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    'itm_' . bin2hex(random_bytes(8)),
                    $id,
                    $product['sku'],
                    (int) $product['price'],
                    $product['currency'],
                    $product['supplier'],
                    'pending',
                ],
            );
        }

        $pdo->commit();

        Log::info('order.create', [
            'order_id' => $id,
            'result' => 'created',
            'items' => count($items),
            'amount' => $total,
        ]);

        return self::get($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        $order = Db::one(
            'SELECT id, sku, amount, currency, status, created_at, updated_at FROM orders WHERE id = ?',
            [$id],
        );

        if ($order === null) {
            return null;
        }

        $order['items'] = Db::all(
            'SELECT id, sku, amount, currency, supplier, status, code
             FROM order_items WHERE order_id = ? ORDER BY id',
            [$id],
        );

        return $order;
    }

    /**
     * The only way an order changes state by hand. Conditional UPDATE + rowCount instead of
     * SELECT-then-UPDATE: postgres evaluates the WHERE under a row lock, so out of N
     * concurrent callers exactly one sees rowCount 1 and owns the transition.
     */
    public static function transition(string $id, string $from, string $to): bool
    {
        $moved = Db::run(
            'UPDATE orders SET status = ?, updated_at = now() WHERE id = ? AND status = ?',
            [$to, $id, $from],
        )->rowCount();

        if ($moved === 1) {
            Log::info('order.transition', ['order_id' => $id, 'result' => $to, 'from' => $from]);
        }

        return $moved === 1;
    }

    /**
     * Derives the order status from its line items.
     *
     * One statement on purpose: the aggregate and the UPDATE share a snapshot, so two workers
     * finishing two different items cannot race each other into leaving the order behind at
     * 'delivering'. Orders that have not been paid yet are never touched here - until money
     * arrives the order status is owned by the payment path.
     */
    public static function settle(string $orderId): ?string
    {
        $row = Db::one(
            "WITH agg AS (
                 SELECT count(*) AS total,
                        count(*) FILTER (WHERE status = 'delivered') AS delivered,
                        count(*) FILTER (WHERE status = 'refunded') AS refunded,
                        count(*) FILTER (WHERE status IN ('pending', 'delivering')) AS in_flight,
                        count(*) FILTER (WHERE status = 'delivery_failed') AS failed
                 FROM order_items WHERE order_id = ?
             ),
             target AS (
                 SELECT CASE
                     WHEN a.in_flight > 0                                    THEN 'delivering'
                     WHEN a.delivered = a.total                              THEN 'delivered'
                     WHEN a.refunded = a.total                               THEN 'refunded'
                     WHEN a.delivered + a.refunded = a.total                 THEN 'partially_delivered'
                     WHEN a.failed > 0                                       THEN 'delivery_failed'
                     ELSE 'out_of_stock'
                 END AS status FROM agg a
             )
             UPDATE orders o SET status = t.status, updated_at = now()
             FROM target t
             WHERE o.id = ?
               AND o.status <> t.status
               AND o.status NOT IN ('created', 'paid', 'payment_failed')
             RETURNING o.status",
            [$orderId, $orderId],
        );

        if ($row !== null) {
            Log::info('order.settle', ['order_id' => $orderId, 'result' => $row['status']]);

            return (string) $row['status'];
        }

        return null;
    }

    /**
     * Orders left mid-flight by a crash between finishing the last line and deriving the order
     * status. settle() is idempotent, so re-running it costs nothing when there is no drift.
     */
    public static function settleStale(int $limit): int
    {
        $rows = Db::all(
            "SELECT o.id FROM orders o
             WHERE o.status = 'delivering'
               AND NOT EXISTS (
                   SELECT 1 FROM order_items i
                   WHERE i.order_id = o.id AND i.status IN ('pending', 'delivering')
               )
             LIMIT ?",
            [$limit],
        );

        $done = 0;
        foreach ($rows as $row) {
            if (self::settle((string) $row['id']) !== null) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Conditional claim on a line item, same contract as transition().
     */
    public static function moveItem(string $itemId, string $from, string $to): bool
    {
        return Db::run(
            'UPDATE order_items SET status = ?, updated_at = now() WHERE id = ? AND status = ?',
            [$to, $itemId, $from],
        )->rowCount() === 1;
    }

    public static function exists(string $id): bool
    {
        return Db::one('SELECT 1 AS ok FROM orders WHERE id = ?', [$id]) !== null;
    }

    /**
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function toJson(array $order): array
    {
        $items = [];
        foreach ($order['items'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'sku' => $item['sku'],
                'amount' => (int) $item['amount'],
                'currency' => $item['currency'],
                'supplier' => $item['supplier'],
                'status' => $item['status'],
                'code' => $item['code'],
            ];
        }

        // The stage 1 shape (top level sku and code) is kept for single-line orders so the
        // original API and its tests keep working unchanged.
        $single = count($items) === 1 ? $items[0] : null;

        return [
            'id' => $order['id'],
            'sku' => $order['sku'],
            'amount' => (int) $order['amount'],
            'currency' => $order['currency'],
            'status' => $order['status'],
            'code' => $single === null ? null : $single['code'],
            'items' => $items,
            'created_at' => gmdate('c', strtotime((string) $order['created_at'])),
            'updated_at' => gmdate('c', strtotime((string) $order['updated_at'])),
        ];
    }
}
