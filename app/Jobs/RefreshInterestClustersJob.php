<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Recommendation\InterestClusterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshInterestClustersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public ?int $userId = null)
    {
        $this->onQueue('clusters');
    }

    public function handle(InterestClusterService $service): void
    {
        if ($this->userId !== null) {
            $user = User::find($this->userId);

            if ($user !== null) {
                $service->computeFor($user);
            }

            return;
        }

        $windowDays = (int) config('recommendation.clusters.window_days', 90);
        $cutoff = now()->subDays($windowDays);

        $userIds = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->where('it.is_positive', true)
            ->where('pi.created_at', '>=', $cutoff)
            ->distinct()
            ->pluck('pi.user_id');

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if ($user === null) {
                continue;
            }

            if (! $service->shouldRefresh($user)) {
                continue;
            }

            $service->computeFor($user);
        }
    }
}
