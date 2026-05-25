<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

interface QuotaStore
{
    /**
     * Runs a mutation atomically for one scope.
     *
     * The callback receives quota state by reference:
     * `fn (array &$state): mixed`.
     */
    public function mutate(string $scope, callable $fn): mixed;
}
