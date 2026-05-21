<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

use Hikuroshi\RateBudget\Support\Num;
use Throwable;

final class Retry
{
    public static function sleep(int|float $ms): void
    {
        $safeMs = Num::nonNegative($ms);

        if ($safeMs <= 0) {
            return;
        }

        usleep($safeMs * 1000);
    }

    public static function status(mixed $error): ?int
    {
        if (is_array($error)) {
            return self::statusValue($error['status'] ?? $error['statusCode'] ?? null);
        }

        if (is_object($error)) {
            foreach (['getStatusCode', 'getStatus'] as $method) {
                if (is_callable([$error, $method])) {
                    $status = self::statusValue($error->{$method}());

                    if ($status !== null) {
                        return $status;
                    }
                }
            }

            foreach (['status', 'statusCode'] as $property) {
                if (isset($error->{$property})) {
                    $status = self::statusValue($error->{$property});

                    if ($status !== null) {
                        return $status;
                    }
                }
            }
        }

        if ($error instanceof Throwable) {
            return self::statusValue($error->getCode());
        }

        return null;
    }

    public static function message(mixed $error): string
    {
        if ($error instanceof Throwable) {
            return $error->getMessage();
        }

        if (is_string($error)) {
            return $error;
        }

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        if (is_object($error) && isset($error->message) && is_string($error->message)) {
            return $error->message;
        }

        return '';
    }

    public static function retryable(mixed $error, array $options = []): bool
    {
        $codes = $options['codes'] ?? $options['statusCodes'] ?? [429, 500, 503];
        $status = self::status($error);

        if ($status !== null && in_array($status, $codes, true)) {
            return true;
        }

        $message = strtolower(self::message($error));

        foreach (($options['messages'] ?? $options['messageIncludes'] ?? []) as $text) {
            if (is_string($text) && $text !== '' && str_contains($message, strtolower($text))) {
                return true;
            }
        }

        return false;
    }

    public static function run(
        callable $task,
        int|float $retries,
        callable|int|float $delayMs = 0,
        ?callable $shouldRetry = null,
        ?callable $onRetry = null,
    ): mixed {
        $retryCount = Num::nonNegative($retries);
        $maxAttempts = $retryCount + 1;
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                return $task($attempt);
            } catch (Throwable $error) {
                $lastError = $error;

                if ($attempt >= $maxAttempts - 1) {
                    break;
                }

                $canRetry = $shouldRetry
                    ? (bool) $shouldRetry($error, $attempt)
                    : self::retryable($error);

                if (! $canRetry) {
                    break;
                }

                $nextDelayMs = is_callable($delayMs)
                    ? Num::nonNegative($delayMs($attempt, $error))
                    : Num::nonNegative($delayMs);

                if ($onRetry) {
                    $onRetry($attempt, $nextDelayMs, $error);
                }

                self::sleep($nextDelayMs);
            }
        }

        throw $lastError;
    }

    private static function statusValue(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (int) $value : null;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
