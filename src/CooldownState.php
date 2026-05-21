<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use Hikuroshi\RateBudget\Support\Num;

final class CooldownState
{
    public readonly int $at;

    public readonly string $actor;

    public function __construct(int|float $at, string $actor)
    {
        $this->at = Num::int($at);
        $this->actor = $actor;
    }

    /** @return array{at: int, actor: string} */
    public function toArray(): array
    {
        return [
            'at' => $this->at,
            'actor' => $this->actor,
        ];
    }
}
