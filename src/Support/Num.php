<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget\Support;

/** @internal */
final class Num
{
    private static int $idSeq = 0;

    public static function finite(mixed $value, int|float $fallback): int|float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : $fallback;
        }

        return $fallback;
    }

    public static function int(mixed $value, int|float $fallback = 0): int
    {
        return (int) floor((float) self::finite($value, $fallback));
    }

    public static function positive(mixed $value, int|float $fallback = 1): int
    {
        return max(1, self::int($value, $fallback));
    }

    public static function nonNegative(mixed $value, int|float $fallback = 0): int
    {
        return max(0, self::int($value, $fallback));
    }

    public static function percent(mixed $value, int|float $fallback = 100): float
    {
        $safe = (float) self::finite($value, $fallback);

        return min(100.0, max(0.0, $safe));
    }

    public static function ratio(int|float $used, int|float $limit): float
    {
        if ($limit <= 0) {
            return 1.0;
        }

        return $used / $limit;
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function dayKey(int $now): string
    {
        return gmdate('Y-m-d', intdiv($now, 1000));
    }

    public static function resetAt(int $now): int
    {
        $startOfDay = strtotime(self::dayKey($now) . ' 00:00:00 UTC');

        return (int) (($startOfDay + 86_400) * 1000);
    }

    public static function id(): string
    {
        self::$idSeq = (self::$idSeq + 1) % PHP_INT_MAX;

        return implode('-', [
            base_convert((string) self::nowMs(), 10, 36),
            base_convert((string) self::$idSeq, 10, 36),
            str_pad(base_convert((string) random_int(0, 2_176_782_335), 10, 36), 6, '0', STR_PAD_LEFT),
        ]);
    }

    public static function estimate(mixed $req): int
    {
        if (is_int($req) || is_float($req)) {
            return self::nonNegative($req);
        }

        if (is_array($req) && array_key_exists('tokens', $req)) {
            if (is_int($req['tokens']) || is_float($req['tokens'])) {
                return self::nonNegative($req['tokens']);
            }

            return 1;
        }

        if (is_object($req) && isset($req->tokens)) {
            if (is_int($req->tokens) || is_float($req->tokens)) {
                return self::nonNegative($req->tokens);
            }

            return 1;
        }

        return 1;
    }
}
