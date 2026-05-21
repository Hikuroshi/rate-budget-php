<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

final class CountResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $used,
        public readonly int $threshold,
        public readonly int $left,
    ) {
    }

    /** @return array{allowed: bool, used: int, threshold: int, left: int} */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'used' => $this->used,
            'threshold' => $this->threshold,
            'left' => $this->left,
        ];
    }
}
