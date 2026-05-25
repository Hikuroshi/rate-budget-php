<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

final class KeyCheck
{
    public function __construct(
        public readonly string $key,
        public readonly bool $ok,
        public readonly ?string $reason,
        public readonly ?int $waitMs,
        public readonly int $priority,
        public readonly int $rpmMs,
        public readonly ?int $tpmMs,
        public readonly int $tokenUsed,
        public readonly float $tokenPct,
        public readonly int $dailyUsed,
        public readonly int $dailyCap,
        public readonly int $dailyLeft,
        public readonly float $dailyPct,
    ) {
    }

    /**
     * @return array{
     *     key: string,
     *     ok: bool,
     *     reason: string|null,
     *     waitMs: int|null,
     *     priority: int,
     *     rpmMs: int,
     *     tpmMs: int|null,
     *     tokenUsed: int,
     *     tokenPct: float,
     *     dailyUsed: int,
     *     dailyCap: int,
     *     dailyLeft: int,
     *     dailyPct: float
     * }
     */
    public function toArray(): array
    {
        $data = [
            'key' => $this->key,
            'ok' => $this->ok,
            'waitMs' => $this->waitMs,
            'priority' => $this->priority,
            'rpmMs' => $this->rpmMs,
            'tpmMs' => $this->tpmMs,
            'tokenUsed' => $this->tokenUsed,
            'tokenPct' => $this->tokenPct,
            'dailyUsed' => $this->dailyUsed,
            'dailyCap' => $this->dailyCap,
            'dailyLeft' => $this->dailyLeft,
            'dailyPct' => $this->dailyPct,
        ];

        if ($this->reason !== null) {
            $data['reason'] = $this->reason;
        }

        return $data;
    }
}
