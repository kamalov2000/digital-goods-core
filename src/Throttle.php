<?php

declare(strict_types=1);

namespace App;

/**
 * Client-side pacing for supplier calls, shared by every worker process.
 *
 * A sliding window rather than a fixed one: a fixed window lets twice the limit through around
 * the boundary, which is exactly the moment a surge would hit it.
 */
final class Throttle
{
    public static function limit(string $supplier): int
    {
        // per-supplier override first, then a shared default; 0 means no limit at all
        $specific = Env::int('SUPPLIER_' . $supplier . '_RATE_LIMIT_PER_MIN', -1);

        return $specific >= 0 ? $specific : Env::int('SUPPLIER_RATE_LIMIT_PER_MIN', 0);
    }

    /** Cheap unlocked peek, used to skip a line rather than claim it and immediately give it back. */
    public static function available(string $supplier): bool
    {
        $limit = self::limit($supplier);

        return $limit === 0 || self::used($supplier) < $limit;
    }

    public static function used(string $supplier): int
    {
        $row = Db::one(
            "SELECT count(*) AS n FROM delivery_slots
             WHERE supplier = ? AND taken_at > now() - interval '1 minute'",
            [$supplier],
        );

        return (int) $row['n'];
    }

    /**
     * Takes one slot, or reports that there is no room right now.
     *
     * The advisory lock is what makes the limit a limit: counting and inserting have to be one
     * indivisible step, otherwise N workers all read "one slot left" and all take it.
     */
    public static function acquire(string $supplier): bool
    {
        $limit = self::limit($supplier);
        if ($limit === 0) {
            return true;
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        Db::run('SELECT pg_advisory_xact_lock(hashtext(?))', ['delivery_slot_' . $supplier]);

        $row = Db::one(
            "SELECT count(*) AS n FROM delivery_slots
             WHERE supplier = ? AND taken_at > now() - interval '1 minute'",
            [$supplier],
        );

        if ((int) $row['n'] >= $limit) {
            $pdo->rollBack();

            return false;
        }

        Db::run('INSERT INTO delivery_slots (supplier) VALUES (?)', [$supplier]);
        $pdo->commit();

        return true;
    }

    /**
     * What the queue looks like right now, for GET /api/admin/queue.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $suppliers = [];
        foreach (Db::all('SELECT DISTINCT supplier FROM products ORDER BY supplier') as $row) {
            $name = (string) $row['supplier'];
            $limit = self::limit($name);
            $used = self::used($name);

            $suppliers[] = [
                'supplier' => $name,
                'limit_per_min' => $limit,
                'used_last_min' => $used,
                'available' => $limit === 0 ? null : max(0, $limit - $used),
            ];
        }

        $queue = Db::one(
            "SELECT
                 count(*) FILTER (WHERE o.status = 'created')                                 AS unpaid,
                 count(*) FILTER (WHERE i.status = 'pending'
                                    AND o.status IN ('paid', 'delivering'))                   AS awaiting_delivery,
                 count(*) FILTER (WHERE i.status = 'delivering')                              AS in_flight,
                 count(*) FILTER (WHERE i.status = 'delivered')                               AS delivered,
                 count(*) FILTER (WHERE i.status = 'refunded')                                AS refunded,
                 count(*) FILTER (WHERE i.status IN ('out_of_stock', 'delivery_failed'))      AS stalled
             FROM order_items i JOIN orders o ON o.id = i.order_id"
        );

        return [
            'generated_at' => gmdate('c'),
            'suppliers' => $suppliers,
            'lines' => array_map('intval', $queue),
            'orders' => array_map('intval', (array) Db::one(
                "SELECT count(*) FILTER (WHERE status = 'delivered')            AS delivered,
                        count(*) FILTER (WHERE status = 'partially_delivered')  AS partially_delivered,
                        count(*) FILTER (WHERE status = 'refunded')             AS refunded,
                        count(*) FILTER (WHERE status IN ('paid', 'delivering')) AS in_progress,
                        count(*) FILTER (WHERE status = 'created')              AS awaiting_payment
                 FROM orders"
            )),
        ];
    }
}
