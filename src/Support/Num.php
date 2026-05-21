<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/** @internal */
final class Num
{
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

    public static function ratio(mixed $value): float
    {
        $safe = (float) self::finite($value, 0);

        return min(1.0, max(0.0, $safe));
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function timeMs(DateTimeInterface|int|float|string|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? self::int($value) : null;
        }

        if ($value instanceof DateTimeInterface) {
            return intdiv((int) $value->format('Uu'), 1000);
        }

        if (is_numeric($value)) {
            return self::int((float) $value);
        }

        try {
            return intdiv((int) (new DateTimeImmutable($value))->format('Uu'), 1000);
        } catch (Throwable) {
            return null;
        }
    }
}
