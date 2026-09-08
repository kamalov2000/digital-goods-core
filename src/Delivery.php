<?php

declare(strict_types=1);

namespace App;

final class Delivery
{
    /**
     * Deterministic request_id, now keyed by the line item. This is the whole timeout trap: a
     * retry of a call that timed out reuses the identifier, so the supplier replays the code it
     * already issued instead of pulling a second key out of the pool.
     */
    public static function requestId(string $itemId, string $supplier): string
    {
        return "req_{$itemId}_{$supplier}";
    }

    /** The other supplier, used as the fallback for a line. */
    public static function fallbackOf(string $supplier): string
    {
        return $supplier === 'A' ? 'B' : 'A';
    }

    /**
     * Worker duty #1: line items waiting for a code on an order whose money already arrived.
     *
     * The SELECT is deliberately unlocked - it only nominates candidates. The claim happens in
     * claim(), where the conditional UPDATE pending -> delivering lets exactly one worker
     * through, so two workers scanning the same batch cannot both call a supplier for one line.
     */
    public static function runPending(int $limit): int
    {
        $items = Db::all(
            "SELECT i.id FROM order_items i
             JOIN orders o ON o.id = i.order_id
             WHERE i.status = 'pending' AND o.status IN ('paid', 'delivering')
             ORDER BY i.created_at
             LIMIT ?",
            [$limit],
        );

        $done = 0;
        foreach ($items as $row) {
            if (self::deliverItem((string) $row['id'])) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Worker duty #3: lines that stalled. Recovery never starts a fresh supplier cycle while an
     * attempt is unresolved - see issueItem().
     *
     * 'delivering' is in the list because a worker killed mid-call leaves a line there with
     * nothing to move it on. Reclaiming it is safe even if that worker is alive: both would call
     * the SAME deterministic request_id, and the supplier answers a repeated request_id with the
     * code it already issued.
     */
    public static function runRecovery(int $limit): int
    {
        $stuck = Db::all(
            "SELECT i.id, i.status FROM order_items i
             JOIN orders o ON o.id = i.order_id
             WHERE i.status IN ('delivery_failed', 'out_of_stock', 'delivering')
               AND i.updated_at < now() - make_interval(secs => ?)
               AND o.status NOT IN ('created', 'payment_failed')
             ORDER BY i.updated_at
             LIMIT ?",
            [Env::int('RECOVERY_DELAY_SEC', 30), $limit],
        );

        $done = 0;
        foreach ($stuck as $row) {
            $itemId = (string) $row['id'];

            if (!Orders::moveItem($itemId, (string) $row['status'], 'delivering')) {
                continue;
            }

            Log::info('recovery.claim', [
                'item_id' => $itemId,
                'result' => 'claimed',
                'from' => $row['status'],
            ]);

            // the order may have settled into a failed state while this line was stuck
            Orders::settle((string) Db::one('SELECT order_id FROM order_items WHERE id = ?', [$itemId])['order_id']);

            self::issueItem($itemId);
            $done++;
        }

        return $done;
    }

    /**
     * Drives every line of one order. Used by the API smoke tests, which want the whole thing to
     * happen synchronously; the worker uses runPending() instead.
     */
    public static function deliver(string $orderId): string
    {
        $items = Db::all('SELECT id FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);

        foreach ($items as $row) {
            self::deliverItem((string) $row['id']);
        }

        $order = Db::one('SELECT status FROM orders WHERE id = ?', [$orderId]);

        return (string) $order['status'];
    }

    /** Claims one line and issues it. Returns false when another worker owned the claim. */
    private static function deliverItem(string $itemId): bool
    {
        if (!Orders::moveItem($itemId, 'pending', 'delivering')) {
            return false;
        }

        $item = Db::one('SELECT order_id FROM order_items WHERE id = ?', [$itemId]);
        $orderId = (string) $item['order_id'];

        // settle() deliberately keeps its hands off the payment path, so the bridge out of
        // 'paid' is an explicit conditional transition. Whoever claims the first line of the
        // order wins it; everyone else gets rowCount 0 and moves on.
        Orders::transition($orderId, 'paid', 'delivering');

        self::issueItem($itemId);

        return true;
    }

    /**
     * Issues a code for a line this process has already claimed into 'delivering'.
     * Tries the line's own supplier, falls back to the other, and never gives one line two codes.
     */
    private static function issueItem(string $itemId): string
    {
        $item = Db::one('SELECT id, order_id, sku, supplier FROM order_items WHERE id = ?', [$itemId]);
        $orderId = (string) $item['order_id'];
        $sku = (string) $item['sku'];
        $primary = (string) $item['supplier'];

        // An unresolved attempt owns this line. That supplier may already be holding a code for
        // it, so the only safe move is to replay ITS request_id - never to start a fresh cycle,
        // which is how a recovery pass would hand out a second key. attempt() reads the sticky
        // 'unknown' back from the row, so a refusal here cannot release us to the other supplier.
        $unresolved = Db::one(
            "SELECT supplier FROM issue_requests
             WHERE item_id = ? AND status = 'unknown'
             ORDER BY updated_at LIMIT 1",
            [$itemId],
        );

        if ($unresolved !== null) {
            $supplier = (string) $unresolved['supplier'];
            $resumed = self::attempt($supplier, $itemId, $orderId, $sku);

            if ($resumed['outcome'] === 'issued') {
                return self::finish($itemId, $orderId, $supplier, (string) $resumed['code']);
            }

            return self::fail($itemId, $orderId, 'delivery_failed', ['reason' => 'still_unresolved']);
        }

        $a = self::attempt($primary, $itemId, $orderId, $sku);
        if ($a['outcome'] === 'issued') {
            return self::finish($itemId, $orderId, $primary, (string) $a['code']);
        }

        // THE RULE: no fallback while the primary supplier is 'unknown'.
        //
        // 'unknown' means we never got an answer - the supplier may well have reserved a key and
        // committed the issue. Asking the other one now would be asking for a second code for the
        // same line, and the first would already be gone from the pool. Only a definite refusal
        // ('failed') or a definite empty stock ('out_of_stock') releases us. A line that ran out
        // of retries while still unknown waits for recovery, which replays the SAME request_id.
        if ($a['outcome'] === 'unknown') {
            return self::fail($itemId, $orderId, 'delivery_failed', ['reason' => 'primary_unresolved']);
        }

        $fallback = self::fallbackOf($primary);
        Log::info('delivery.fallback', [
            'order_id' => $orderId,
            'item_id' => $itemId,
            'result' => 'to_' . strtolower($fallback),
            'reason' => $a['outcome'],
        ]);

        $b = self::attempt($fallback, $itemId, $orderId, $sku);
        if ($b['outcome'] === 'issued') {
            return self::finish($itemId, $orderId, $fallback, (string) $b['code']);
        }

        // Both empty is a stock problem, not an integration failure: recoverable by restocking,
        // and refundable once the refund deadline passes.
        $target = $a['outcome'] === 'out_of_stock' && $b['outcome'] === 'out_of_stock'
            ? 'out_of_stock'
            : 'delivery_failed';

        return self::fail($itemId, $orderId, $target, ['primary' => $a['outcome'], 'fallback' => $b['outcome']]);
    }

    /**
     * Persisting the code, finishing the line and recognising the money happen together, so a
     * line can never be delivered without a recorded code or without its ledger entry.
     */
    private static function finish(string $itemId, string $orderId, string $supplier, string $code): string
    {
        $requestId = self::requestId($itemId, $supplier);
        $amount = (int) Db::one('SELECT amount FROM order_items WHERE id = ?', [$itemId])['amount'];

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        Db::run(
            'UPDATE issue_requests SET status = ?, code = ?, last_error = NULL, updated_at = now()
             WHERE request_id = ?',
            ['issued', $code, $requestId],
        );
        Db::run(
            'UPDATE order_items SET status = ?, code = ?, supplier = ?, updated_at = now()
             WHERE id = ? AND status = ?',
            ['delivered', $code, $supplier, $itemId, 'delivering'],
        );
        Ledger::record($orderId, 'revenue_recognised', $amount, $itemId);

        $pdo->commit();

        Log::info('delivery.finish', [
            'order_id' => $orderId,
            'item_id' => $itemId,
            'request_id' => $requestId,
            'result' => 'delivered',
        ]);

        Orders::settle($orderId);

        return 'delivered';
    }

    /** @param array<string, mixed> $context */
    private static function fail(string $itemId, string $orderId, string $status, array $context): string
    {
        Orders::moveItem($itemId, 'delivering', $status);

        Log::error('delivery.finish', ['order_id' => $orderId, 'item_id' => $itemId, 'result' => $status] + $context);

        Orders::settle($orderId);

        return $status;
    }

    /**
     * One supplier, up to DELIVERY_MAX_ATTEMPTS calls, always under the same request_id.
     *
     * Only 'unknown' is retried. A definite refusal or an empty pool is an answer, and repeating
     * the call would not change it.
     *
     * @return array{outcome: string, code: ?string, error: ?string}
     */
    private static function attempt(string $supplier, string $itemId, string $orderId, string $sku): array
    {
        $requestId = self::requestId($itemId, $supplier);
        $maxAttempts = max(1, Env::int('DELIVERY_MAX_ATTEMPTS', 4));
        $result = ['outcome' => 'failed', 'code' => null, 'error' => 'no attempt made'];

        // Read the state left by earlier passes: once this request_id has been 'unknown', it
        // stays unknown across worker restarts until a supplier hands us the code.
        $prior = Db::one('SELECT status FROM issue_requests WHERE request_id = ?', [$requestId]);
        $sawUnknown = ($prior['status'] ?? '') === 'unknown';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            self::track($requestId, $itemId, $orderId, $supplier);

            $result = self::callSupplier($supplier, $requestId, $orderId, $sku);

            Log::info('supplier.call', [
                'order_id' => $orderId,
                'item_id' => $itemId,
                'request_id' => $requestId,
                'result' => $result['outcome'],
                'supplier' => $supplier,
                'attempt' => $attempt,
                'error' => $result['error'],
            ]);

            if ($result['outcome'] === 'issued') {
                return $result;
            }

            $sawUnknown = $sawUnknown || $result['outcome'] === 'unknown';

            self::storeFailure($requestId, $result);

            if ($result['outcome'] !== 'unknown') {
                break;
            }

            if ($attempt < $maxAttempts) {
                self::backoff($attempt);
            }
        }

        // Uncertainty is sticky. Once an answer for this request_id was lost, a later 5xx or 409
        // on the SAME request_id refuses that call - it does not retract what an earlier call may
        // already have issued. Only the supplier handing us the code resolves it. Downgrading to
        // 'failed' here would authorise the fallback and hand the line a second key while the
        // first one sits reserved.
        if ($sawUnknown) {
            $result['outcome'] = 'unknown';
            self::storeFailure($requestId, $result);
        }

        return $result;
    }

    /**
     * Records that an attempt is about to happen. On a retry only the counter moves: the previous
     * status is left in place, so a worker that dies mid-call leaves the row saying 'unknown'
     * rather than a cheerful 'pending'.
     */
    private static function track(string $requestId, string $itemId, string $orderId, string $supplier): void
    {
        Db::run(
            'INSERT INTO issue_requests (request_id, order_id, item_id, supplier, status, attempts)
             VALUES (?, ?, ?, ?, ?, 1)
             ON CONFLICT (request_id) DO UPDATE
                 SET attempts = issue_requests.attempts + 1, updated_at = now()',
            [$requestId, $orderId, $itemId, $supplier, 'pending'],
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
            // The split that matters. Left column: the request was sent but no complete answer
            // came back - the supplier may have issued, so this is 'unknown' and may only be
            // resolved by replaying the same request_id. Everything else never reached the
            // supplier and is a safe, definite failure.
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
