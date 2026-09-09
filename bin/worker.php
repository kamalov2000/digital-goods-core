<?php

declare(strict_types=1);

/**
 * Delivery worker.
 *
 *   php bin/worker.php            run until SIGTERM/SIGINT
 *   php bin/worker.php --once     one pass, then exit (used by the race harness)
 *
 * Duties per pass:
 *   1. line items waiting for a code on a paid order
 *   2. events left with applied=false whose order has since been created
 * and, on a slower clock:
 *   3. recovery of lines stalled in 'delivery_failed' / 'out_of_stock' / 'delivering'
 *   4. refunds for lines we hold money for and cannot deliver
 *   5. settling orders whose lines have all reached a terminal state
 *   6. auditing the supplier: keys it took out of the pool that never reached a line
 *
 * Safe to run in several instances. Nothing here does work without first winning a claim:
 *   - Delivery::runPending() claims a line with the conditional UPDATE pending -> delivering
 *     (Orders::moveItem), so only one worker ever reaches a supplier for a given line;
 *   - Payments::apply() claims an event with UPDATE ... SET applied = true WHERE applied = false;
 *   - Delivery::runRecovery() and Refunds::runPending() claim a line with
 *     UPDATE ... WHERE status = <the status it was read in>.
 * Both are single statements evaluated under a row lock, so the losers see rowCount 0 and
 * simply move on. The unlocked SELECTs above them only nominate candidates.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Delivery;
use App\Env;
use App\Log;
use App\Orders;
use App\Payments;
use App\Refunds;
use App\SupplierAudit;

$once = in_array('--once', array_slice($argv, 1), true);
$batch = Env::int('WORKER_BATCH', 20);
$sleepMs = Env::int('WORKER_SLEEP_MS', 200);
$recoveryEvery = Env::int('RECOVERY_INTERVAL_SEC', 5);
$nextRecovery = 0;

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

Log::info('worker.start', ['result' => 'running', 'pid' => getmypid(), 'batch' => $batch, 'once' => $once]);

do {
    try {
        $work = Payments::applyPending($batch) + Delivery::runPending($batch);

        // recovery runs on its own, slower clock: re-scanning stalled orders every 200ms
        // would just spin against an empty pool or a supplier that is still down
        if (time() >= $nextRecovery) {
            $nextRecovery = time() + $recoveryEvery;
            $work += Delivery::runRecovery($batch);
            $work += Refunds::runPending($batch);
            // safety net for a crash between finishing a line and deriving the order status
            $work += Orders::settleStale($batch);
            $work += SupplierAudit::runAudit($batch);
        }
    } catch (Throwable $e) {
        // one bad pass must not kill the loop; the claims are all conditional, so whatever
        // failed is still in a state some pass can pick up again
        Log::error('worker.pass', ['result' => 'error', 'error' => $e->getMessage()]);
        $work = 0;
    }

    if ($once) {
        break;
    }

    if ($work === 0) {
        usleep($sleepMs * 1000);
    }
} while ($running);

Log::info('worker.stop', ['result' => 'stopped', 'pid' => getmypid()]);
