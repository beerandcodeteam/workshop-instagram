<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class GeminiCircuitBreaker
{
    public const FAILURES_KEY = 'rec:gemini:failures';

    public const CIRCUIT_KEY = 'rec:gemini:circuit_open';

    public const FAILURE_THRESHOLD = 10;

    public const FAILURE_WINDOW_SECONDS = 60;

    public const CIRCUIT_OPEN_SECONDS = 300;

    public function isOpen(): bool
    {
        return (bool) Redis::exists(self::CIRCUIT_KEY);
    }

    public function recordFailure(): void
    {
        $count = (int) Redis::incr(self::FAILURES_KEY);

        if ($count === 1) {
            Redis::expire(self::FAILURES_KEY, self::FAILURE_WINDOW_SECONDS);
        }

        if ($count >= self::FAILURE_THRESHOLD) {
            Redis::setex(self::CIRCUIT_KEY, self::CIRCUIT_OPEN_SECONDS, '1');
        }
    }

    public function recordSuccess(): void
    {
        Redis::del(self::FAILURES_KEY);
    }

    public function reset(): void
    {
        Redis::del(self::FAILURES_KEY);
        Redis::del(self::CIRCUIT_KEY);
    }
}
