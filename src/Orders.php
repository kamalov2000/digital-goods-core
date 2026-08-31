<?php

declare(strict_types=1);

namespace App;

final class Orders
{
    /**
     * @return array<string, mixed>|null null when the sku is unknown
     */
    public static function create(string $sku): ?array
    {
        $product = Db::one('SELECT sku, price, currency FROM products WHERE sku = ?', [$sku]);
        if ($product === null) {
            return null;
        }

        $id = 'ord_' . bin2hex(random_bytes(8));

        Db::run(
            'INSERT INTO orders (id, sku, amount, currency, status) VALUES (?, ?, ?, ?, ?)',
            [$id, $product['sku'], $product['price'], $product['currency'], 'created'],
        );

        Log::info('order.create', [
            'order_id' => $id,
            'result' => 'created',
            'sku' => $sku,
            'amount' => (int) $product['price'],
        ]);

        return self::get($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        $order = Db::one(
            'SELECT o.id, o.sku, o.amount, o.currency, o.status, o.created_at, o.updated_at,
                    (SELECT code FROM issue_requests r WHERE r.order_id = o.id AND r.status = ? LIMIT 1) AS code
             FROM orders o WHERE o.id = ?',
            ['issued', $id],
        );

        return $order;
    }

    /**
     * The only way an order changes state. Conditional UPDATE + rowCount instead of
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
        return [
            'id' => $order['id'],
            'sku' => $order['sku'],
            'amount' => (int) $order['amount'],
            'currency' => $order['currency'],
            'status' => $order['status'],
            'code' => $order['code'],
            'created_at' => gmdate('c', strtotime((string) $order['created_at'])),
            'updated_at' => gmdate('c', strtotime((string) $order['updated_at'])),
        ];
    }
}
