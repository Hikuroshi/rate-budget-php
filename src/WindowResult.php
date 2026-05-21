<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

final class WindowResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly int $cost,
        public readonly int $limit,
        public readonly ?int $retryMs,
    ) {
    }

    /** @return array{allowed: bool, reason: string, cost: int, limit: int, retryMs: int|null} */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'cost' => $this->cost,
            'limit' => $this->limit,
            'retryMs' => $this->retryMs,
        ];
    }
}
