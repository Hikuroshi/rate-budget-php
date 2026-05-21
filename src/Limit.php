<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use DateTimeInterface;
use Hikuroshi\RateBudget\Support\Num;

final class Limit
{
    public const DEFAULT_WINDOW_MS = 60_000;

    public const DEFAULT_BUFFER_MS = 1_000;

    public const DEFAULT_INACTIVE_MULTIPLIER = 2;

    public static function spacing(int|float $limit, array $options = []): int
    {
        $safeLimit = Num::positive($limit);
        $windowMs = Num::positive(self::option($options, 'windowMs', self::DEFAULT_WINDOW_MS));
        $bufferMs = Num::nonNegative(
            self::option($options, 'bufferMs', self::DEFAULT_BUFFER_MS, ['safetyBufferMs'])
        );

        return (int) ceil($windowMs / $safeLimit + $bufferMs);
    }

    public static function inactiveSpacing(int|float $limit, array $options = []): int
    {
        $multiplier = max(
            1.0,
            (float) Num::finite(
                self::option($options, 'multiplier', self::DEFAULT_INACTIVE_MULTIPLIER),
                self::DEFAULT_INACTIVE_MULTIPLIER
            )
        );

        return (int) ceil(self::spacing($limit, $options) * $multiplier);
    }

    public static function threshold(int|float $limit, int|float $percent = 100): int
    {
        return max(1, (int) ceil(Num::positive($limit) * (Num::percent($percent) / 100)));
    }

    public static function count(int|float $used, int|float $limit, int|float $percent = 100): CountResult
    {
        $safeUsed = Num::nonNegative($used);
        $threshold = self::threshold($limit, $percent);
        $left = max(0, $threshold - $safeUsed);

        return new CountResult(
            allowed: $left > 0,
            used: $safeUsed,
            threshold: $threshold,
            left: $left
        );
    }

    public static function wait(
        DateTimeInterface|int|float|string|null $lastAt,
        int|float $cooldownMs,
        int|float|null $now = null,
    ): int {
        $lastMs = Num::timeMs($lastAt);

        if ($lastMs === null) {
            return 0;
        }

        $nowMs = $now === null ? Num::nowMs() : Num::int($now, Num::nowMs());

        return max(0, Num::nonNegative($cooldownMs) - ($nowMs - $lastMs));
    }

    public static function window(
        int|float $incoming,
        int|float $limit,
        array $entries = [],
        array $options = [],
    ): WindowResult {
        $safeLimit = Num::positive($limit);
        $incomingCost = Num::nonNegative($incoming);
        $now = Num::int(self::option($options, 'now', Num::nowMs()), Num::nowMs());
        $windowMs = Num::positive(self::option($options, 'windowMs', self::DEFAULT_WINDOW_MS));
        $rows = self::entries(
            entries: $entries,
            sorted: (bool) self::option($options, 'sorted', true, ['entriesSortedOldestFirst']),
            now: $now,
            windowMs: $windowMs
        );

        $current = self::hasOption($options, 'current', ['currentCost'])
            ? Num::nonNegative(self::option($options, 'current', 0, ['currentCost']))
            : array_reduce($rows, static fn (int $sum, array $row): int => $sum + $row['cost'], 0);
        $cost = $current + $incomingCost;

        if ($incomingCost > $safeLimit) {
            return new WindowResult(false, 'single_request_exceeds_limit', $cost, $safeLimit, null);
        }

        if ($cost <= $safeLimit) {
            return new WindowResult(true, 'within_limit', $cost, $safeLimit, 0);
        }

        $bufferMs = Num::nonNegative(
            self::option($options, 'bufferMs', self::DEFAULT_BUFFER_MS, ['safetyBufferMs'])
        );
        $remaining = $cost;

        foreach ($rows as $row) {
            $remaining -= $row['cost'];

            if ($remaining <= $safeLimit) {
                return new WindowResult(
                    false,
                    'window_exhausted',
                    $cost,
                    $safeLimit,
                    max(0, $windowMs - ($now - $row['at']) + $bufferMs)
                );
            }
        }

        return new WindowResult(false, 'window_exhausted', $cost, $safeLimit, $windowMs);
    }

    public static function windowWait(
        int|float $incoming,
        int|float $limit,
        array $entries = [],
        array $options = [],
    ): int {
        $result = self::window($incoming, $limit, $entries, $options);

        if ($result->allowed) {
            return 0;
        }

        return $result->retryMs ?? Num::positive(
            self::option($options, 'windowMs', self::DEFAULT_WINDOW_MS),
            self::DEFAULT_WINDOW_MS
        );
    }

    public static function profile(int|float $rpm, int|float $tpm, int|float $rpd, array $options = []): LimitProfile
    {
        $safeRpm = Num::positive($rpm);
        $safeTpm = Num::positive($tpm);
        $safeRpd = Num::positive($rpd);
        $dailyPercent = Num::percent(self::option($options, 'dailyPercent', 100, ['dailyThresholdPercent']));
        $spacingOptions = [
            'windowMs' => self::option($options, 'windowMs', self::DEFAULT_WINDOW_MS),
            'bufferMs' => self::option($options, 'bufferMs', self::DEFAULT_BUFFER_MS, ['safetyBufferMs']),
        ];

        return new LimitProfile(
            rpm: $safeRpm,
            tpm: $safeTpm,
            rpd: $safeRpd,
            dailyPercent: $dailyPercent,
            dailyLimit: self::threshold($safeRpd, $dailyPercent),
            cooldownMs: self::spacing($safeRpm, $spacingOptions),
            inactiveMs: self::inactiveSpacing($safeRpm, [
                ...$spacingOptions,
                'multiplier' => self::option(
                    $options,
                    'inactiveMultiplier',
                    self::DEFAULT_INACTIVE_MULTIPLIER,
                    ['inactiveCooldownMultiplier']
                ),
            ])
        );
    }

    private static function hasOption(array $options, string $name, array $aliases = []): bool
    {
        if (array_key_exists($name, $options)) {
            return true;
        }

        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $options)) {
                return true;
            }
        }

        return false;
    }

    private static function option(array $options, string $name, mixed $default = null, array $aliases = []): mixed
    {
        if (array_key_exists($name, $options)) {
            return $options[$name];
        }

        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $options)) {
                return $options[$alias];
            }
        }

        return $default;
    }

    /**
     * @return list<array{at: int, cost: int}>
     */
    private static function entries(array $entries, bool $sorted, int $now, int $windowMs): array
    {
        $rows = [];

        foreach ($entries as $entry) {
            $at = Num::timeMs(self::entryValue($entry, 'at', ['occurredAt']));

            if ($at === null || $at < $now - $windowMs || $at > $now) {
                continue;
            }

            $rows[] = [
                'at' => $at,
                'cost' => Num::nonNegative(self::entryValue($entry, 'cost', default: 0)),
            ];
        }

        if (! $sorted) {
            usort($rows, static fn (array $left, array $right): int => $left['at'] <=> $right['at']);
        }

        return $rows;
    }

    private static function entryValue(mixed $entry, string $name, array $aliases = [], mixed $default = null): mixed
    {
        if ($entry instanceof WindowEntry) {
            return match ($name) {
                'at' => $entry->at,
                'cost' => $entry->cost,
                default => $default,
            };
        }

        if (is_array($entry)) {
            if (array_key_exists($name, $entry)) {
                return $entry[$name];
            }

            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $entry)) {
                    return $entry[$alias];
                }
            }
        }

        if (is_object($entry)) {
            if (isset($entry->{$name})) {
                return $entry->{$name};
            }

            foreach ($aliases as $alias) {
                if (isset($entry->{$alias})) {
                    return $entry->{$alias};
                }
            }
        }

        return $default;
    }
}
