<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

final class MemoryStore implements QuotaStore
{
    /** @var array<string, array<string, mixed>> */
    private array $scopes = [];

    public function mutate(string $scope, callable $fn): mixed
    {
        if (! isset($this->scopes[$scope]) || ! is_array($this->scopes[$scope])) {
            $this->scopes[$scope] = self::emptyState();
        }

        $state =& $this->scopes[$scope];

        return $fn($state);
    }

    public function get(string $scope): ?array
    {
        return $this->scopes[$scope] ?? null;
    }

    public function set(string $scope, array $state): void
    {
        $this->scopes[$scope] = $state;
    }

    public function reset(?string $scope = null): void
    {
        if ($scope !== null) {
            unset($this->scopes[$scope]);

            return;
        }

        $this->scopes = [];
    }

    public function size(): int
    {
        return count($this->scopes);
    }

    /** @return array{keys: array<string, mixed>} */
    public static function emptyState(): array
    {
        return ['keys' => []];
    }
}
