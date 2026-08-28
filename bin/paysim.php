<?php

declare(strict_types=1);

/**
 * Payment gateway simulator.
 *
 *   php bin/paysim.php <order_id> [--status=paid|failed] [--n=1] [--unique] [--event-id=...]
 *
 * With --n > 1 the webhooks are fired in parallel via curl_multi. By default every request
 * carries the SAME event_id (a redelivery storm); --unique gives each its own event_id
 * (distinct events racing for one order). Both must end with exactly one delivery.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Env;

$args = array_slice($argv, 1);
$orderId = '';
$options = ['status' => 'paid', 'n' => '1', 'event-id' => '', 'amount' => '', 'unique' => '0'];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $options[$key] = $value;
    } elseif ($orderId === '') {
        $orderId = $arg;
    }
}

if ($orderId === '') {
    fwrite(STDERR, "usage: php bin/paysim.php <order_id> [--status=paid|failed] [--n=1] [--unique]\n");
    exit(1);
}

$status = $options['status'];
$n = max(1, (int) $options['n']);
$unique = $options['unique'] !== '0';
$sharedEventId = $options['event-id'] !== '' ? $options['event-id'] : 'evt_' . bin2hex(random_bytes(6));

$amount = (int) $options['amount'];
$currency = 'RUB';
if ($amount === 0) {
    $order = Db::one('SELECT amount, currency FROM orders WHERE id = ?', [$orderId]);
    if ($order !== null) {
        $amount = (int) $order['amount'];
        $currency = (string) $order['currency'];
    }
}

$url = rtrim(Env::get('APP_URL', 'http://localhost:8000'), '/') . '/webhook/payment';

$multi = curl_multi_init();
$handles = [];

for ($i = 0; $i < $n; $i++) {
    $payload = [
        'event_id' => $unique ? 'evt_' . bin2hex(random_bytes(6)) : $sharedEventId,
        'order_id' => $orderId,
        'status' => $status,
        'amount' => $amount,
        'currency' => $currency,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => (string) json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}

// fire everything at once - this is the race harness, not a loop of sequential calls
$running = null;
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 1.0);
} while ($running > 0);

$summary = [];
foreach ($handles as $ch) {
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response = (string) curl_multi_getcontent($ch);
    $errno = curl_errno($ch);
    $key = $errno !== 0 ? 'curl:' . curl_strerror($errno) : $code . ' ' . $response;
    $summary[$key] = ($summary[$key] ?? 0) + 1;

    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

printf("sent %d webhook(s) status=%s to %s\n", $n, $status, $url);
foreach ($summary as $key => $count) {
    printf("  %4d x %s\n", $count, $key);
}
