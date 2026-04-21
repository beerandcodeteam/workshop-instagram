<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Recommendation\UserEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshAvoidEmbeddingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $userId)
    {
        $this->onQueue('clusters');
    }

    public function handle(UserEmbeddingService $service): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $service->refreshAvoid($user);
    }
}
