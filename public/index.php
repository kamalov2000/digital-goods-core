<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Http;
use App\Log;
use App\Orders;
use App\Payments;
use App\Router;

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

$router->add('GET', '/health', function (): void {
    Http::json(['status' => 'ok']);
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($path) ? rtrim($path, '/') ?: '/' : '/');
