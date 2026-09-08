<?php

declare(strict_types=1);

namespace Tests;

use App\Db;
use App\Delivery;
use App\Env;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the stage 1 API surface. They run against a live stack: postgres, both
 * supplier stubs and the app itself (see the run commands in the Makefile).
 *
 * Since stage 2 the webhook handler no longer delivers - bin/worker.php does. These tests
 * drive one delivery step synchronously via deliverNow() so they stay deterministic and do
 * not need a worker process; the real worker loop is exercised by tests/race.sh.
 */
final class Stage1SmokeTest extends TestCase
{
    private const SKU = 'KEY-CS2-PRIME';
    private const PRICE = 1290;

    private static string $base;

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim(Env::get('APP_URL', 'http://localhost:8000'), '/');
    }

    protected function setUp(): void
    {
        [$code] = self::request('GET', self::$base . '/health');
        if ($code !== 200) {
            self::markTestSkipped('app is not running on ' . self::$base);
        }
    }

    public function testCreateOrderReturnsIdAndCatalogPrice(): void
    {
        [$status, $order] = self::request('POST', self::$base . '/api/orders', ['sku' => self::SKU]);

        self::assertSame(201, $status);
        self::assertStringStartsWith('ord_', $order['id']);
        self::assertSame(self::SKU, $order['sku']);
        self::assertSame(self::PRICE, $order['amount']);
        self::assertSame('RUB', $order['currency']);
        self::assertSame('created', $order['status']);
        self::assertNull($order['code']);

        [$status, $fetched] = self::request('GET', self::$base . '/api/orders/' . $order['id']);
        self::assertSame(200, $status);
        self::assertSame($order['id'], $fetched['id']);
    }

    public function testUnknownSkuIsRejected(): void
    {
        [$status, $body] = self::request('POST', self::$base . '/api/orders', ['sku' => 'NO-SUCH-SKU']);

        self::assertSame(404, $status);
        self::assertSame('unknown_sku', $body['reason']);
    }

    public function testPaidWebhookDeliversAKey(): void
    {
        $orderId = self::createOrder();

        [$status, $body] = self::webhook($orderId, 'paid', self::eventId());
        self::assertSame(200, $status);
        self::assertSame('applied', $body['result']);

        // the handler is SQL only: it must not have called a supplier
        [, $paid] = self::request('GET', self::$base . '/api/orders/' . $orderId);
        self::assertSame('paid', $paid['status']);
        self::assertNull($paid['code']);

        self::deliverNow($orderId);

        [, $order] = self::request('GET', self::$base . '/api/orders/' . $orderId);
        self::assertSame('delivered', $order['status']);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) $order['code']);

        // the key left the pool bound to this order, exactly once
        $reserved = Db::all('SELECT code FROM key_pool WHERE order_id = ?', [$orderId]);
        self::assertCount(1, $reserved);
        self::assertSame($order['code'], $reserved[0]['code']);

        $requests = Db::all(
            'SELECT r.request_id, r.status, r.item_id, r.supplier FROM issue_requests r WHERE r.order_id = ?',
            [$orderId],
        );
        self::assertCount(1, $requests);
        self::assertSame('issued', $requests[0]['status']);

        // request_id is keyed by the line item, which is what makes a retry replayable per line
        $item = Db::one('SELECT id FROM order_items WHERE order_id = ?', [$orderId]);
        self::assertSame(
            'req_' . $item['id'] . '_' . $requests[0]['supplier'],
            $requests[0]['request_id'],
        );
    }

    public function testRepeatedEventIdChangesNothing(): void
    {
        $orderId = self::createOrder();
        $eventId = self::eventId();

        [, $first] = self::webhook($orderId, 'paid', $eventId);
        self::assertSame('applied', $first['result']);
        self::deliverNow($orderId);

        [, $delivered] = self::request('GET', self::$base . '/api/orders/' . $orderId);
        self::assertSame('delivered', $delivered['status']);

        [$status, $second] = self::webhook($orderId, 'paid', $eventId);
        self::assertSame(200, $status);
        self::assertSame('duplicate', $second['result']);

        [, $after] = self::request('GET', self::$base . '/api/orders/' . $orderId);
        self::assertSame($delivered['status'], $after['status']);
        self::assertSame($delivered['code'], $after['code']);
        self::assertSame($delivered['updated_at'], $after['updated_at']);

        self::assertSame(1, self::rowCount('SELECT count(*) FROM payment_events WHERE order_id = ?', [$orderId]));
        self::assertSame(1, self::rowCount('SELECT count(*) FROM issue_requests WHERE order_id = ?', [$orderId]));
        self::assertSame(1, self::rowCount('SELECT count(*) FROM key_pool WHERE order_id = ?', [$orderId]));
    }

    public function testFailedWebhookMarksPaymentFailed(): void
    {
        $orderId = self::createOrder();

        [, $body] = self::webhook($orderId, 'failed', self::eventId());
        self::assertSame('applied', $body['result']);

        [, $order] = self::request('GET', self::$base . '/api/orders/' . $orderId);
        self::assertSame('payment_failed', $order['status']);
        self::assertNull($order['code']);
        self::assertSame(0, self::rowCount('SELECT count(*) FROM issue_requests WHERE order_id = ?', [$orderId]));
    }

    public function testWebhookForAnUnknownOrderIsStoredAndStaysPending(): void
    {
        $orderId = 'ord_' . bin2hex(random_bytes(8));
        $eventId = self::eventId();

        [$status, $body] = self::webhook($orderId, 'paid', $eventId);

        self::assertSame(200, $status);
        self::assertSame('order_missing', $body['result']);

        $event = Db::one('SELECT applied, result FROM payment_events WHERE event_id = ?', [$eventId]);
        self::assertNotNull($event);
        self::assertFalse($event['applied']);
        self::assertSame('order_missing', $event['result']);
    }

    public function testEventThatCannotMoveTheOrderIsConsumedNotRetried(): void
    {
        $orderId = self::createOrder();

        [, $first] = self::webhook($orderId, 'paid', self::eventId());
        self::assertSame('applied', $first['result']);

        // a late 'failed' for an already paid order: transitions only start from 'created'
        $lateEventId = self::eventId();
        [, $late] = self::webhook($orderId, 'failed', $lateEventId);
        self::assertSame('ignored', $late['result']);

        // ignored still counts as consumed, otherwise the worker would pick it up forever
        $event = Db::one('SELECT applied FROM payment_events WHERE event_id = ?', [$lateEventId]);
        self::assertTrue($event['applied']);

        [, $order] = self::request('GET', self::$base . '/api/orders/' . $orderId);
        self::assertSame('paid', $order['status']);
    }

    /** Runs the exact claim-and-issue step bin/worker.php would run for this order. */
    private static function deliverNow(string $orderId): void
    {
        self::assertSame('delivered', Delivery::deliver($orderId));
    }

    private static function eventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(6));
    }

    private static function createOrder(): string
    {
        [$status, $order] = self::request('POST', self::$base . '/api/orders', ['sku' => self::SKU]);
        self::assertSame(201, $status);

        return (string) $order['id'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function webhook(string $orderId, string $status, string $eventId): array
    {
        return self::request('POST', self::$base . '/webhook/payment', [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'status' => $status,
            'amount' => self::PRICE,
            'currency' => 'RUB',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * @param  array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = (string) json_encode($body);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response === false ? '' : (string) $response, true);

        return [$code, is_array($decoded) ? $decoded : []];
    }

    /** @param array<int, mixed> $params */
    private static function rowCount(string $sql, array $params): int
    {
        return (int) array_values((array) Db::one($sql, $params))[0];
    }
}
