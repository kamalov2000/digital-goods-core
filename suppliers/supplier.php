<?php

declare(strict_types=1);

/**
 * Supplier stub. Run one process per supplier:
 *   SUPPLIER_NAME=A php -S localhost:9001 suppliers/supplier.php
 *   SUPPLIER_NAME=B php -S localhost:9002 suppliers/supplier.php
 *
 * It stands in for an external system, so it keeps its own PDO connection and its own
 * tables (supplier_issues, key_pool) and shares nothing with the shop core but config.
 *
 * Fault injection via env: FAIL_RATE, TIMEOUT_RATE, TIMEOUT_SEC (all default to no faults).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Env;

$supplier = Env::get('SUPPLIER_NAME', 'A');

function supplier_log(string $msg, array $context = []): void
{
    $line = ['ts' => gmdate('c'), 'level' => 'info', 'msg' => $msg] + $context;
    error_log((string) json_encode($line, JSON_UNESCAPED_SLASHES));
}

function supplier_reply(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/issue' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    supplier_reply(404, ['status' => 'error', 'reason' => 'not_found']);

    return true;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw === false ? '' : $raw, true);
$body = is_array($body) ? $body : [];

$requestId = isset($body['request_id']) ? (string) $body['request_id'] : '';
$orderId = isset($body['order_id']) ? (string) $body['order_id'] : '';
$sku = isset($body['sku']) ? (string) $body['sku'] : '';

if ($requestId === '' || $orderId === '' || $sku === '') {
    supplier_reply(400, ['status' => 'error', 'reason' => 'bad_request']);

    return true;
}

$roll = static fn (float $rate): bool => $rate > 0 && (mt_rand() / mt_getrandmax()) < $rate;

if ($roll(Env::float('FAIL_RATE', 0.0))) {
    supplier_log('injected failure', ['supplier' => $supplier, 'request_id' => $requestId]);
    supplier_reply(500, ['status' => 'error', 'reason' => 'internal_error']);

    return true;
}

$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        Env::get('DB_HOST', '127.0.0.1'),
        Env::int('DB_PORT', 5432),
        Env::get('DB_NAME', 'shop'),
    ),
    Env::get('DB_USER', 'shop'),
    Env::get('DB_PASS', 'shop'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

$pdo->beginTransaction();

// Claim the request_id first. A concurrent call with the same request_id blocks on the
// primary key here and, once we commit, falls through to the replay branch below.
$stmt = $pdo->prepare(
    'INSERT INTO supplier_issues (request_id, supplier, order_id, sku) VALUES (?, ?, ?, ?)
     ON CONFLICT (request_id) DO NOTHING'
);
$stmt->execute([$requestId, $supplier, $orderId, $sku]);

$stmt = $pdo->prepare('SELECT code FROM supplier_issues WHERE request_id = ? FOR UPDATE');
$stmt->execute([$requestId]);
$existing = $stmt->fetch();

if ($existing !== false && $existing['code'] !== null) {
    $pdo->commit();
    supplier_log('replayed issued code', ['supplier' => $supplier, 'request_id' => $requestId]);

    if ($roll(Env::float('TIMEOUT_RATE', 0.0))) {
        sleep(Env::int('TIMEOUT_SEC', 10));
    }

    supplier_reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $existing['code']]);

    return true;
}

// Reserve one free key. SKIP LOCKED lets concurrent issues grab different rows instead of
// queuing on the same one, and the whole thing is a single statement so no key can be
// handed out twice.
$stmt = $pdo->prepare(
    'UPDATE key_pool SET order_id = ?, reserved_at = now()
     WHERE code = (
         SELECT code FROM key_pool
         WHERE order_id IS NULL AND (sku IS NULL OR sku = ?)
         LIMIT 1 FOR UPDATE SKIP LOCKED
     )
     RETURNING code'
);
$stmt->execute([$orderId, $sku]);
$reserved = $stmt->fetch();

if ($reserved === false) {
    // roll back so the request_id is not burned with a NULL code: after a restock the
    // same request_id can be retried and will get a real key
    $pdo->rollBack();
    supplier_log('out of stock', ['supplier' => $supplier, 'request_id' => $requestId, 'order_id' => $orderId]);
    supplier_reply(409, ['status' => 'error', 'reason' => 'out_of_stock']);

    return true;
}

$code = (string) $reserved['code'];
$stmt = $pdo->prepare('UPDATE supplier_issues SET code = ? WHERE request_id = ?');
$stmt->execute([$code, $requestId]);
$pdo->commit();

supplier_log('issued code', ['supplier' => $supplier, 'request_id' => $requestId, 'order_id' => $orderId]);

// The code is already committed at this point: hanging now reproduces the trap where the
// client times out even though the supplier did issue.
if ($roll(Env::float('TIMEOUT_RATE', 0.0))) {
    sleep(Env::int('TIMEOUT_SEC', 10));
}

supplier_reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $code]);

return true;
