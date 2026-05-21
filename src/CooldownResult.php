<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

final class CooldownResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly CooldownState $state,
        public readonly ?int $retryMs = null,
    ) {
    }

    /** @return array{allowed: bool, state: array{at: int, actor: string}, retryMs: int|null} */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'state' => $this->state->toArray(),
            'retryMs' => $this->retryMs,
        ];
    }
}
