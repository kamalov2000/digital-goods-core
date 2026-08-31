<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Env;
use App\Http;
use App\Log;
use App\Orders;
use App\Payments;
use App\Reconcile;
use App\Router;
use App\Stock;

$router = new Router();

$router->add('POST', '/api/orders', function (): void {
    $body = Http::body();
    $sku = isset($body['sku']) ? trim((string) $body['sku']) : '';

    if ($sku === '') {
        Http::error('sku_required', 400);

        return;
    }

    $order = Orders::create($sku);
    if ($order === null) {
        Http::error('unknown_sku', 404);

        return;
    }

    Http::json(Orders::toJson($order), 201);
});

$router->add('GET', '/api/orders/{id}', function (array $args): void {
    $order = Orders::get($args['id']);
    if ($order === null) {
        Http::error('order_not_found', 404);

        return;
    }

    Http::json(Orders::toJson($order));
});

$router->add('POST', '/webhook/payment', function (): void {
    $body = Http::body();

    try {
        $result = Payments::handleWebhook($body);
    } catch (Throwable $e) {
        // The event row is committed before anything else happens, so a failure past that
        // point is recoverable by the worker. Answering 5xx would only buy a pointless redelivery.
        Log::error('webhook processing failed', [
            'event_id' => $body['event_id'] ?? null,
            'order_id' => $body['order_id'] ?? null,
            'error' => $e->getMessage(),
        ]);
        Http::json(['status' => 'ok', 'result' => 'deferred']);

        return;
    }

    if ($result['code'] === 400) {
        Http::error('bad_payload', 400);

        return;
    }

    Http::json(['status' => 'ok', 'result' => $result['status']], $result['code']);
});

// Storefront: products with how many codes are actually available right now.
// The per-sku number is read from the materialised counter, never counted out of key_pool -
// see docs/stage5_explain.md. The generic pool is one extra scalar for the whole page.
$router->add('GET', '/api/catalog', function (): void {
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $inStockOnly = ($_GET['in_stock'] ?? '') === '1';

    $shared = Stock::sharedAvailable();

    // two fixed statements rather than a built one - no value is ever concatenated into SQL
    $sql = $inStockOnly
        ? 'SELECT p.sku, p.name, p.type, p.price, p.currency, s.available
           FROM products p JOIN stock s ON s.sku = p.sku
           WHERE s.available > 0 ORDER BY p.sku LIMIT ? OFFSET ?'
        : 'SELECT p.sku, p.name, p.type, p.price, p.currency, s.available
           FROM products p JOIN stock s ON s.sku = p.sku
           ORDER BY p.sku LIMIT ? OFFSET ?';

    $items = [];
    foreach (Db::all($sql, [$limit, $offset]) as $row) {
        $items[] = [
            'sku' => $row['sku'],
            'name' => $row['name'],
            'type' => $row['type'],
            'price' => (int) $row['price'],
            'currency' => $row['currency'],
            'available' => (int) $row['available'] + $shared,
        ];
    }

    Http::json(['limit' => $limit, 'offset' => $offset, 'shared_pool' => $shared, 'items' => $items]);
});

$router->add('GET', '/api/admin/reconcile', function (): void {
    $stale = isset($_GET['stale_minutes'])
        ? max(0, (int) $_GET['stale_minutes'])
        : Env::int('RECONCILE_STALE_MIN', 5);

    Http::json(Reconcile::report($stale));
});

$router->add('GET', '/health', function (): void {
    Http::json(['status' => 'ok']);
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($path) ? rtrim($path, '/') ?: '/' : '/');
