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
     * Drives one order from paid to a terminal-ish status: delivered, out_of_stock or
     * delivery_failed. Tries supplier A, falls back to B, and never gives one order two codes.
     */
    public static function deliver(string $orderId): string
    {
        // paid -> delivering is the delivery lock: whoever wins it owns the issue attempt
        if (!Orders::transition($orderId, 'paid', 'delivering')) {
            Log::info('delivery skipped, order not paid', ['order_id' => $orderId]);

            return 'not_paid';
        }

        $order = Db::one('SELECT id, sku FROM orders WHERE id = ?', [$orderId]);
        $sku = (string) $order['sku'];

        $a = self::attempt('A', $orderId, $sku);
        if ($a['outcome'] === 'issued') {
            return self::finish($orderId, self::requestId($orderId, 'A'), (string) $a['code']);
        }

        // THE RULE: no fallback to B while A is 'unknown'.
        //
        // 'unknown' means we never got an answer - the supplier may well have reserved a key
        // and committed the issue. Asking B now would be asking for a second code for the same
        // order, and the first one would already be gone from the pool. Only a definite
        // refusal ('failed') or a definite empty stock ('out_of_stock') releases us to B.
        // A that ran out of retries while still unknown leaves the order in delivery_failed;
        // recovery (stage 4) resumes it by replaying the SAME request_id against A, which the
        // supplier answers with the code it already issued.
        if ($a['outcome'] === 'unknown') {
            Orders::transition($orderId, 'delivering', 'delivery_failed');
            Log::error('delivery unresolved, not falling back', [
                'order_id' => $orderId,
                'supplier' => 'A',
                'outcome' => 'unknown',
                'error' => $a['error'],
            ]);

            return 'delivery_failed';
        }

        Log::info('falling back to supplier B', ['order_id' => $orderId, 'reason' => $a['outcome']]);

        $b = self::attempt('B', $orderId, $sku);
        if ($b['outcome'] === 'issued') {
            return self::finish($orderId, self::requestId($orderId, 'B'), (string) $b['code']);
        }

        // Both empty is a stock problem, not an integration failure: recoverable by restocking.
        $target = $a['outcome'] === 'out_of_stock' && $b['outcome'] === 'out_of_stock'
            ? 'out_of_stock'
            : 'delivery_failed';

        Orders::transition($orderId, 'delivering', $target);
        Log::error('delivery failed on both suppliers', [
            'order_id' => $orderId,
            'supplier_a' => $a['outcome'],
            'supplier_b' => $b['outcome'],
            'status' => $target,
        ]);

        return $target;
    }

    /**
     * One supplier, up to DELIVERY_MAX_ATTEMPTS calls, always under the same request_id.
     *
     * Only 'unknown' is retried. A definite refusal or an empty pool is an answer, and
     * repeating the call would not change it.
     *
     * @return array{outcome: string, code: ?string, error: ?string}
     */
    private static function attempt(string $supplier, string $orderId, string $sku): array
    {
        $requestId = self::requestId($orderId, $supplier);
        $maxAttempts = max(1, Env::int('DELIVERY_MAX_ATTEMPTS', 4));
        $result = ['outcome' => 'failed', 'code' => null, 'error' => 'no attempt made'];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            self::track($requestId, $orderId, $supplier);

            $result = self::callSupplier($supplier, $requestId, $orderId, $sku);

            Log::info('supplier attempt', [
                'order_id' => $orderId,
                'request_id' => $requestId,
                'attempt' => $attempt,
                'outcome' => $result['outcome'],
                'error' => $result['error'],
            ]);

            if ($result['outcome'] === 'issued') {
                return $result;
            }

            self::storeFailure($requestId, $result);

            if ($result['outcome'] !== 'unknown') {
                break;
            }

            if ($attempt < $maxAttempts) {
                self::backoff($attempt);
            }
        }

        return $result;
    }

    /**
     * Records that an attempt is about to happen. On a retry only the counter moves: the
     * previous status is left in place, so a worker that dies mid-call leaves the row saying
     * 'unknown' rather than a cheerful 'pending'.
     */
    private static function track(string $requestId, string $orderId, string $supplier): void
    {
        Db::run(
            'INSERT INTO issue_requests (request_id, order_id, supplier, status, attempts)
             VALUES (?, ?, ?, ?, 1)
             ON CONFLICT (request_id) DO UPDATE
                 SET attempts = issue_requests.attempts + 1, updated_at = now()',
            [$requestId, $orderId, $supplier, 'pending'],
        );
    }

    /** @param array{outcome: string, code: ?string, error: ?string} $result */
    private static function storeFailure(string $requestId, array $result): void
    {
        Db::run(
            'UPDATE issue_requests SET status = ?, last_error = ?, updated_at = now() WHERE request_id = ?',
            [$result['outcome'], $result['error'], $requestId],
        );
    }

    /**
     * Persisting the code and finishing the order happen together, so the order can never be
     * delivered without a recorded code, nor hold a code while still sitting in 'delivering'.
     */
    private static function finish(string $orderId, string $requestId, string $code): string
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        Db::run(
            'UPDATE issue_requests SET status = ?, code = ?, last_error = NULL, updated_at = now()
             WHERE request_id = ?',
            ['issued', $code, $requestId],
        );
        Orders::transition($orderId, 'delivering', 'delivered');

        $pdo->commit();

        Log::info('code delivered', ['order_id' => $orderId, 'request_id' => $requestId]);

        return 'delivered';
    }

    /** Exponential backoff with full jitter, so parallel workers do not retry in lockstep. */
    private static function backoff(int $attempt): void
    {
        $base = Env::int('DELIVERY_BACKOFF_BASE_MS', 200);
        $cap = Env::int('DELIVERY_BACKOFF_CAP_MS', 5000);
        $window = min($cap, $base * (2 ** ($attempt - 1)));

        usleep(random_int(0, max(1, $window)) * 1000);
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
            CURLOPT_CONNECTTIMEOUT => Env::int('DELIVERY_CONNECT_TIMEOUT_SEC', 2),
            CURLOPT_TIMEOUT => Env::int('DELIVERY_TIMEOUT_SEC', 5),
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            // The split that stage 3 turns on. Left column: the request was sent but no
            // complete answer came back - the supplier may have issued, so this is 'unknown'
            // and may only be resolved by replaying the same request_id. Everything else
            // never reached the supplier and is a safe, definite failure.
            $unknown = [
                CURLE_OPERATION_TIMEDOUT,
                CURLE_PARTIAL_FILE,
                CURLE_RECV_ERROR,
                CURLE_SEND_ERROR,
                CURLE_GOT_NOTHING,
            ];
            $outcome = in_array($errno, $unknown, true) ? 'unknown' : 'failed';

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

        // A complete 4xx/5xx answer is a refusal we can trust: the supplier told us it did
        // nothing, so falling back to the other one cannot double-issue.
        return [
            'outcome' => 'failed',
            'code' => null,
            'error' => 'http:' . $httpCode . ' ' . substr((string) $response, 0, 200),
        ];
    }
}
