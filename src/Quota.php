<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use Hikuroshi\RateBudget\Support\Num;
use InvalidArgumentException;

class Quota
{
    private readonly QuotaStore $store;

    private $estimate;

    private $now;

    private $nextId;

    private readonly int $windowMs;

    private readonly int $bufferMs;

    private readonly float $thresholdPct;

    private $dayKey;

    private $resetAt;

    public function __construct(
        array|QuotaStore|null $options = [],
        ?QuotaStore $store = null,
        ?callable $estimate = null,
        ?callable $now = null,
        ?callable $id = null,
        int|float|null $windowMs = null,
        int|float|null $bufferMs = null,
        int|float|null $thresholdPct = null,
        ?callable $dayKey = null,
        ?callable $resetAt = null,
    ) {
        if ($options instanceof QuotaStore) {
            $store ??= $options;
            $options = [];
        }

        $options ??= [];
        $store ??= $options['store'] ?? null;
        $estimate ??= $options['estimate'] ?? null;
        $now ??= $options['now'] ?? null;
        $id ??= $options['id'] ?? null;
        $windowMs ??= $options['windowMs'] ?? null;
        $bufferMs ??= $options['bufferMs'] ?? null;
        $thresholdPct ??= $options['thresholdPct'] ?? null;
        $dayKey ??= $options['dayKey'] ?? null;
        $resetAt ??= $options['resetAt'] ?? null;

        $this->store = $store ?? new MemoryStore();
        $this->estimate = $estimate ?? static fn (mixed $req = null): int => Num::estimate($req);
        $this->now = $now ?? static fn (): int => Num::nowMs();
        $this->nextId = $id ?? static fn (): string => Num::id();
        $this->windowMs = Num::positive($windowMs ?? Defaults::WINDOW_MS, Defaults::WINDOW_MS);
        $this->bufferMs = Num::nonNegative($bufferMs ?? Defaults::BUFFER_MS, Defaults::BUFFER_MS);
        $this->thresholdPct = Num::percent($thresholdPct ?? Defaults::THRESHOLD_PCT);
        $this->dayKey = $dayKey ?? static fn (int $now): string => Num::dayKey($now);
        $this->resetAt = $resetAt ?? static fn (int $now): int => Num::resetAt($now);
    }

    public function check(string $scope, array|object $keys, mixed $req = null): QuotaResult
    {
        $now = $this->clock();
        $day = (string) ($this->dayKey)($now);
        $tokens = Num::nonNegative(($this->estimate)($req));

        return $this->store->mutate($scope, function (array &$state) use ($keys, $tokens, $now, $day): QuotaResult {
            return $this->pick($state, $this->keyList($keys), $tokens, $now, $day);
        });
    }

    public function reserve(string $scope, array|object $keys, mixed $req = null): QuotaResult
    {
        $now = $this->clock();
        $day = (string) ($this->dayKey)($now);
        $tokens = Num::nonNegative(($this->estimate)($req));

        return $this->store->mutate($scope, function (array &$state) use ($scope, $keys, $tokens, $now, $day): QuotaResult {
            $picked = $this->pick($state, $this->keyList($keys), $tokens, $now, $day);

            if (! $picked->ok) {
                return $picked;
            }

            $keyId = $this->keyId($picked->key);
            $keyState =& $this->keyState($state, $keyId, $day);
            $hold = new Hold(
                id: (string) ($this->nextId)(),
                scope: $scope,
                key: $keyId,
                tokens: $tokens,
                at: $now,
                day: $day,
                prevAt: isset($keyState['lastAt']) ? Num::int($keyState['lastAt']) : null,
                prevId: isset($keyState['lastId']) ? (string) $keyState['lastId'] : null,
            );

            $keyState['used'] = Num::nonNegative($keyState['used'] ?? 0) + 1;
            $keyState['lastAt'] = $now;
            $keyState['lastId'] = $hold->id;
            $keyState['hits'][] = ['id' => $hold->id, 'at' => $now, 'tokens' => $tokens];
            $keyState['holds'][$hold->id] = $hold->toArray();

            return QuotaResult::allowed($picked->key, $tokens, $picked->checks, $hold);
        });
    }

    public function commit(Hold|array $hold, array $usage = []): bool
    {
        $hold = Hold::from($hold);
        $tokens = array_key_exists('tokens', $usage)
            ? Num::nonNegative($usage['tokens'], $hold->tokens)
            : null;

        return $this->store->mutate($hold->scope, function (array &$state) use ($hold, $tokens): bool {
            if (! isset($state['keys'][$hold->key]) || ! is_array($state['keys'][$hold->key])) {
                return false;
            }

            $keyState =& $state['keys'][$hold->key];

            if (! isset($keyState['holds'][$hold->id])) {
                return false;
            }

            if ($tokens !== null) {
                foreach ($keyState['hits'] as &$hit) {
                    if (($hit['id'] ?? null) === $hold->id) {
                        $hit['tokens'] = $tokens;
                        break;
                    }
                }
                unset($hit);

                $keyState['holds'][$hold->id]['tokens'] = $tokens;
            }

            unset($keyState['holds'][$hold->id]);

            return true;
        });
    }

    public function rollback(Hold|array $hold): bool
    {
        $hold = Hold::from($hold);

        return $this->store->mutate($hold->scope, function (array &$state) use ($hold): bool {
            if (! isset($state['keys'][$hold->key]) || ! is_array($state['keys'][$hold->key])) {
                return false;
            }

            $keyState =& $state['keys'][$hold->key];
            $stored = $keyState['holds'][$hold->id] ?? null;

            if (! is_array($stored)) {
                return false;
            }

            $storedHold = Hold::from($stored);
            $keyState['hits'] = array_values(array_filter(
                $keyState['hits'] ?? [],
                static fn (mixed $hit): bool => is_array($hit) && ($hit['id'] ?? null) !== $hold->id
            ));

            if (($keyState['day'] ?? null) === $storedHold->day) {
                $keyState['used'] = max(0, Num::nonNegative($keyState['used'] ?? 0) - 1);
            }

            if (($keyState['lastId'] ?? null) === $hold->id) {
                if ($storedHold->prevAt !== null) {
                    $keyState['lastAt'] = $storedHold->prevAt;
                } else {
                    unset($keyState['lastAt']);
                }

                if ($storedHold->prevId !== null) {
                    $keyState['lastId'] = $storedHold->prevId;
                } else {
                    unset($keyState['lastId']);
                }
            }

            unset($keyState['holds'][$hold->id]);

            return true;
        });
    }

    /**
     * @param list<array|object> $keys
     */
    private function pick(array &$state, array $keys, int $tokens, int $now, string $day): QuotaResult
    {
        $checks = array_map(
            fn (array|object $key): KeyCheck => $this->checkKey($state, $key, $tokens, $now, $day),
            $keys
        );
        $allowed = array_values(array_filter($checks, static fn (KeyCheck $check): bool => $check->ok));

        usort($allowed, static function (KeyCheck $left, KeyCheck $right): int {
            return $right->priority <=> $left->priority
                ?: $left->tokenPct <=> $right->tokenPct
                ?: $left->dailyPct <=> $right->dailyPct
                ?: $left->key <=> $right->key;
        });

        if (isset($allowed[0])) {
            foreach ($keys as $key) {
                if ($this->keyId($key) === $allowed[0]->key) {
                    return QuotaResult::allowed($key, $tokens, $checks);
                }
            }
        }

        $blocked = $this->bestBlocked($checks);

        return QuotaResult::blocked(
            reason: $blocked?->reason ?? 'no_key',
            tokens: $tokens,
            waitMs: $blocked?->waitMs,
            checks: $checks,
        );
    }

    private function checkKey(array &$state, array|object $key, int $tokens, int $now, string $day): KeyCheck
    {
        $config = $this->normalizeKey($key);
        $keyState =& $this->keyState($state, $config['id'], $day);

        $this->pruneHits($keyState, $now);

        $cap = Limit::dailyCap($config['rpd'], $this->thresholdPct);
        $tokenUsed = array_reduce(
            $keyState['hits'],
            static fn (int $sum, mixed $hit): int => $sum + Num::nonNegative(is_array($hit) ? ($hit['tokens'] ?? 0) : 0),
            0
        );
        $rpmWait = isset($keyState['lastAt'])
            ? max(0, Limit::cooldownMs($config['rpm'], $this->bufferMs, $this->windowMs) - ($now - Num::int($keyState['lastAt'])))
            : 0;
        $tpmWait = Limit::tokenWaitMs(
            used: $tokenUsed,
            tokens: $tokens,
            limit: $config['tpm'],
            hits: $keyState['hits'],
            now: $now,
            windowMs: $this->windowMs,
            bufferMs: $this->bufferMs,
        );
        $dailyLeft = max(0, $cap - Num::nonNegative($keyState['used'] ?? 0));

        if (! $config['enabled']) {
            return $this->keyCheck($config, false, 'off', null, $rpmWait, $tpmWait, $tokenUsed, $cap, $dailyLeft, $keyState);
        }

        if ($tpmWait === null) {
            return $this->keyCheck($config, false, 'tpm', null, $rpmWait, null, $tokenUsed, $cap, $dailyLeft, $keyState);
        }

        $waits = [];

        if ($dailyLeft <= 0) {
            $waits[] = ['reason' => 'rpd', 'waitMs' => $this->waitUntilReset($now)];
        }

        if ($rpmWait > 0) {
            $waits[] = ['reason' => 'rpm', 'waitMs' => $rpmWait];
        }

        if ($tpmWait > 0) {
            $waits[] = ['reason' => 'tpm', 'waitMs' => $tpmWait];
        }

        $block = $this->keyBlock($waits);

        return $this->keyCheck(
            config: $config,
            ok: $block === null,
            reason: $block['reason'] ?? null,
            waitMs: $block['waitMs'] ?? 0,
            rpmWait: $rpmWait,
            tpmWait: $tpmWait,
            tokenUsed: $tokenUsed,
            dailyCap: $cap,
            dailyLeft: $dailyLeft,
            keyState: $keyState,
        );
    }

    private function keyCheck(
        array $config,
        bool $ok,
        ?string $reason,
        ?int $waitMs,
        int $rpmWait,
        ?int $tpmWait,
        int $tokenUsed,
        int $dailyCap,
        int $dailyLeft,
        array $keyState,
    ): KeyCheck {
        return new KeyCheck(
            key: $config['id'],
            ok: $ok,
            reason: $reason,
            waitMs: $waitMs,
            priority: $config['priority'],
            rpmMs: $rpmWait,
            tpmMs: $tpmWait,
            tokenUsed: $tokenUsed,
            tokenPct: Num::ratio($tokenUsed, $config['tpm']),
            dailyUsed: Num::nonNegative($keyState['used'] ?? 0),
            dailyCap: $dailyCap,
            dailyLeft: $dailyLeft,
            dailyPct: Num::ratio(Num::nonNegative($keyState['used'] ?? 0), $dailyCap),
        );
    }

    private function keyBlock(array $waits): ?array
    {
        if ($waits === []) {
            return null;
        }

        return array_reduce(
            $waits,
            static fn (array $best, array $item): array => $item['waitMs'] > $best['waitMs'] ? $item : $best,
            $waits[0]
        );
    }

    /**
     * @param list<KeyCheck> $checks
     */
    private function bestBlocked(array $checks): ?KeyCheck
    {
        $retryable = array_values(array_filter(
            $checks,
            static fn (KeyCheck $check): bool => ! $check->ok && $check->waitMs !== null
        ));

        if ($retryable !== []) {
            return array_reduce(
                $retryable,
                static fn (KeyCheck $best, KeyCheck $check): KeyCheck => $check->waitMs < $best->waitMs ? $check : $best,
                $retryable[0]
            );
        }

        foreach ($checks as $check) {
            if (! $check->ok) {
                return $check;
            }
        }

        return null;
    }

    private function waitUntilReset(int $now): int
    {
        return max(0, Num::nonNegative(($this->resetAt)($now) - $now));
    }

    private function clock(): int
    {
        return Num::int(($this->now)(), Num::nowMs());
    }

    /**
     * @return list<array|object>
     */
    private function keyList(array|object $keys): array
    {
        if (is_array($keys) && array_is_list($keys)) {
            return $keys;
        }

        return [$keys];
    }

    private function normalizeKey(array|object $key): array
    {
        $id = $this->keyValue($key, 'id');

        if (! is_string($id) && ! is_int($id) && ! is_float($id)) {
            throw new InvalidArgumentException('Quota key needs an id.');
        }

        return [
            'id' => (string) $id,
            'rpm' => Num::positive($this->keyValue($key, 'rpm')),
            'tpm' => Num::positive($this->keyValue($key, 'tpm')),
            'rpd' => Num::positive($this->keyValue($key, 'rpd')),
            'priority' => Num::int($this->keyValue($key, 'priority', 0)),
            'enabled' => $this->keyValue($key, 'enabled', true) !== false,
        ];
    }

    private function keyId(mixed $key): string
    {
        return (string) $this->keyValue($key, 'id');
    }

    private function keyValue(mixed $key, string $field, mixed $default = null): mixed
    {
        if (is_array($key) && array_key_exists($field, $key)) {
            return $key[$field];
        }

        if (is_object($key) && isset($key->{$field})) {
            return $key->{$field};
        }

        return $default;
    }

    private function normalizeState(array &$state): void
    {
        if (! isset($state['keys']) || ! is_array($state['keys'])) {
            $state['keys'] = [];
        }
    }

    private function &keyState(array &$state, string $key, string $day): array
    {
        $this->normalizeState($state);

        if (! isset($state['keys'][$key]) || ! is_array($state['keys'][$key])) {
            $state['keys'][$key] = [
                'day' => $day,
                'used' => 0,
                'hits' => [],
                'holds' => [],
            ];
        }

        $keyState =& $state['keys'][$key];
        $keyState['hits'] = is_array($keyState['hits'] ?? null) ? $keyState['hits'] : [];
        $keyState['holds'] = is_array($keyState['holds'] ?? null) ? $keyState['holds'] : [];
        $keyState['used'] = Num::nonNegative($keyState['used'] ?? 0);

        if (($keyState['day'] ?? null) !== $day) {
            $keyState['day'] = $day;
            $keyState['used'] = 0;
        }

        return $keyState;
    }

    private function pruneHits(array &$keyState, int $now): void
    {
        $minAt = $now - $this->windowMs;
        $keyState['hits'] = array_values(array_filter(
            $keyState['hits'],
            static function (mixed $hit) use ($minAt, $now): bool {
                if (! is_array($hit)) {
                    return false;
                }

                $at = Num::int($hit['at'] ?? null, PHP_INT_MIN);

                return $at >= $minAt
                    && $at <= $now
                    && Num::nonNegative($hit['tokens'] ?? 0) > 0;
            }
        ));
    }
}
