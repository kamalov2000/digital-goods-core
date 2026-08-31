<?php

declare(strict_types=1);

namespace App;

final class Stock
{
    /**
     * Rebuilds every counter from the pool. Bulk operation only - seeding, restocking and the
     * test reset. The hot path never calls this: it decrements in the reservation transaction.
     */
    public static function recompute(): int
    {
        return Db::run(
            'INSERT INTO stock (sku, available, updated_at)
             SELECT p.sku, count(k.code), now()
             FROM products p
             LEFT JOIN key_pool k ON k.sku = p.sku AND k.order_id IS NULL
             GROUP BY p.sku
             ON CONFLICT (sku) DO UPDATE SET available = excluded.available, updated_at = now()',
        )->rowCount();
    }

    /**
     * Free keys that are not bound to any sku and therefore usable by every product.
     * One scalar per storefront request, served straight off key_pool_available_idx.
     */
    public static function sharedAvailable(): int
    {
        $row = Db::one('SELECT count(*) AS n FROM key_pool WHERE sku IS NULL AND order_id IS NULL');

        return (int) $row['n'];
    }
}
