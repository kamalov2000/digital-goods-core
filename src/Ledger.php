<?php

declare(strict_types=1);

namespace App;

/**
 * Money journal. Three facts, all positive amounts:
 *
 *   payment_received    ref = event_id   the gateway confirmed the order total
 *   revenue_recognised  ref = item_id    a line item was delivered and kept
 *   refund_issued       ref = item_id    a line item could not be delivered and was refunded
 *
 * The identity every finished order must satisfy:
 *   payment_received = revenue_recognised + refund_issued
 */
final class Ledger
{
    /**
     * Idempotent by UNIQUE (order_id, type, ref): replaying the step that produced the fact
     * cannot book the same money twice. Always called inside the transaction that made the
     * fact true, so the journal can never disagree with the order.
     */
    public static function record(string $orderId, string $type, int $amount, string $ref): bool
    {
        $written = Db::run(
            'INSERT INTO ledger (order_id, type, amount, ref) VALUES (?, ?, ?, ?)
             ON CONFLICT (order_id, type, ref) DO NOTHING',
            [$orderId, $type, $amount, $ref],
        )->rowCount() === 1;

        if ($written) {
            Log::info('ledger.record', [
                'order_id' => $orderId,
                'result' => $type,
                'amount' => $amount,
                'ref' => $ref,
            ]);
        }

        return $written;
    }
}
