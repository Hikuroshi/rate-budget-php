<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use Hikuroshi\RateBudget\Support\Num;

final class Cooldown
{
    public static function consume(
        CooldownState|array|null $state,
        string $actor,
        int|float $sameMs,
        int|float $diffMs,
        int|float|null $now = null,
    ): CooldownResult {
        $nowMs = $now === null ? Num::nowMs() : Num::int($now, Num::nowMs());
        $current = self::state($state);

        if ($current === null) {
            return new CooldownResult(true, new CooldownState($nowMs, $actor));
        }

        $required = $current->actor === $actor
            ? Num::nonNegative($sameMs)
            : Num::nonNegative($diffMs);
        $elapsed = $nowMs - $current->at;

        if ($elapsed < $required) {
            return new CooldownResult(false, $current, $required - $elapsed);
        }

        return new CooldownResult(true, new CooldownState($nowMs, $actor));
    }

    private static function state(CooldownState|array|null $state): ?CooldownState
    {
        if ($state instanceof CooldownState || $state === null) {
            return $state;
        }

        $at = $state['at'] ?? $state['lastAcceptedAt'] ?? null;
        $actor = $state['actor'] ?? $state['lastActorId'] ?? null;

        if (! is_string($actor) || $at === null) {
            return null;
        }

        return new CooldownState($at, $actor);
    }
}
