<?php

declare(strict_types=1);

namespace App;

/**
 * Everything the supplier got wrong, and how it was put right without a human.
 */
final class SupplierAudit
{
    /**
     * Idempotent by UNIQUE (request_id, kind, code): running into the same lie twice records it
     * once. Recording never blocks delivery - it is an audit trail, not a gate.
     */
    public static function record(
        string $kind,
        string $requestId,
        string $orderId,
        ?string $itemId,
        string $supplier,
        ?string $code,
        string $detail,
    ): void {
        Db::run(
            'INSERT INTO supplier_discrepancies (request_id, order_id, item_id, supplier, kind, code, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (request_id, kind, code) DO NOTHING',
            [$requestId, $orderId, $itemId, $supplier, $kind, $code, $detail],
        );

        Log::error('supplier.discrepancy', [
            'order_id' => $orderId,
            'item_id' => $itemId,
            'request_id' => $requestId,
            'result' => $kind,
            'supplier' => $supplier,
            'code' => $code,
            'detail' => $detail,
        ]);
    }

    public static function resolve(string $requestId, string $kind, ?string $code, string $resolution): void
    {
        Db::run(
            'UPDATE supplier_discrepancies
             SET resolved_at = now(), resolution = ?
             WHERE request_id = ? AND kind = ? AND code IS NOT DISTINCT FROM ? AND resolved_at IS NULL',
            [$resolution, $requestId, $kind, $code],
        );
    }

    /**
     * Worker duty: keys the supplier took out of the pool for an order but that never reached a
     * line. They are the residue of a lie or of a lost answer.
     *
     * A key is only released once the line it was meant for is closed for good - delivered by
     * someone else, or refunded. While the line is still open, recovery may yet replay the same
     * request_id and the supplier will hand this very code back, so taking it away would turn a
     * recoverable order into a broken one.
     */
    public static function runAudit(int $limit): int
    {
        $orphans = Db::all(
            "SELECT k.code, k.order_id, k.sku, s.request_id, s.supplier,
                    substring(s.request_id from 'itm_[0-9a-f]+') AS item_id
             FROM key_pool k
             JOIN supplier_issues s ON s.code = k.code
             WHERE k.order_id IS NOT NULL
               AND k.reserved_at < now() - make_interval(secs => ?)
               AND NOT EXISTS (SELECT 1 FROM order_items i WHERE i.code = k.code)
             ORDER BY k.reserved_at
             LIMIT ?",
            [Env::int('AUDIT_GRACE_SEC', 60), $limit],
        );

        $done = 0;
        foreach ($orphans as $row) {
            $code = (string) $row['code'];
            $requestId = (string) $row['request_id'];
            $itemId = $row['item_id'] === null ? null : (string) $row['item_id'];

            self::record(
                'orphaned_reservation',
                $requestId,
                (string) $row['order_id'],
                $itemId,
                (string) $row['supplier'],
                $code,
                'key reserved but attached to no line',
            );

            $item = $itemId === null
                ? null
                : Db::one('SELECT status FROM order_items WHERE id = ?', [$itemId]);

            // still in play - leave the key where it is, recovery may still claim it
            if ($item !== null && !in_array($item['status'], ['delivered', 'refunded'], true)) {
                continue;
            }

            if (self::release($code)) {
                self::resolve($requestId, 'orphaned_reservation', $code, 'returned to pool');
                Log::info('supplier.audit', [
                    'order_id' => $row['order_id'],
                    'item_id' => $itemId,
                    'request_id' => $requestId,
                    'result' => 'key_returned_to_pool',
                    'code' => $code,
                ]);
                $done++;
            }
        }

        return $done;
    }

    /**
     * Puts one key back on the shelf. The storefront counter moves in the same transaction that
     * frees the key, exactly as it does when a key is taken out.
     */
    private static function release(string $code): bool
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        $freed = Db::one(
            'UPDATE key_pool SET order_id = NULL, reserved_at = NULL
             WHERE code = ? AND order_id IS NOT NULL
             RETURNING sku',
            [$code],
        );

        if ($freed === null) {
            $pdo->rollBack();

            return false;
        }

        if ($freed['sku'] !== null) {
            Db::run(
                'UPDATE stock SET available = available + 1, updated_at = now() WHERE sku = ?',
                [$freed['sku']],
            );
        }

        $pdo->commit();

        return true;
    }
}
