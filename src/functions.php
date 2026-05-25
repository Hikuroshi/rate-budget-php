<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

defined(__NAMESPACE__ . '\\WINDOW_MS') || define(__NAMESPACE__ . '\\WINDOW_MS', Defaults::WINDOW_MS);
defined(__NAMESPACE__ . '\\BUFFER_MS') || define(__NAMESPACE__ . '\\BUFFER_MS', Defaults::BUFFER_MS);
defined(__NAMESPACE__ . '\\THRESHOLD_PCT') || define(__NAMESPACE__ . '\\THRESHOLD_PCT', Defaults::THRESHOLD_PCT);

if (! function_exists(__NAMESPACE__ . '\\cooldownMs')) {
    function cooldownMs(int|float $rpm, int|float|null $bufferMs = null, int|float|null $windowMs = null): int
    {
        return Limit::cooldownMs($rpm, $bufferMs, $windowMs);
    }
}

if (! function_exists(__NAMESPACE__ . '\\dailyCap')) {
    function dailyCap(int|float $rpd, int|float|null $thresholdPct = null): int
    {
        return Limit::dailyCap($rpd, $thresholdPct);
    }
}

if (! function_exists(__NAMESPACE__ . '\\tokenWaitMs')) {
    function tokenWaitMs(
        int|float $used,
        int|float $tokens,
        int|float $limit,
        array $hits,
        int|float $now,
        int|float|null $windowMs = null,
        int|float|null $bufferMs = null,
    ): ?int {
        return Limit::tokenWaitMs($used, $tokens, $limit, $hits, $now, $windowMs, $bufferMs);
    }
}
