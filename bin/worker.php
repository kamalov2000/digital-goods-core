<?php

declare(strict_types=1);

/**
 * Delivery worker.
 *
 *   php bin/worker.php            run until SIGTERM/SIGINT
 *   php bin/worker.php --once     one pass, then exit (used by the race harness)
 *
 * Two duties per pass:
 *   1. orders in 'paid' -> call supplier A -> 'delivered'
 *   2. events left with applied=false whose order has since been created
 *
 * Safe to run in several instances. Nothing here does work without first winning a claim:
 *   - Delivery::deliver() claims an order with the conditional UPDATE paid -> delivering
 *     (Orders::transition), so only one worker ever reaches the supplier for a given order;
 *   - Payments::apply() claims an event with UPDATE ... SET applied = true WHERE applied = false.
 * Both are single statements evaluated under a row lock, so the losers see rowCount 0 and
 * simply move on. The unlocked SELECTs above them only nominate candidates.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Delivery;
use App\Env;
use App\Log;
use App\Payments;

$once = in_array('--once', array_slice($argv, 1), true);
$batch = Env::int('WORKER_BATCH', 20);
$sleepMs = Env::int('WORKER_SLEEP_MS', 200);

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

Log::info('worker started', ['pid' => getmypid(), 'batch' => $batch, 'once' => $once]);

do {
    try {
        $work = Payments::applyPending($batch) + Delivery::runPending($batch);
    } catch (Throwable $e) {
        // one bad pass must not kill the loop; the claims are all conditional, so whatever
        // failed is still in a state some pass can pick up again
        Log::error('worker pass failed', ['error' => $e->getMessage()]);
        $work = 0;
    }

    if ($once) {
        break;
    }

    if ($work === 0) {
        usleep($sleepMs * 1000);
    }
} while ($running);

Log::info('worker stopped', ['pid' => getmypid()]);
