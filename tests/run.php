<?php

declare(strict_types=1);

use Hikuroshi\RateBudget\Limit;
use Hikuroshi\RateBudget\MemoryStore;
use Hikuroshi\RateBudget\Quota;
use Hikuroshi\RateBudget\QuotaManager;
use function Hikuroshi\RateBudget\cooldownMs;
use function Hikuroshi\RateBudget\dailyCap;
use function Hikuroshi\RateBudget\tokenWaitMs;

require __DIR__ . '/../vendor/autoload.php';

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

$now = 1_700_000_000_000;

check(Limit::cooldownMs(15) === 5_000, 'RPM cooldown failed.');
check(Limit::dailyCap(500, 90) === 450, 'Daily cap failed.');
check(cooldownMs(15) === 5_000, 'RPM cooldown function failed.');
check(dailyCap(500, 90) === 450, 'Daily cap function failed.');
check(
    Limit::tokenWaitMs(
        used: 90,
        tokens: 20,
        limit: 100,
        hits: [['id' => 'h1', 'at' => $now - 1_000, 'tokens' => 90]],
        now: $now
    ) === 60_000,
    'Token wait failed.'
);
check(
    tokenWaitMs(
        used: 90,
        tokens: 20,
        limit: 100,
        hits: [['id' => 'h1', 'at' => $now - 1_000, 'tokens' => 90]],
        now: $now
    ) === 60_000,
    'Token wait function failed.'
);
check(
    Limit::tokenWaitMs(
        used: 0,
        tokens: 101,
        limit: 100,
        hits: [],
        now: $now
    ) === null,
    'Token overflow failed.'
);

$store = new MemoryStore();
$quota = new Quota([
    'store' => $store,
    'now' => static fn (): int => $now,
    'id' => static fn (): string => 'hold-1',
]);
$key = ['id' => 'key-a', 'rpm' => 15, 'tpm' => 250_000, 'rpd' => 500];

$reserved = $quota->reserve('scope:1', $key, ['tokens' => 800]);
check($reserved->ok && $reserved->hold !== null && $reserved->key === $key, 'Single key reserve failed.');
check(! array_key_exists('reason', $reserved->toArray()), 'Allowed result shape failed.');
check($quota->commit($reserved->hold, ['tokens' => 742]), 'Commit failed.');
check($store->get('scope:1')['keys']['key-a']['hits'][0]['tokens'] === 742, 'Commit token update failed.');

$quota = new Quota(
    store: new MemoryStore(),
    now: static fn (): int => $now,
    id: static fn (): string => 'hold-2',
);
$keys = [
    ['id' => 'key-a', 'rpm' => 15, 'tpm' => 250_000, 'rpd' => 500, 'priority' => 5],
    ['id' => 'key-b', 'rpm' => 15, 'tpm' => 250_000, 'rpd' => 500, 'priority' => 10],
];
$picked = $quota->check('scope:2', $keys, 1_500);
check($picked->ok && $picked->key['id'] === 'key-b', 'Priority selection failed.');

$store = new MemoryStore();
$quota = new Quota(
    store: $store,
    now: static fn (): int => $now,
    id: static fn (): string => 'hold-3',
);
$held = $quota->reserve('scope:3', ['id' => 'key-a', 'rpm' => 15, 'tpm' => 1_000, 'rpd' => 500], 800);
check($held->ok && $held->hold !== null, 'Rollback reserve failed.');
$blocked = $quota->check('scope:3', ['id' => 'key-a', 'rpm' => 15, 'tpm' => 1_000, 'rpd' => 500], 100);
check(! $blocked->ok && $blocked->reason === 'rpm' && $blocked->waitMs === 5_000, 'RPM block failed.');
check($quota->rollback($held->hold), 'Rollback failed.');
check($store->get('scope:3')['keys']['key-a']['used'] === 0, 'Rollback usage restore failed.');

$quota = new Quota(
    now: static fn (): int => $now,
    id: static fn (): string => 'hold-rpd',
    thresholdPct: 100,
);
$dailyKey = ['id' => 'daily', 'rpm' => 999, 'tpm' => 999_999, 'rpd' => 1];
check($quota->reserve('scope:4', $dailyKey, 1)->ok, 'RPD first reserve failed.');
$rpdBlocked = $quota->check('scope:4', $dailyKey, 1);
check(! $rpdBlocked->ok && $rpdBlocked->reason === 'rpd' && $rpdBlocked->waitMs > 0, 'RPD block failed.');

$quota = new Quota(now: static fn (): int => $now);
$tpmBlocked = $quota->check('scope:5', ['id' => 'tiny', 'rpm' => 999, 'tpm' => 10, 'rpd' => 999], 11);
check(! $tpmBlocked->ok && $tpmBlocked->reason === 'tpm' && $tpmBlocked->waitMs === null, 'TPM hard block failed.');
check($tpmBlocked->toArray()['reason'] === 'tpm', 'Blocked result shape failed.');

$store = new MemoryStore();
$store->set('scope:6', ['keys' => []]);
check($store->size() === 1 && $store->get('scope:6') !== null, 'MemoryStore set/get failed.');
$store->reset('scope:6');
check($store->size() === 0, 'MemoryStore reset failed.');

$manager = new QuotaManager(now: static fn (): int => $now);
check($manager->check('scope:7', ['id' => 'alias', 'rpm' => 1, 'tpm' => 1, 'rpd' => 1], 1)->ok, 'QuotaManager alias failed.');

echo "All tests passed.\n";
