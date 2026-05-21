<?php

declare(strict_types=1);

use Hikuroshi\RateBudget\Budget;
use Hikuroshi\RateBudget\Cooldown;
use Hikuroshi\RateBudget\Limit;
use Hikuroshi\RateBudget\MemoryCooldown;
use Hikuroshi\RateBudget\Retry;

require __DIR__ . '/../vendor/autoload.php';

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

$now = 1_700_000_000_000;

check(Limit::spacing(60, ['windowMs' => 60_000, 'bufferMs' => 1_000]) === 2_000, 'Limit spacing failed.');

$count = Limit::count(90, 100, 90);
check(! $count->allowed && $count->left === 0 && $count->threshold === 90, 'Count limit failed.');

$window = Limit::window(
    incoming: 20,
    limit: 100,
    entries: [
        ['at' => $now - 1_000, 'cost' => 90],
    ],
    options: ['now' => $now]
);
check(! $window->allowed && $window->reason === 'window_exhausted' && $window->retryMs === 60_000, 'Window usage failed.');

$profile = Limit::profile(rpm: 60, tpm: 6_000, rpd: 1_000, options: ['dailyPercent' => 80]);
check($profile->cooldownMs === 2_000 && $profile->dailyLimit === 800, 'Limit profile failed.');

check(Budget::perRequest(tokens: 6_000, spacingMs: 1_000) === 100, 'Per request budget failed.');
check(Budget::portion(100, 0.25) === 25, 'Budget portion failed.');

$split = Budget::split(100, 100, [
    ['name' => 'chat', 'target' => 0.5, 'max' => 0.7],
    ['name' => 'embed', 'target' => 0.3, 'max' => 0.5],
]);
check($split === ['chat' => 70, 'embed' => 30], 'Flexible budget split failed.');

$first = Cooldown::consume(null, actor: 'u1', sameMs: 1_000, diffMs: 2_000, now: $now);
$second = Cooldown::consume($first->state, actor: 'u1', sameMs: 1_000, diffMs: 2_000, now: $now + 500);
check($first->allowed && ! $second->allowed && $second->retryMs === 500, 'Cooldown failed.');

$memory = new MemoryCooldown(sameMs: 1_000, diffMs: 2_000);
check($memory->consume('room:1', 'u1', $now)->allowed, 'Memory cooldown first consume failed.');
check(! $memory->consume('room:1', 'u2', $now + 1_000)->allowed, 'Memory cooldown diff actor failed.');
check($memory->size() === 1, 'Memory cooldown size failed.');
$memory->reset('room:1');
check($memory->size() === 0, 'Memory cooldown reset failed.');

$tries = 0;
$value = Retry::run(
    task: function (int $attempt) use (&$tries): string {
        $tries++;

        if ($attempt === 0) {
            throw new RuntimeException('Too many requests', 429);
        }

        return 'ok';
    },
    retries: 2
);
check($value === 'ok' && $tries === 2, 'Retry run failed.');

echo "All tests passed.\n";
