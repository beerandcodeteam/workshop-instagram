<?php

namespace App\Jobs;

use App\Models\RecommendationSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PersistRankingTracesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  list<array{
     *   request_id: string,
     *   user_id: int,
     *   post_id: int,
     *   source_slug?: string|null,
     *   score: float,
     *   rank_position: int,
     *   scores_breakdown?: array<string, mixed>|null,
     *   filtered_reason?: string|null,
     *   experiment_variant?: string|null,
     *   created_at?: string|null,
     * }>  $traces
     */
    public function __construct(public array $traces)
    {
        $this->onQueue('traces');
    }

    public function handle(): void
    {
        if ($this->traces === []) {
            return;
        }

        $sourceIdsBySlug = RecommendationSource::pluck('id', 'slug')->all();
        $now = now();

        $rows = array_map(function (array $trace) use ($sourceIdsBySlug, $now): array {
            $slug = $trace['source_slug'] ?? null;

            return [
                'request_id' => $trace['request_id'],
                'user_id' => $trace['user_id'],
                'post_id' => $trace['post_id'],
                'recommendation_source_id' => $slug !== null ? ($sourceIdsBySlug[$slug] ?? null) : null,
                'score' => $trace['score'],
                'rank_position' => $trace['rank_position'],
                'scores_breakdown' => isset($trace['scores_breakdown'])
                    ? json_encode($trace['scores_breakdown'])
                    : null,
                'filtered_reason' => $trace['filtered_reason'] ?? null,
                'experiment_variant' => $trace['experiment_variant'] ?? null,
                'created_at' => $trace['created_at'] ?? $now,
            ];
        }, $this->traces);

        DB::table('recommendation_logs')->insert($rows);
    }
}
