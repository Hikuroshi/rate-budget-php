# hikuroshi/rate-budget

`hikuroshi/rate-budget` is a small, dependency-free Composer package for
calculating request rate limits, quota thresholds, token or cost budgets,
cooldowns, and retries.

It is provider-agnostic and storage-agnostic. The package does not manage your
database, Redis keys, queues, locks, or HTTP clients. Your application owns the
state; `hikuroshi/rate-budget` only calculates deterministic decisions from the
counters and events you pass in.

## Requirements

- PHP 8.1 or newer
- Composer
- No runtime dependencies

## Installation

```bash
composer require hikuroshi/rate-budget
```

## Features

- Request spacing for limits such as RPM, RPH, or any count-per-window limit.
- Sliding-window cost checks for tokens, bytes, credits, points, or custom units.
- Daily quota thresholds such as RPD with configurable safety percentages.
- Per-request token or cost budget planning.
- Flexible budget splitting for context, input, output, metadata, and similar allocations.
- Pure actor cooldown helper for Redis, database, cache, or custom storage.
- Lightweight in-memory actor cooldowns for single-process use cases.
- Retry helper with configurable status codes, message fragments, and delays.
- Small immutable result objects with `toArray()` helpers.
- PSR-4 autoloading.
- Zero runtime dependencies.

## Imports

```php
<?php

use Hikuroshi\RateBudget\Budget;
use Hikuroshi\RateBudget\Cooldown;
use Hikuroshi\RateBudget\Limit;
use Hikuroshi\RateBudget\MemoryCooldown;
use Hikuroshi\RateBudget\Retry;
use Hikuroshi\RateBudget\WindowEntry;
```

## Core Model

`hikuroshi/rate-budget` is designed around pure calculations:

1. Read current usage and recent events from your own storage.
2. Pass those values into `hikuroshi/rate-budget`.
3. If the decision requires waiting, delay, reject, or enqueue in your application.
4. If the request is allowed, reserve or log the request atomically.
5. After the request finishes, update usage in your storage.

This keeps the package lightweight while still fitting distributed systems,
serverless applications, workers, bots, API gateways, and cron jobs.

Time values are in milliseconds. Timestamps can be `DateTimeInterface`, Unix
milliseconds, numeric strings, date strings, or `null` depending on the method.

## RPM, TPM, and RPD Example

```php
<?php

use Hikuroshi\RateBudget\Limit;

$profile = Limit::profile(
    rpm: 15,
    tpm: 250_000,
    rpd: 500,
    options: [
        'dailyPercent' => 90,
    ],
);

$daily = Limit::count(
    used: $usageToday['request_count'],
    limit: $profile->rpd,
    percent: $profile->dailyPercent,
);

if (! $daily->allowed) {
    throw new RuntimeException('Daily quota threshold reached.');
}

$cooldownMs = Limit::wait(
    lastAt: $lastApiRequest['created_at'] ?? null,
    cooldownMs: $profile->cooldownMs,
);

$tokenMs = Limit::windowWait(
    incoming: $estimatedRequestTokens,
    limit: $profile->tpm,
    entries: array_map(
        static fn (array $request): array => [
            'at' => $request['created_at'],
            'cost' => $request['total_tokens'],
        ],
        $recentApiRequests,
    ),
);

$waitMs = max($cooldownMs, $tokenMs);

if ($waitMs > 0) {
    // Sleep, queue, reject, or reschedule in your application.
}
```

## Sliding-Window Cost Limit

Use `Limit::window()` when you need the full decision, or
`Limit::windowWait()` when you only need the wait time.

```php
<?php

use Hikuroshi\RateBudget\Limit;

$now = strtotime('2026-05-21 14:00:40') * 1000;
$decision = Limit::window(
    incoming: 500,
    limit: 10_000,
    entries: [
        ['at' => $now - 30_000, 'cost' => 4_000],
        ['at' => $now - 10_000, 'cost' => 6_200],
    ],
    options: [
        'now' => $now,
        'windowMs' => 60_000,
        'bufferMs' => 1_000,
    ],
);

if (! $decision->allowed && $decision->retryMs !== null) {
    usleep($decision->retryMs * 1000);
}
```

`cost` can represent tokens, weighted requests, bytes, credits, rows, points,
or any other unit that should be limited over time.

`Limit::window()` returns `WindowResult`:

```php
$decision->allowed; // bool
$decision->reason;  // "within_limit", "single_request_exceeds_limit", or "window_exhausted"
$decision->cost;    // projected cost after the incoming request
$decision->limit;   // normalized limit
$decision->retryMs; // int, 0, or null
```

## Count Quotas

Use `Limit::count()` for daily, monthly, or fixed-period counters where your
application already stores the current count.

```php
<?php

use Hikuroshi\RateBudget\Limit;

$decision = Limit::count(
    used: 450,
    limit: 500,
    percent: 90,
);

if (! $decision->allowed) {
    throw new RuntimeException('Quota reached.');
}

echo $decision->left; // remaining requests before threshold
```

`Limit::count()` returns `CountResult`:

```php
$decision->allowed;   // bool
$decision->used;      // normalized used count
$decision->threshold; // calculated threshold count
$decision->left;      // remaining count before threshold
```

## Token Budget Planning

```php
<?php

use Hikuroshi\RateBudget\Budget;

$totalBudget = Budget::perRequest(
    tokens: 250_000,
    spacingMs: 5_000,
    options: [
        'min' => 96,
    ],
);

$inputBudget = Budget::portion($totalBudget, 0.5);

$flexible = Budget::split(
    parent: $inputBudget,
    available: $inputBudget - $systemPromptTokens - $userPromptTokens,
    parts: [
        ['name' => 'context', 'target' => 0.55, 'max' => 0.60],
        ['name' => 'metadata', 'target' => 0.15, 'max' => 0.20],
    ],
);

echo $flexible['context'];
echo $flexible['metadata'];
```

`Budget::split()` first allocates each part up to its `target` ratio, then uses
remaining budget up to each part's `max` ratio in order.

## In-Memory Actor Cooldowns

Use `MemoryCooldown` for local process cooldowns, such as UX cooldowns in a bot
or a simple API server. For multi-instance systems, store the cooldown state in
Redis, a database, Laravel cache, or another shared store and use the pure
`Cooldown::consume()` helper instead.

```php
<?php

use Hikuroshi\RateBudget\MemoryCooldown;

$limiter = new MemoryCooldown(
    sameMs: 3_000,
    diffMs: 1_000,
    ttlMs: 5 * 60_000,
);

$decision = $limiter->consume(
    scope: 'guild:123',
    actor: 'user:456',
);

if (! $decision->allowed) {
    echo "Retry after {$decision->retryMs}ms";
}
```

`MemoryCooldown` keeps state in PHP memory only. State is lost when the process
restarts and is not shared across workers.

## Pure Actor Cooldowns

Use `Cooldown::consume()` when your application owns the state.

```php
<?php

use Hikuroshi\RateBudget\Cooldown;

$state = $cache->get('cooldown:guild:123');

$decision = Cooldown::consume(
    state: $state,
    actor: 'user:456',
    sameMs: 3_000,
    diffMs: 1_000,
);

if (! $decision->allowed) {
    return ['retry_after_ms' => $decision->retryMs];
}

$cache->set('cooldown:guild:123', $decision->state->toArray(), ttl: 300);
```

`Cooldown::consume()` accepts `CooldownState`, array state, or `null`. Array
state can use short keys (`at`, `actor`) or JS-compatible keys
(`lastAcceptedAt`, `lastActorId`).

## Retry

```php
<?php

use Hikuroshi\RateBudget\Retry;
use Throwable;

$response = Retry::run(
    task: static fn (int $attempt) => fetchProvider(),
    retries: 2,
    delayMs: static fn (int $attempt, Throwable $error): int => 1_000 * ($attempt + 1),
    shouldRetry: static fn (Throwable $error): bool => Retry::retryable($error, [
        'codes' => [429, 500, 502, 503, 504],
        'messages' => ['rate limit', 'temporarily unavailable'],
    ]),
    onRetry: static function (int $attempt, int $delayMs, Throwable $error): void {
        error_log("Retry {$attempt} in {$delayMs}ms: {$error->getMessage()}");
    },
);
```

By default, `Retry::run()` retries errors with status codes `429`, `500`, and
`503`. You can override retry behavior with `shouldRetry`.

`Retry::status()` supports common exception shapes:

- `getStatusCode()`
- `getStatus()`
- public `status`
- public `statusCode`
- throwable code
- array keys `status` or `statusCode`

## API Overview

### `Limit`

- `Limit::spacing($limit, $options = [])`: calculates safe request spacing inside a time window.
- `Limit::inactiveSpacing($limit, $options = [])`: calculates inactive cooldown spacing with a multiplier.
- `Limit::threshold($limit, $percent = 100)`: calculates a safe quota threshold.
- `Limit::count($used, $limit, $percent = 100)`: evaluates count-based quota usage.
- `Limit::wait($lastAt, $cooldownMs, $now = null)`: calculates remaining cooldown from the last accepted timestamp.
- `Limit::window($incoming, $limit, $entries = [], $options = [])`: evaluates sliding-window cost usage.
- `Limit::windowWait($incoming, $limit, $entries = [], $options = [])`: returns only the wait time for sliding-window cost usage.
- `Limit::profile($rpm, $tpm, $rpd, $options = [])`: creates a normalized RPM, TPM, RPD, threshold, cooldown, and inactive cooldown profile.

Supported `Limit` options:

- `windowMs`: window length in milliseconds. Default: `60_000`.
- `bufferMs`: safety buffer in milliseconds. Default: `1_000`.
- `safetyBufferMs`: alias for `bufferMs`.
- `dailyPercent`: daily threshold percentage for `profile()`.
- `dailyThresholdPercent`: alias for `dailyPercent`.
- `inactiveMultiplier`: inactive cooldown multiplier for `profile()`.
- `inactiveCooldownMultiplier`: alias for `inactiveMultiplier`.
- `current`: current cost override for `window()`.
- `currentCost`: alias for `current`.
- `sorted`: whether window entries are already sorted oldest first. Default: `true`.
- `entriesSortedOldestFirst`: alias for `sorted`.
- `now`: current timestamp in milliseconds for deterministic tests.

### `Budget`

- `Budget::perRequest($tokens, $spacingMs, $options = [])`: calculates cost or token budget per request.
- `Budget::portion($total, $ratio)`: returns a ratio-based budget portion.
- `Budget::split($parent, $available, $parts)`: allocates flexible budget across named partitions.

Supported `Budget::perRequest()` options:

- `windowMs`: window length in milliseconds. Default: `60_000`.
- `min`: minimum per-request budget. Default: `1`.
- `minimumTokens`: alias for `min`.

### `Cooldown`

- `Cooldown::consume($state, $actor, $sameMs, $diffMs, $now = null)`: evaluates and returns the next cooldown state.

### `MemoryCooldown`

- `new MemoryCooldown($sameMs, $diffMs, $ttlMs = 300_000, $cleanup = 256)`: creates an in-memory limiter.
- `$limiter->consume($scope, $actor, $now = null)`: evaluates a scoped actor cooldown.
- `$limiter->reset($scope = null)`: clears one scope or all scopes.
- `$limiter->size()`: returns stored scope count.

### `Retry`

- `Retry::run($task, $retries, $delayMs = 0, $shouldRetry = null, $onRetry = null)`: retries a callable task.
- `Retry::retryable($error, $options = [])`: checks status codes and message fragments.
- `Retry::status($error)`: extracts an HTTP-like status code when possible.
- `Retry::message($error)`: extracts an error message when possible.
- `Retry::sleep($ms)`: sleeps for a millisecond duration.

Supported `Retry::retryable()` options:

- `codes`: retryable status codes. Default: `[429, 500, 503]`.
- `statusCodes`: alias for `codes`.
- `messages`: case-insensitive message fragments.
- `messageIncludes`: alias for `messages`.

## Result Objects

All result objects expose public readonly properties and `toArray()`.

- `LimitProfile`: `rpm`, `tpm`, `rpd`, `dailyPercent`, `dailyLimit`, `cooldownMs`, `inactiveMs`.
- `CountResult`: `allowed`, `used`, `threshold`, `left`.
- `WindowResult`: `allowed`, `reason`, `cost`, `limit`, `retryMs`.
- `CooldownState`: `at`, `actor`.
- `CooldownResult`: `allowed`, `state`, `retryMs`.

## Build and Test

From the package directory:

```bash
composer dump-autoload
composer test
composer validate --strict
```
