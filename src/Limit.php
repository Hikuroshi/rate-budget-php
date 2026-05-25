<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use Hikuroshi\RateBudget\Support\Num;

final class Limit
{
    public static function cooldownMs(
        int|float $rpm,
        int|float|null $bufferMs = null,
        int|float|null $windowMs = null,
    ): int {
        return (int) ceil(
            Num::positive($windowMs ?? Defaults::WINDOW_MS, Defaults::WINDOW_MS) / Num::positive($rpm)
        ) + Num::nonNegative($bufferMs ?? Defaults::BUFFER_MS, Defaults::BUFFER_MS);
    }

    public static function dailyCap(int|float $rpd, int|float|null $thresholdPct = null): int
    {
        return (int) ceil(Num::positive($rpd) * (Num::percent($thresholdPct ?? Defaults::THRESHOLD_PCT) / 100));
    }

    public static function tokenWaitMs(
        int|float $used,
        int|float $tokens,
        int|float $limit,
        array $hits,
        int|float $now,
        int|float|null $windowMs = null,
        int|float|null $bufferMs = null,
    ): ?int {
        $safeLimit = Num::positive($limit);
        $safeTokens = Num::nonNegative($tokens);
        $safeUsed = Num::nonNegative($used);
        $safeWindowMs = Num::positive($windowMs ?? Defaults::WINDOW_MS, Defaults::WINDOW_MS);
        $safeBufferMs = Num::nonNegative($bufferMs ?? Defaults::BUFFER_MS, Defaults::BUFFER_MS);
        $projected = $safeUsed + $safeTokens;

        if ($safeTokens > $safeLimit) {
            return null;
        }

        if ($projected <= $safeLimit) {
            return 0;
        }

        $remaining = $projected;
        $rows = self::sortHits($hits);

        foreach ($rows as $hit) {
            $remaining -= Num::nonNegative(self::hitValue($hit, 'tokens'));

            if ($remaining <= $safeLimit) {
                return max(0, $safeWindowMs - (Num::int($now) - Num::int(self::hitValue($hit, 'at'))) + $safeBufferMs);
            }
        }

        return $safeWindowMs + $safeBufferMs;
    }

    private static function sortHits(array $hits): array
    {
        usort($hits, static fn (mixed $left, mixed $right): int => Num::int(self::hitValue($left, 'at')) <=> Num::int(self::hitValue($right, 'at')));

        return $hits;
    }

    private static function hitValue(mixed $hit, string $name): mixed
    {
        if (is_array($hit) && array_key_exists($name, $hit)) {
            return $hit[$name];
        }

        if (is_object($hit) && isset($hit->{$name})) {
            return $hit->{$name};
        }

        return 0;
    }
}
