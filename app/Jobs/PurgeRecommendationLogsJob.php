<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PurgeRecommendationLogsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $retentionDays = 7)
    {
        $this->onQueue('traces');
    }

    public function handle(): int
    {
        $cutoff = now()->subDays($this->retentionDays);

        return DB::table('recommendation_logs')
            ->where('created_at', '<', $cutoff)
            ->delete();
    }
}
