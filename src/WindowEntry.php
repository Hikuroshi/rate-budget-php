<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use DateTimeInterface;
use Hikuroshi\RateBudget\Support\Num;

final class WindowEntry
{
    public readonly DateTimeInterface|int|float|string|null $at;

    public readonly int $cost;

    public function __construct(DateTimeInterface|int|float|string|null $at, int|float $cost = 1)
    {
        $this->at = $at;
        $this->cost = Num::nonNegative($cost);
    }
}
