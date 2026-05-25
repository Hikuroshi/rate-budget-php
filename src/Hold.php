<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use Hikuroshi\RateBudget\Support\Num;
use InvalidArgumentException;

final class Hold
{
    public function __construct(
        public readonly string $id,
        public readonly string $scope,
        public readonly string $key,
        public readonly int $tokens,
        public readonly int $at,
        public readonly string $day,
        public readonly ?int $prevAt = null,
        public readonly ?string $prevId = null,
    ) {
    }

    public static function from(self|array $hold): self
    {
        if ($hold instanceof self) {
            return $hold;
        }

        foreach (['id', 'scope', 'key', 'tokens', 'at', 'day'] as $field) {
            if (! array_key_exists($field, $hold)) {
                throw new InvalidArgumentException("Hold is missing {$field}.");
            }
        }

        return new self(
            id: (string) $hold['id'],
            scope: (string) $hold['scope'],
            key: (string) $hold['key'],
            tokens: Num::nonNegative($hold['tokens']),
            at: Num::int($hold['at']),
            day: (string) $hold['day'],
            prevAt: array_key_exists('prevAt', $hold) && $hold['prevAt'] !== null ? Num::int($hold['prevAt']) : null,
            prevId: array_key_exists('prevId', $hold) && $hold['prevId'] !== null ? (string) $hold['prevId'] : null,
        );
    }

    /**
     * @return array{id: string, scope: string, key: string, tokens: int, at: int, day: string, prevAt?: int, prevId?: string}
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'scope' => $this->scope,
            'key' => $this->key,
            'tokens' => $this->tokens,
            'at' => $this->at,
            'day' => $this->day,
        ];

        if ($this->prevAt !== null) {
            $data['prevAt'] = $this->prevAt;
        }

        if ($this->prevId !== null) {
            $data['prevId'] = $this->prevId;
        }

        return $data;
    }
}
