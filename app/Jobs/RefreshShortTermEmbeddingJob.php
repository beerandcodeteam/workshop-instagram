<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Recommendation\UserEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

class RefreshShortTermEmbeddingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $userId)
    {
        $this->onQueue('realtime');
    }

    public function handle(UserEmbeddingService $service): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $service->refreshShortTerm($user);
    }

    public static function dispatchDebounced(int $userId): bool
    {
        $ttl = (int) config('recommendation.short_term.debounce_seconds', 5);
        $key = self::lockKey($userId);

        $acquired = Redis::set($key, '1', 'EX', $ttl, 'NX');

        if ($acquired === false || $acquired === null) {
            return false;
        }

        self::dispatch($userId);

        return true;
    }

    public static function lockKey(int $userId): string
    {
        return "rec:user:{$userId}:st_lock";
    }
}
