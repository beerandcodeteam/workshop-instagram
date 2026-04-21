<?php

namespace App\Jobs;

use App\Models\InteractionType;
use App\Services\Recommendation\ViewSignalCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AggregateViewSignalsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('realtime');
    }

    public function handle(): void
    {
        $windowMinutes = (int) config('recommendation.view_signals.aggregation_window_minutes', 10);
        $threshold = (float) config('recommendation.view_signals.refresh_threshold_delta', 1.0);
        $cutoff = now()->subMinutes($windowMinutes);

        $viewTypeId = InteractionType::where('slug', ViewSignalCalculator::KIND_VIEW)->value('id');

        if ($viewTypeId === null) {
            return;
        }

        $eligibleUserIds = DB::table('post_interactions')
            ->select('user_id')
            ->where('interaction_type_id', $viewTypeId)
            ->where('created_at', '>=', $cutoff)
            ->groupBy('user_id')
            ->havingRaw('SUM(weight) >= ?', [$threshold])
            ->pluck('user_id');

        foreach ($eligibleUserIds as $userId) {
            RefreshShortTermEmbeddingJob::dispatchDebounced((int) $userId);
        }
    }
}
