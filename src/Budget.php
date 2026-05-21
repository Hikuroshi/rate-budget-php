<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use Hikuroshi\RateBudget\Support\Num;
use InvalidArgumentException;

final class Budget
{
    public static function perRequest(int|float $tokens, int|float $spacingMs, array $options = []): int
    {
        $safeTokens = Num::positive($tokens);
        $safeSpacingMs = Num::positive($spacingMs);
        $windowMs = Num::positive(self::option($options, 'windowMs', Limit::DEFAULT_WINDOW_MS));
        $min = Num::positive(self::option($options, 'min', 1, ['minimumTokens']));
        $requests = $windowMs / $safeSpacingMs;

        return max($min, (int) floor($safeTokens / max(1, $requests)));
    }

    public static function portion(int|float $total, int|float $ratio): int
    {
        return max(1, (int) floor(Num::positive($total) * Num::ratio($ratio)));
    }

    public static function split(int|float $parent, int|float $available, array $parts): array
    {
        $safeParent = Num::positive($parent);
        $left = Num::nonNegative($available);
        $alloc = [];

        foreach ($parts as $part) {
            $name = self::partName($part);
            $target = (int) floor($safeParent * Num::ratio(self::partValue($part, 'target', ['targetRatio'])));
            $take = min($target, $left);

            $alloc[$name] = $take;
            $left -= $take;
        }

        foreach ($parts as $part) {
            if ($left <= 0) {
                break;
            }

            $name = self::partName($part);
            $current = $alloc[$name] ?? 0;
            $max = max(
                $current,
                (int) floor($safeParent * Num::ratio(self::partValue($part, 'max', ['maxRatio'])))
            );
            $extra = min($max - $current, $left);

            $alloc[$name] = $current + $extra;
            $left -= $extra;
        }

        return $alloc;
    }

    private static function option(array $options, string $name, mixed $default = null, array $aliases = []): mixed
    {
        if (array_key_exists($name, $options)) {
            return $options[$name];
        }

        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $options)) {
                return $options[$alias];
            }
        }

        return $default;
    }

    private static function partName(mixed $part): string
    {
        $name = self::partValue($part, 'name');

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Budget part needs a non-empty name.');
        }

        return $name;
    }

    private static function partValue(mixed $part, string $name, array $aliases = [], mixed $default = null): mixed
    {
        if (is_array($part)) {
            if (array_key_exists($name, $part)) {
                return $part[$name];
            }

            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $part)) {
                    return $part[$alias];
                }
            }
        }

        if (is_object($part)) {
            if (isset($part->{$name})) {
                return $part->{$name};
            }

            foreach ($aliases as $alias) {
                if (isset($part->{$alias})) {
                    return $part->{$alias};
                }
            }
        }

        return $default;
    }
}
