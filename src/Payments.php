<?php

declare(strict_types=1);

namespace App;

final class Payments
{
    /**
     * Webhook entry point. The event is persisted first and only then acted on, so the
     * dedup decision is made by postgres and never by application-level checking.
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
            Log::error('webhook rejected', ['event_id' => $eventId, 'order_id' => $orderId, 'reason' => 'bad_payload']);

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
            Log::info('webhook duplicate ignored', ['event_id' => $eventId, 'order_id' => $orderId]);

            return ['status' => 'duplicate', 'code' => 200];
        }

        Log::info('webhook accepted', ['event_id' => $eventId, 'order_id' => $orderId, 'payment_status' => $status]);

        // Stage 1 applies inline; stage 2 moves this behind bin/worker.php and the endpoint
        // returns as soon as the event row is committed.
        return ['status' => self::apply($eventId), 'code' => 200];
    }

    /**
     * Applies one stored event exactly once. Returns what actually happened.
     */
    public static function apply(string $eventId): string
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        // claiming the event and moving the order happen in one transaction, so a crash
        // in between cannot leave the event marked applied with the order untouched
        $claimed = Db::run(
            'UPDATE payment_events SET applied = true, applied_at = now()
             WHERE event_id = ? AND applied = false',
            [$eventId],
        )->rowCount();

        if ($claimed === 0) {
            $pdo->rollBack();

            return 'already_applied';
        }

        $event = Db::one('SELECT order_id, status FROM payment_events WHERE event_id = ?', [$eventId]);
        $orderId = (string) $event['order_id'];
        $target = $event['status'] === 'paid' ? 'paid' : 'payment_failed';

        $moved = Orders::transition($orderId, 'created', $target);

        if (!$moved && !Orders::exists($orderId)) {
            // Webhook arrived before the order was created. Roll the claim back so the event
            // stays pending and gets applied once the order shows up.
            $pdo->rollBack();
            Log::info('webhook ahead of order, left pending', ['event_id' => $eventId, 'order_id' => $orderId]);

            return 'order_missing';
        }

        $pdo->commit();

        if (!$moved) {
            // order already left 'created' - a final or in-flight order is never rewound
            return 'no_transition';
        }

        if ($target === 'paid') {
            Delivery::deliver($orderId);
        }

        return 'applied';
    }
}
