<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use Hikuroshi\RateBudget\Support\Num;

final class MemoryCooldown
{
    /** @var array<string, CooldownState> */
    private array $states = [];

    private readonly int $sameMs;

    private readonly int $diffMs;

    private readonly int $ttlMs;

    private readonly int $cleanup;

    private int $touches = 0;

    public function __construct(
        int|float $sameMs,
        int|float $diffMs,
        int|float $ttlMs = 300_000,
        int|float $cleanup = 256,
    ) {
        $this->sameMs = Num::nonNegative($sameMs);
        $this->diffMs = Num::nonNegative($diffMs);
        $this->ttlMs = Num::nonNegative($ttlMs);
        $this->cleanup = max(1, Num::nonNegative($cleanup, 256));
    }

    public function consume(string $scope, string $actor, int|float|null $now = null): CooldownResult
    {
        $nowMs = $now === null ? Num::nowMs() : Num::int($now, Num::nowMs());

        $this->cleanup($nowMs);

        $result = Cooldown::consume(
            state: $this->states[$scope] ?? null,
            actor: $actor,
            sameMs: $this->sameMs,
            diffMs: $this->diffMs,
            now: $nowMs
        );

        if ($result->allowed) {
            $this->states[$scope] = $result->state;
        }

        return $result;
    }

    public function reset(?string $scope = null): void
    {
        if ($scope !== null) {
            unset($this->states[$scope]);

            return;
        }

        $this->states = [];
    }

    public function size(): int
    {
        return count($this->states);
    }

    private function cleanup(int $now): void
    {
        $this->touches++;

        if ($this->touches % $this->cleanup !== 0) {
            return;
        }

        foreach ($this->states as $scope => $state) {
            if ($now - $state->at > $this->ttlMs) {
                unset($this->states[$scope]);
            }
        }
    }
}
