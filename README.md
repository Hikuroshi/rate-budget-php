# hikuroshi/rate-budget

A multi-key quota decision engine for RPM, TPM, and RPD-aware routing.

`hikuroshi/rate-budget` selects an active key within a scope such as a tenant,
workspace, project, user, or guild. Each key has independent RPM, TPM, and RPD
limits, so your application can safely move to another key when one key is
cooling down, under token pressure, or near its daily quota.

The package is lightweight, dependency-free, and storage adapter friendly. Your
application owns the state; `hikuroshi/rate-budget` only computes quota
decisions and records reservations through the configured adapter.

## Install

```bash
composer require hikuroshi/rate-budget
```

## Core Algorithm

RPM: spacing-based cooldown.

```txt
cooldownMs = ceil(60000 / rpm) + bufferMs
```

TPM: sliding 60-second token window.

```txt
usedTokensLast60s + estimatedRequestTokens <= tpmLimit
```

RPD: daily threshold guard.

```txt
dailyCap = ceil(rpdLimit * thresholdPct / 100)
```

## Quick Start

Use a single key when you do not need key rotation yet.

```php
<?php

use Hikuroshi\RateBudget\Quota;

$quota = new Quota();
$key = ['id' => 'key-a', 'rpm' => 15, 'tpm' => 250_000, 'rpd' => 500];

$reserved = $quota->reserve('scope:123', $key, ['tokens' => 800]);

if (! $reserved->ok) {
    throw new RuntimeException("Quota blocked: {$reserved->reason}");
}

try {
    $response = callProvider($reserved->key);

    $quota->commit($reserved->hold, [
        'tokens' => $response->usage->totalTokens,
    ]);
} catch (Throwable $error) {
    $quota->rollback($reserved->hold);

    throw $error;
}
```

Use multiple keys with priority when you want automatic key rotation.

```php
<?php

use Hikuroshi\RateBudget\Quota;

$quota = new Quota(['thresholdPct' => 90]);

$keys = [
    ['id' => 'key-a', 'rpm' => 15, 'tpm' => 250_000, 'rpd' => 500, 'priority' => 10],
    ['id' => 'key-b', 'rpm' => 15, 'tpm' => 250_000, 'rpd' => 500, 'priority' => 5],
];

$reserved = $quota->reserve('tenant:acme', $keys, ['tokens' => 2_400]);

if (! $reserved->ok) {
    if ($reserved->waitMs !== null) {
        queueForLater($reserved->waitMs);
        return;
    }

    throw new RuntimeException("No quota key available: {$reserved->reason}");
}

$response = callProvider($reserved->key);

$quota->commit($reserved->hold, [
    'tokens' => $response->usage->totalTokens,
]);
```

## API

### `new Quota($options = [])`

```php
use Hikuroshi\RateBudget\MemoryStore;
use Hikuroshi\RateBudget\Quota;

$quota = new Quota([
    'store' => new MemoryStore(),
    'estimate' => static fn (array $req): int => $req['inputTokens'] + $req['maxOutputTokens'],
    'windowMs' => 60_000,
    'bufferMs' => 1_000,
    'thresholdPct' => 90,
]);
```

Constructor options:

- `store`: custom storage adapter. Defaults to `MemoryStore`.
- `estimate($req)`: token estimator. Defaults to `$req['tokens']`, numeric request, object `tokens`, or `1`.
- `now()`: custom clock returning Unix milliseconds.
- `id()`: reservation id generator.
- `windowMs`: token window. Defaults to `60000`.
- `bufferMs`: RPM/TPM safety buffer. Defaults to `1000`.
- `thresholdPct`: RPD threshold percent. Defaults to `100`.
- `dayKey($now)`: daily bucket key. Defaults to UTC `YYYY-MM-DD`.
- `resetAt($now)`: timestamp for the next daily reset. Defaults to next UTC day.

### `reserve($scope, $keyOrKeys, $req = null)`

Selects the best key and immediately creates a reservation for RPM, TPM, and
RPD accounting. `$keyOrKeys` can be a single key array/object or a list of keys.

```php
$single = $quota->reserve('tenant:acme', $key, ['tokens' => 1_500]);
$result = $quota->reserve('tenant:acme', $keys, ['tokens' => 1_500]);
```

Successful result:

```php
$result->ok;      // true
$result->key;     // selected key, preserving extra fields
$result->hold;    // Hold object for commit/rollback
$result->tokens;  // estimated tokens
$result->waitMs;  // 0
$result->checks;  // list<KeyCheck>
```

Blocked result:

```php
$result->ok;      // false
$result->reason;  // "rpm", "tpm", "rpd", "off", or "no_key"
$result->waitMs;  // int|null
$result->tokens;  // estimated tokens
$result->checks;  // list<KeyCheck>
```

`waitMs` is `null` when the request cannot be handled by any key, for example
when the estimated token count is larger than every key's TPM limit.

### `commit($hold, $usage = [])`

Completes a reservation after the request has actually consumed provider quota.

```php
$quota->commit($reserved->hold, ['tokens' => $actualTokens]);
```

### `rollback($hold)`

Cancels a reservation when a request was not sent or failed before provider
quota was consumed.

```php
$quota->rollback($reserved->hold);
```

### `check($scope, $keyOrKeys, $req = null)`

Runs a dry check to see which key would be selected without creating a
reservation.

```php
$single = $quota->check('workspace:42', $key, ['tokens' => 800]);
$decision = $quota->check('workspace:42', $keys, ['tokens' => 800]);
```

`check()` still touches storage to normalize scope/key state and prune old token
hits, but it does not create a hold or consume quota.

## Key Shape

```php
$key = [
    'id' => 'key-a',
    'rpm' => 15,
    'tpm' => 250_000,
    'rpd' => 500,
    'priority' => 10,
    'enabled' => true,
];
```

Fields:

- `id`: stable key id.
- `rpm`: requests per minute.
- `tpm`: tokens per minute.
- `rpd`: requests per day.
- `priority`: higher value is preferred. Defaults to `0`.
- `enabled`: set `false` to skip a key. Defaults to `true`.

Extra fields are preserved on the selected key, so you can attach provider
names, encrypted key references, model names, or metadata.

## Selection Rules

1. Disabled keys are skipped.
2. A key must pass RPD threshold, RPM cooldown, and TPM window checks.
3. Among allowed keys, the highest `priority` wins.
4. Ties prefer lower token pressure, then lower daily pressure, then key id.
5. If no key is allowed, the result returns the shortest useful `waitMs`.

## Storage Adapter

`MemoryStore` is useful for one process. For multi-instance systems, implement
`QuotaStore` with Redis, SQL, DynamoDB, Laravel cache with locks, or any
transactional storage.

```php
<?php

use Hikuroshi\RateBudget\QuotaStore;

final class DatabaseQuotaStore implements QuotaStore
{
    public function mutate(string $scope, callable $fn): mixed
    {
        return DB::transaction(function () use ($scope, $fn) {
            $state = DB::table('quota_states')
                ->where('scope', $scope)
                ->lockForUpdate()
                ->value('state');

            $state = $state ? json_decode($state, true) : ['keys' => []];
            $result = $fn($state);

            DB::table('quota_states')->updateOrInsert(
                ['scope' => $scope],
                ['state' => json_encode($state)]
            );

            return $result;
        });
    }
}
```

The `mutate` callback must run atomically per scope. That keeps reservations
safe when many workers route requests at the same time.

## Helpers

```php
use const Hikuroshi\RateBudget\BUFFER_MS;
use const Hikuroshi\RateBudget\THRESHOLD_PCT;
use const Hikuroshi\RateBudget\WINDOW_MS;
use function Hikuroshi\RateBudget\cooldownMs;
use function Hikuroshi\RateBudget\dailyCap;
use function Hikuroshi\RateBudget\tokenWaitMs;

cooldownMs(rpm: 15);
dailyCap(rpd: 500, thresholdPct: 90);
tokenWaitMs(
    used: 2_000,
    tokens: 800,
    limit: 10_000,
    hits: [['id' => 'hit-1', 'at' => time() * 1000, 'tokens' => 2_000]],
    now: time() * 1000,
);
```

Exports:

- `WINDOW_MS`, `BUFFER_MS`, and `THRESHOLD_PCT` constants.
- `cooldownMs()`
- `dailyCap()`
- `tokenWaitMs()`
- `Defaults`: class wrapper for default constants.
- `Limit::cooldownMs()`
- `Limit::dailyCap()`
- `Limit::tokenWaitMs()`
- `Quota`
- `QuotaManager`: alias for `Quota`.
- `MemoryStore`
- `QuotaStore`
- `QuotaResult`
- `KeyCheck`
- `Hold`

## Build and Test

```bash
composer dump-autoload
composer check
composer test
```
