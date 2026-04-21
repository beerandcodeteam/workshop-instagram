<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Recommendation\UserEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshLongTermEmbeddingsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('longterm');
    }

    public function handle(UserEmbeddingService $service): void
    {
        $activityWindow = (int) config('recommendation.long_term.activity_window_days', 7);
        $cutoff = now()->subDays($activityWindow);

        $userIds = DB::table('post_interactions')
            ->where('created_at', '>=', $cutoff)
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if ($user === null) {
                continue;
            }

            $service->refreshLongTerm($user);
            $service->refreshAvoid($user);
        }
    }
}
