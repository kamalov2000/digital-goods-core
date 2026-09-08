<?php

declare(strict_types=1);

namespace App;

final class Refunds
{
    /**
     * Worker duty #4: lines we have taken money for but could not deliver.
     *
     * The deadline is anchored to the payment, not to the line's own updated_at. Recovery
     * touches updated_at on every retry, so anchoring there would push the deadline forward
     * forever and a permanently undeliverable line would never be refunded.
     *
     * Only orders with a payment_received entry are eligible - refunding money that never
     * arrived would break the identity the journal exists to prove.
     */
    public static function runPending(int $limit): int
    {
        $due = Db::all(
            "SELECT i.id, i.order_id, i.amount, i.status
             FROM order_items i
             JOIN ledger l ON l.order_id = i.order_id AND l.type = 'payment_received'
             WHERE i.status IN ('out_of_stock', 'delivery_failed')
               AND l.created_at < now() - make_interval(secs => ?)
             ORDER BY l.created_at
             LIMIT ?",
            [Env::int('REFUND_AFTER_SEC', 300), $limit],
        );

        $done = 0;
        foreach ($due as $row) {
            if (self::refund((string) $row['id'], (string) $row['order_id'], (int) $row['amount'], (string) $row['status'])) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Refunds one line. The conditional move out of the exact status we read is the claim, and
     * the ledger entry shares its transaction, so a replay can produce neither a second refund
     * nor a refund without a line to justify it.
     */
    public static function refund(string $itemId, string $orderId, int $amount, string $from): bool
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        if (!Orders::moveItem($itemId, $from, 'refunded')) {
            $pdo->rollBack();

            return false;
        }

        Ledger::record($orderId, 'refund_issued', $amount, $itemId);
        $pdo->commit();

        Log::info('refund.issued', [
            'order_id' => $orderId,
            'item_id' => $itemId,
            'result' => 'refunded',
            'amount' => $amount,
            'from' => $from,
        ]);

        Orders::settle($orderId);

        return true;
    }
}
