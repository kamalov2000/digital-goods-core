<?php

declare(strict_types=1);

/**
 * Assertion helper for tests/race.sh. Every check is a real SQL query against the live
 * database - nothing is mocked or inferred from HTTP responses.
 *
 *   php tests/race_assert.php "<label>" <expected> "<sql returning one scalar>" [params...]
 *
 * Exits 0 on PASS, 1 on FAIL, so the shell can accumulate failures.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;

$label = $argv[1] ?? '';
$expected = $argv[2] ?? '';
$sql = $argv[3] ?? '';
$params = array_slice($argv, 4);

$row = Db::one($sql, $params);
$actual = $row === null ? null : (string) array_values($row)[0];

if ((string) $actual === (string) $expected) {
    printf("  PASS  %-58s = %s\n", $label, $actual);
    exit(0);
}

printf("  FAIL  %-58s expected %s, got %s\n", $label, $expected, var_export($actual, true));
exit(1);
