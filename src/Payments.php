<?php

declare(strict_types=1);

namespace App;

final class Payments
{
    /**
     * Webhook entry point. Persist the event, apply it with SQL only, answer 200.
     * No supplier call happens here - delivery is the worker's job, so the handler stays
     * fast and the payment gateway never waits on an external system.
     *
     * @param  array<string, mixed> $payload
     * @return array{status: string, code: int}
     */
    public static function handleWebhook(array $payload): array
    {
        $eventId = isset($payload['event_id']) ? (string) $payload['event_id'] : '';
        $orderId = isset($payload['order_id']) ? (string) $payload['order_id'] : '';
        $status = isset($payload['status']) ? (string) $payload['status'] : '';

        if ($eventId === '' || $orderId === '' || !in_array($status, ['paid', 'failed'], true)) {
            Log::error('payment.webhook', ['order_id' => $orderId, 'event_id' => $eventId, 'result' => 'bad_payload']);

            return ['status' => 'bad_request', 'code' => 400];
        }

        $inserted = Db::run(
            'INSERT INTO payment_events (event_id, order_id, status, amount, currency, payload)
             VALUES (?, ?, ?, ?, ?, ?::jsonb) ON CONFLICT (event_id) DO NOTHING',
            [
                $eventId,
                $orderId,
                $status,
                (int) ($payload['amount'] ?? 0),
                isset($payload['currency']) ? (string) $payload['currency'] : null,
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
        )->rowCount();

        // ON CONFLICT DO NOTHING inserted nothing => this event_id was already accepted.
        // Answer 200 immediately: a redelivery must never re-run the side effects.
        if ($inserted === 0) {
            Log::info('payment.webhook', ['order_id' => $orderId, 'event_id' => $eventId, 'result' => 'duplicate']);

            return ['status' => 'duplicate', 'code' => 200];
        }

        Log::info('payment.webhook', [
            'order_id' => $orderId,
            'event_id' => $eventId,
            'result' => 'accepted',
            'payment_status' => $status,
        ]);

        return ['status' => self::apply($eventId), 'code' => 200];
    }

    /**
     * Applies one stored event exactly once. Pure SQL - the delivery it may unlock is picked
     * up separately by the worker.
     *
     * Returns 'applied' | 'ignored' | 'order_missing' | 'already_applied'.
     */
    public static function apply(string $eventId): string
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        // The claim. Out of N concurrent appliers of the same event exactly one sees
        // rowCount 1, and claim + order transition commit together, so a crash in between
        // cannot leave the event marked applied with the order untouched.
        $claimed = Db::run(
            'UPDATE payment_events SET applied = true, applied_at = now()
             WHERE event_id = ? AND applied = false',
            [$eventId],
        )->rowCount();

        if ($claimed === 0) {
            $pdo->rollBack();

            return 'already_applied';
        }

        $event = Db::one('SELECT order_id, status, amount FROM payment_events WHERE event_id = ?', [$eventId]);
        $orderId = (string) $event['order_id'];
        $target = $event['status'] === 'paid' ? 'paid' : 'payment_failed';

        $moved = Orders::transition($orderId, 'created', $target);

        if (!$moved && !Orders::exists($orderId)) {
            // Webhook ahead of its order. Roll the claim back so applied stays false and the
            // worker retries later; the reason is recorded outside the transaction.
            $pdo->rollBack();
            Db::run('UPDATE payment_events SET result = ? WHERE event_id = ?', ['order_missing', $eventId]);
            Log::info('payment.apply', ['order_id' => $orderId, 'event_id' => $eventId, 'result' => 'order_missing']);

            return 'order_missing';
        }

        // The order exists but did not move. It may have been inserted between the two
        // statements above, so give the conditional UPDATE one more shot before concluding
        // that the order is genuinely past 'created'.
        if (!$moved) {
            $moved = Orders::transition($orderId, 'created', $target);
        }

        // 'ignored' still counts as consumed: a late or superseded event must never be
        // retried, otherwise the worker would pick it up on every pass forever.
        $result = $moved ? 'applied' : 'ignored';
        Db::run('UPDATE payment_events SET result = ? WHERE event_id = ?', [$result, $eventId]);

        // The only real money fact this domain has: the gateway confirmed a payment. It is
        // written in the same transaction that moved the order, so the journal can never
        // disagree with the order status. UNIQUE (order_id, type, ref) makes the write
        // idempotent - a replayed event_id cannot book the same money twice.
        if ($moved && $target === 'paid') {
            Db::run(
                'INSERT INTO ledger (order_id, type, amount, ref) VALUES (?, ?, ?, ?)
                 ON CONFLICT (order_id, type, ref) DO NOTHING',
                [$orderId, 'payment_received', (int) $event['amount'], $eventId],
            );
        }

        $pdo->commit();

        Log::info('payment.apply', [
            'order_id' => $orderId,
            'event_id' => $eventId,
            'result' => $result,
            'order_status' => $moved ? $target : null,
        ]);

        return $result;
    }

    /**
     * Worker duty #2: events that could not be applied because their order did not exist yet.
     * The join keeps the scan to events whose order has since shown up; apply() itself does
     * the claiming, so running several workers is safe.
     */
    public static function applyPending(int $limit): int
    {
        $pending = Db::all(
            'SELECT e.event_id, e.order_id FROM payment_events e
             JOIN orders o ON o.id = e.order_id
             WHERE e.applied = false
             ORDER BY e.received_at
             LIMIT ?',
            [$limit],
        );

        $done = 0;
        foreach ($pending as $row) {
            $result = self::apply((string) $row['event_id']);

            // order_missing does not count as progress, otherwise an event whose order never
            // arrives would keep the loop from ever sleeping
            if ($result === 'applied' || $result === 'ignored') {
                Log::info('payment.apply_pending', [
                    'order_id' => $row['order_id'],
                    'event_id' => $row['event_id'],
                    'result' => $result,
                ]);
                $done++;
            }
        }

        return $done;
    }
}
