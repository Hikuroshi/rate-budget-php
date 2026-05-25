<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

final class QuotaResult
{
    /**
     * @param list<KeyCheck> $checks
     */
    public function __construct(
        public readonly bool $ok,
        public readonly mixed $key,
        public readonly ?Hold $hold,
        public readonly ?string $reason,
        public readonly int $tokens,
        public readonly ?int $waitMs,
        public readonly array $checks,
    ) {
    }

    /**
     * @param list<KeyCheck> $checks
     */
    public static function allowed(mixed $key, int $tokens, array $checks, ?Hold $hold = null): self
    {
        return new self(
            ok: true,
            key: $key,
            hold: $hold,
            reason: null,
            tokens: $tokens,
            waitMs: 0,
            checks: $checks,
        );
    }

    /**
     * @param list<KeyCheck> $checks
     */
    public static function blocked(string $reason, int $tokens, ?int $waitMs, array $checks): self
    {
        return new self(
            ok: false,
            key: null,
            hold: null,
            reason: $reason,
            tokens: $tokens,
            waitMs: $waitMs,
            checks: $checks,
        );
    }

    /**
     * @return array{ok: bool, key: mixed, hold: array<string, mixed>|null, reason: string|null, tokens: int, waitMs: int|null, checks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $data = [
            'ok' => $this->ok,
            'tokens' => $this->tokens,
            'waitMs' => $this->waitMs,
            'checks' => array_map(static fn (KeyCheck $check): array => $check->toArray(), $this->checks),
        ];

        if ($this->ok) {
            $data['key'] = $this->key;

            if ($this->hold !== null) {
                $data['hold'] = $this->hold->toArray();
            }

            return $data;
        }

        $data['reason'] = $this->reason;

        return $data;
    }
}
