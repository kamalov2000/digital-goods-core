<?php

declare(strict_types=1);

/**
 * Reconciliation report as JSON.
 *
 *   php bin/reconcile.php [--stale-minutes=N]
 *
 * Exits 1 if anything that must be empty is not: "delivered but not paid", ledger
 * discrepancies, or stock counter drift. "Paid but not delivered" is reported, not failed on -
 * orders legitimately sit there until recovery gets to them.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Env;
use App\Reconcile;

$stale = Env::int('RECONCILE_STALE_MIN', 5);
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--stale-minutes=')) {
        $stale = (int) substr($arg, 16);
    }
}

$report = Reconcile::report($stale);
$report['ledger_discrepancies'] = Reconcile::ledgerDiscrepancies();
$report['ledger_without_payment'] = Reconcile::ledgerWithoutPayment();

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

$broken = $report['delivered_not_paid']['count']
    + $report['stock_drift']['count']
    + count($report['ledger_discrepancies'])
    + count($report['ledger_without_payment']);

exit($broken === 0 ? 0 : 1);
