<?php

declare(strict_types=1);

namespace Tests;

use App\Db;
use App\Env;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for stage 1. They run against a live stack: postgres, both supplier stubs
 * and the app itself (see the run commands in the Makefile).
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

        [, $order] = self::request('GET', self::$base . '/api/orders/' . $orderId);
        self::assertSame('delivered', $order['status']);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) $order['code']);

        // the key left the pool bound to this order, exactly once
        $reserved = Db::all('SELECT code FROM key_pool WHERE order_id = ?', [$orderId]);
        self::assertCount(1, $reserved);
        self::assertSame($order['code'], $reserved[0]['code']);

        $requests = Db::all('SELECT request_id, status FROM issue_requests WHERE order_id = ?', [$orderId]);
        self::assertCount(1, $requests);
        self::assertSame('req_' . $orderId . '_A', $requests[0]['request_id']);
        self::assertSame('issued', $requests[0]['status']);
    }

    public function testRepeatedEventIdChangesNothing(): void
    {
        $orderId = self::createOrder();
        $eventId = self::eventId();

        [, $first] = self::webhook($orderId, 'paid', $eventId);
        self::assertSame('applied', $first['result']);

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

        $event = Db::one('SELECT applied FROM payment_events WHERE event_id = ?', [$eventId]);
        self::assertNotNull($event);
        self::assertFalse($event['applied']);
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
