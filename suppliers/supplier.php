<?php

declare(strict_types=1);

/**
 * Supplier stub. Run one process per supplier:
 *   SUPPLIER_NAME=A php -S localhost:9001 suppliers/supplier.php
 *   SUPPLIER_NAME=B php -S localhost:9002 suppliers/supplier.php
 *
 * It stands in for an external system, so it keeps its own PDO connection and its own tables
 * (supplier_issues, key_pool) and shares nothing with the shop core but config.
 *
 * Two endpoints:
 *   POST /issue                 ask for a code
 *   GET  /issue/{request_id}    ask what was issued for that request_id
 *
 * The GET is the honest half of a dishonest supplier: even a system that lies in its answers
 * still knows what it did, and that is what lets the client resolve "you answered with an
 * error but you did issue" without gambling on a retry.
 *
 * Fault injection via env (all default to no faults):
 *   FAIL_RATE             answer 5xx BEFORE anything is issued
 *   TIMEOUT_RATE          hang for TIMEOUT_SEC AFTER the code is committed
 *   TIMEOUT_SEC           how long the hang lasts; set it above the client timeout
 *   ERROR_AFTER_ISSUE_RATE  reserve and commit the key, then answer 5xx anyway
 *   DUPLICATE_RATE        answer with a code that was already issued to someone else
 *   FOREIGN_CODE_RATE     answer with a code that was never in this pool at all
 *   RATE_LIMIT_PER_MIN    accept only this many POST /issue calls per minute (0 = no limit)
 *
 * The ordering is the whole point. FAIL_RATE fires before any side effect, so it is a refusal
 * the client may trust. DUPLICATE and FOREIGN reserve nothing, so they burn no key. TIMEOUT and
 * ERROR_AFTER_ISSUE fire only after the key is committed, which is exactly the case where the
 * client must not believe the answer.
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

function supplier_pdo(): PDO
{
    return new PDO(
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
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// ---------------------------------------------------------------------------
// GET /issue/{request_id} - what do you think you issued for this request?
if ($method === 'GET' && preg_match('#^/issue/(?P<id>[^/]+)$#', (string) $path, $m) === 1) {
    $stmt = supplier_pdo()->prepare('SELECT code FROM supplier_issues WHERE request_id = ?');
    $stmt->execute([$m['id']]);
    $row = $stmt->fetch();

    if ($row === false || $row['code'] === null) {
        supplier_reply(200, ['status' => 'none', 'request_id' => $m['id']]);

        return true;
    }

    supplier_log('confirmed issue', ['supplier' => $supplier, 'request_id' => $m['id']]);
    supplier_reply(200, ['status' => 'ok', 'request_id' => $m['id'], 'code' => $row['code']]);

    return true;
}

if ($path !== '/issue' || $method !== 'POST') {
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

// Rate limit, enforced before any work and only on the issuing endpoint - GET /issue/{id} is a
// question about the past and costs the supplier nothing. Counting and recording the call happen
// under an advisory lock, so concurrent callers cannot all squeeze through the last free slot.
$rateLimit = Env::int('RATE_LIMIT_PER_MIN', 0);
if ($rateLimit > 0) {
    $pdo = supplier_pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
    $stmt->execute(['supplier_rate_' . $supplier]);

    $stmt = $pdo->prepare(
        "SELECT count(*) AS n FROM supplier_calls
         WHERE supplier = ? AND called_at > now() - interval '1 minute'"
    );
    $stmt->execute([$supplier]);

    if ((int) $stmt->fetch()['n'] >= $rateLimit) {
        $pdo->rollBack();
        supplier_log('rate limited', ['supplier' => $supplier, 'request_id' => $requestId]);
        header('Retry-After: 5');
        supplier_reply(429, ['status' => 'error', 'reason' => 'rate_limited']);

        return true;
    }

    $stmt = $pdo->prepare('INSERT INTO supplier_calls (supplier) VALUES (?)');
    $stmt->execute([$supplier]);
    $pdo->commit();
}

if ($roll(Env::float('FAIL_RATE', 0.0))) {
    // injected before touching the database: nothing was issued, so the client may safely treat
    // this as a definite refusal and fall back to the other supplier
    supplier_log('injected failure', ['supplier' => $supplier, 'request_id' => $requestId]);
    supplier_reply(500, ['status' => 'error', 'reason' => 'internal']);

    return true;
}

$pdo = supplier_pdo();
$pdo->beginTransaction();

// Claim the request_id first. A concurrent call with the same request_id blocks on the primary
// key here and, once we commit, falls through to the replay branch below.
$stmt = $pdo->prepare(
    'INSERT INTO supplier_issues (request_id, supplier, order_id, sku) VALUES (?, ?, ?, ?)
     ON CONFLICT (request_id) DO NOTHING'
);
$stmt->execute([$requestId, $supplier, $orderId, $sku]);

$stmt = $pdo->prepare('SELECT code FROM supplier_issues WHERE request_id = ? FOR UPDATE');
$stmt->execute([$requestId]);
$existing = $stmt->fetch();

// Replay: this request_id already has a code. Answer immediately and never inject a fault here -
// a retry after a timeout must be able to learn what was issued, otherwise the client could
// never resolve an 'unknown' attempt and would be pushed into a second issue.
if ($existing !== false && $existing['code'] !== null) {
    $pdo->commit();
    supplier_log('replayed issued code', ['supplier' => $supplier, 'request_id' => $requestId]);
    supplier_reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $existing['code']]);

    return true;
}

// --- dishonest answers -----------------------------------------------------
// Both of these reserve nothing and record nothing: the supplier simply says something untrue.
// That is what makes them detectable on the client side and safe to fall back from.

if ($roll(Env::float('DUPLICATE_RATE', 0.0))) {
    $stmt = $pdo->prepare(
        'SELECT code FROM supplier_issues WHERE code IS NOT NULL AND request_id <> ? LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $victim = $stmt->fetch();

    if ($victim !== false) {
        $pdo->rollBack();
        supplier_log('injected duplicate code', [
            'supplier' => $supplier,
            'request_id' => $requestId,
            'code' => $victim['code'],
        ]);
        supplier_reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $victim['code']]);

        return true;
    }
}

if ($roll(Env::float('FOREIGN_CODE_RATE', 0.0))) {
    $pdo->rollBack();
    $fake = sprintf(
        'XXXX-%04X-%04X',
        random_int(0, 0xFFFF),
        random_int(0, 0xFFFF),
    );
    supplier_log('injected foreign code', [
        'supplier' => $supplier,
        'request_id' => $requestId,
        'code' => $fake,
    ]);
    supplier_reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $fake]);

    return true;
}

// Reserve one free key. SKIP LOCKED lets concurrent issues grab different rows instead of
// queuing on the same one, and the whole thing is a single statement so no key can be handed
// out twice.
$stmt = $pdo->prepare(
    'UPDATE key_pool SET order_id = ?, reserved_at = now()
     WHERE code = (
         SELECT code FROM key_pool
         WHERE order_id IS NULL AND (sku IS NULL OR sku = ?)
         LIMIT 1 FOR UPDATE SKIP LOCKED
     )
     RETURNING code, sku'
);
$stmt->execute([$orderId, $sku]);
$reserved = $stmt->fetch();

if ($reserved === false) {
    // roll back so the request_id is not burned with a NULL code: after a restock the same
    // request_id can be retried and will get a real key
    $pdo->rollBack();
    supplier_log('out of stock', ['supplier' => $supplier, 'request_id' => $requestId, 'order_id' => $orderId]);
    supplier_reply(409, ['status' => 'error', 'reason' => 'out_of_stock']);

    return true;
}

$code = (string) $reserved['code'];
$stmt = $pdo->prepare('UPDATE supplier_issues SET code = ? WHERE request_id = ?');
$stmt->execute([$code, $requestId]);

// Storefront counter, decremented in the SAME transaction that took the key out of the pool.
// That is the only reason it can be trusted: there is no window where the key is reserved but
// the counter still advertises it. Generic keys (sku NULL) are not counted per sku - see
// migrations/004_stock.sql.
if ($reserved['sku'] !== null) {
    $stmt = $pdo->prepare('UPDATE stock SET available = available - 1, updated_at = now() WHERE sku = ?');
    $stmt->execute([$reserved['sku']]);
}

$pdo->commit();

supplier_log('issued code', ['supplier' => $supplier, 'request_id' => $requestId, 'order_id' => $orderId]);

// --- faults that fire only after the key is already committed ---------------

if ($roll(Env::float('ERROR_AFTER_ISSUE_RATE', 0.0))) {
    // The nastiest one: a complete, plausible refusal for a request that succeeded. A client
    // that believes it will fall back and hand the line a second key.
    supplier_log('injected error after issuing', ['supplier' => $supplier, 'request_id' => $requestId]);
    supplier_reply(500, ['status' => 'error', 'reason' => 'internal']);

    return true;
}

// The key is reserved and supplier_issues is committed at this point. Hanging now is the
// timeout trap: the client gives up and never learns that the code was already issued.
if ($roll(Env::float('TIMEOUT_RATE', 0.0))) {
    supplier_log('injected timeout after issuing', ['supplier' => $supplier, 'request_id' => $requestId]);
    sleep(Env::int('TIMEOUT_SEC', 10));
}

supplier_reply(200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $code]);

return true;
