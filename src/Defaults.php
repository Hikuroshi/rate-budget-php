<?php

declare(strict_types=1);

namespace Hikuroshi\RateBudget;

final class Defaults
{
    public const WINDOW_MS = 60_000;

    public const BUFFER_MS = 1_000;

    public const THRESHOLD_PCT = 100;
}
