<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

final class LimitProfile
{
    public function __construct(
        public readonly int $rpm,
        public readonly int $tpm,
        public readonly int $rpd,
        public readonly float $dailyPercent,
        public readonly int $dailyLimit,
        public readonly int $cooldownMs,
        public readonly int $inactiveMs,
    ) {
    }

    /**
     * @return array{
     *     rpm: int,
     *     tpm: int,
     *     rpd: int,
     *     dailyPercent: float,
     *     dailyLimit: int,
     *     cooldownMs: int,
     *     inactiveMs: int
     * }
     */
    public function toArray(): array
    {
        return [
            'rpm' => $this->rpm,
            'tpm' => $this->tpm,
            'rpd' => $this->rpd,
            'dailyPercent' => $this->dailyPercent,
            'dailyLimit' => $this->dailyLimit,
            'cooldownMs' => $this->cooldownMs,
            'inactiveMs' => $this->inactiveMs,
        ];
    }
}
