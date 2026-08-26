<?php

declare(strict_types=1);

namespace App;

/**
 * Structured logging: one JSON line per event so payment/delivery traces stay greppable.
 */
final class Log
{
    /** @param array<string, mixed> $context */
    public static function info(string $msg, array $context = []): void
    {
        self::write('info', $msg, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $msg, array $context = []): void
    {
        self::write('error', $msg, $context);
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $msg, array $context): void
    {
        $line = ['ts' => gmdate('c'), 'level' => $level, 'msg' => $msg] + $context;
        error_log((string) json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
