<?php

declare(strict_types=1);

namespace App;

final class Delivery
{
    /**
     * Deterministic request_id. This is the whole timeout trap: a retry of a call that
     * timed out reuses the identifier, so the supplier replays the code it already issued
     * instead of pulling a second key out of the pool.
     */
    public static function requestId(string $orderId, string $supplier): string
    {
        return "req_{$orderId}_{$supplier}";
    }

    /**
     * Worker duty #1: orders sitting in 'paid' waiting for a code.
     *
     * The SELECT is deliberately unlocked - it only nominates candidates. The claim happens
     * inside deliver(), where the conditional UPDATE paid -> delivering lets exactly one
     * worker through, so two workers scanning the same batch cannot both call the supplier.
     */
    public static function runPending(int $limit): int
    {
        $orders = Db::all(
            'SELECT id FROM orders WHERE status = ? ORDER BY updated_at LIMIT ?',
            ['paid', $limit],
        );

        $done = 0;
        foreach ($orders as $row) {
            if (self::deliver((string) $row['id']) !== 'not_paid') {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Drives one order from paid to delivered. Stage 2 talks to supplier A only;
     * retries with backoff and the fallback to B land in stage 3.
     */
    public static function deliver(string $orderId): string
    {
        // paid -> delivering is the delivery lock: whoever wins it owns the issue attempt
        if (!Orders::transition($orderId, 'paid', 'delivering')) {
            Log::info('delivery skipped, order not paid', ['order_id' => $orderId]);

            return 'not_paid';
        }

        $order = Db::one('SELECT id, sku FROM orders WHERE id = ?', [$orderId]);
        $supplier = 'A';
        $requestId = self::requestId($orderId, $supplier);

        Db::run(
            'INSERT INTO issue_requests (request_id, order_id, supplier, status, attempts)
             VALUES (?, ?, ?, ?, 1)
             ON CONFLICT (request_id) DO UPDATE
                 SET attempts = issue_requests.attempts + 1, status = ?, updated_at = now()',
            [$requestId, $orderId, $supplier, 'pending', 'pending'],
        );

        $result = self::callSupplier($supplier, $requestId, $orderId, (string) $order['sku']);

        return self::record($orderId, $requestId, $result);
    }

    /**
     * @param  array{outcome: string, code: ?string, error: ?string} $result
     */
    private static function record(string $orderId, string $requestId, array $result): string
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        if ($result['outcome'] === 'issued') {
            Db::run(
                'UPDATE issue_requests SET status = ?, code = ?, last_error = NULL, updated_at = now()
                 WHERE request_id = ?',
                ['issued', $result['code'], $requestId],
            );
            $pdo->commit();

            Orders::transition($orderId, 'delivering', 'delivered');
            Log::info('code delivered', ['order_id' => $orderId, 'request_id' => $requestId]);

            return 'delivered';
        }

        Db::run(
            'UPDATE issue_requests SET status = ?, last_error = ?, updated_at = now() WHERE request_id = ?',
            [$result['outcome'], $result['error'], $requestId],
        );
        $pdo->commit();

        // both are recoverable states, not crashes: stock refill or a background retry resumes them
        $target = $result['outcome'] === 'out_of_stock' ? 'out_of_stock' : 'delivery_failed';
        Orders::transition($orderId, 'delivering', $target);

        Log::error('delivery failed', [
            'order_id' => $orderId,
            'request_id' => $requestId,
            'outcome' => $result['outcome'],
            'error' => $result['error'],
        ]);

        return $target;
    }

    /**
     * @return array{outcome: string, code: ?string, error: ?string}
     */
    private static function callSupplier(string $supplier, string $requestId, string $orderId, string $sku): array
    {
        $url = rtrim(Env::get('SUPPLIER_' . $supplier . '_URL', 'http://127.0.0.1:9001'), '/') . '/issue';
        $body = (string) json_encode(['request_id' => $requestId, 'sku' => $sku, 'order_id' => $orderId]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => Env::int('DELIVERY_TIMEOUT_SEC', 3),
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            // a timeout is not a refusal - the supplier may have issued the code anyway,
            // so this is recorded as 'unknown' and resolved by replaying the same request_id
            $outcome = $errno === CURLE_OPERATION_TIMEDOUT ? 'unknown' : 'failed';

            return ['outcome' => $outcome, 'code' => null, 'error' => 'curl:' . curl_strerror($errno)];
        }

        $decoded = json_decode((string) $response, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($httpCode === 200 && ($decoded['status'] ?? '') === 'ok' && !empty($decoded['code'])) {
            return ['outcome' => 'issued', 'code' => (string) $decoded['code'], 'error' => null];
        }

        if (($decoded['reason'] ?? '') === 'out_of_stock') {
            return ['outcome' => 'out_of_stock', 'code' => null, 'error' => 'out_of_stock'];
        }

        return [
            'outcome' => 'failed',
            'code' => null,
            'error' => 'http:' . $httpCode . ' ' . substr((string) $response, 0, 200),
        ];
    }
}
