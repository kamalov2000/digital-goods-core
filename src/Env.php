<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal .env reader: real environment wins, the file is only a fallback.
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $file = null;

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        if (self::$file === null) {
            self::$file = self::readFile(dirname(__DIR__) . '/.env');
        }

        return self::$file[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value === '' ? $default : (int) $value;
    }

    public static function float(string $key, float $default): float
    {
        $value = self::get($key);

        return $value === '' ? $default : (float) $value;
    }

    /** @return array<string, string> */
    private static function readFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $result = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $result[trim($key)] = trim($value, " \t\"'");
        }

        return $result;
    }
}
