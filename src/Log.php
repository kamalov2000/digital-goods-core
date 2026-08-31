<?php

declare(strict_types=1);

namespace App;

/**
 * One JSON line per step of the payment and delivery path.
 *
 * Every line carries: action (dotted, e.g. payment.apply), result (what happened) and the
 * identifiers of whatever it touched - order_id, and event_id or request_id. That is enough
 * to reconstruct the whole life of one order with:
 *
 *   grep ord_1234 app.log worker.log supplier-a.log | sort
 */
final class Log
{
    /** @param array<string, mixed> $context */
    public static function info(string $action, array $context = []): void
    {
        self::write('info', $action, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $action, array $context = []): void
    {
        self::write('error', $action, $context);
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $action, array $context): void
    {
        $line = ['ts' => gmdate('c'), 'level' => $level, 'action' => $action] + $context;
        error_log((string) json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
