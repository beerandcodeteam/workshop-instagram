<?php

namespace App\Services\Recommendation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrendingPoolService
{
    public function redisKey(): string
    {
        return (string) config('recommendation.trending.redis_key', 'rec:trending:global');
    }

    /**
     * Recompute the global trending pool from recent interactions and
     * persist the top-N post ids in Redis as a sorted set.
     *
     * @return array<int, float> post_id => score
     */
    public function refresh(): array
    {
        $windowHours = (int) config('recommendation.trending.window_hours', 24);
        $halfLifeHours = (float) config('recommendation.trending.half_life_hours', 24);
        $poolSize = (int) config('recommendation.trending.pool_size', 200);
        $reportsThreshold = (int) config('recommendation.trending.reports_threshold', 3);

        $cutoff = now()->subHours($windowHours);
        $ln2 = log(2);
        $nowTs = now()->getTimestamp();

        $rows = DB::table('post_interactions as pi')
            ->join('posts as p', 'p.id', '=', 'pi.post_id')
            ->where('pi.created_at', '>=', $cutoff)
            ->whereNull('p.deleted_at')
            ->where('p.reports_count', '<', $reportsThreshold)
            ->select(
                'pi.post_id as post_id',
                'pi.weight as weight',
                'pi.created_at as created_at',
                'pi.interaction_type_id as interaction_type_id',
            )
            ->get();

        $numerators = [];
        $impressions = [];

        $viewTypeId = DB::table('interaction_types')->where('slug', 'view')->value('id');

        foreach ($rows as $row) {
            $createdAt = strtotime($row->created_at);
            $ageSeconds = max(0, $nowTs - $createdAt);
            $ageHours = $ageSeconds / 3600.0;
            $decay = exp(-$ln2 * $ageHours / $halfLifeHours);
            $postId = (int) $row->post_id;

            $numerators[$postId] = ($numerators[$postId] ?? 0.0) + ((float) $row->weight * $decay);

            if ($viewTypeId !== null && (int) $row->interaction_type_id === (int) $viewTypeId) {
                $impressions[$postId] = ($impressions[$postId] ?? 0) + 1;
            }
        }

        $scores = [];
        foreach ($numerators as $postId => $numerator) {
            $views = $impressions[$postId] ?? 0;
            $scores[$postId] = $numerator / ($views + 1);
        }

        arsort($scores);
        $top = array_slice($scores, 0, $poolSize, true);

        $key = $this->redisKey();
        Redis::del($key);

        if ($top !== []) {
            $args = [];
            foreach ($top as $postId => $score) {
                $args[] = $score;
                $args[] = $postId;
            }
            Redis::zadd($key, ...$args);
        }

        return $top;
    }

    /**
     * @return array<int, int> ordered list of post ids (most trending first)
     */
    public function topIds(int $limit): array
    {
        $key = $this->redisKey();
        $raw = Redis::zrevrange($key, 0, max(0, $limit - 1));

        return array_map(static fn ($id) => (int) $id, $raw);
    }

    /**
     * @return array<int, float> post_id => score
     */
    public function topWithScores(int $limit): array
    {
        $key = $this->redisKey();
        $raw = Redis::zrevrange($key, 0, max(0, $limit - 1), ['WITHSCORES' => true]);

        if ($raw === []) {
            return [];
        }

        $result = [];
        foreach ($raw as $id => $score) {
            $result[(int) $id] = (float) $score;
        }

        return $result;
    }
}
