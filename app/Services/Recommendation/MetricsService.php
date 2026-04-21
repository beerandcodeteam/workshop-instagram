<?php

namespace App\Services\Recommendation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    /**
     * @return array{ctr_1h: float, ctr_24h: float, ctr_7d: float}
     */
    public function ctrBuckets(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'ctr_1h' => $this->ctrSince($now->subHour(), $now),
            'ctr_24h' => $this->ctrSince($now->subDay(), $now),
            'ctr_7d' => $this->ctrSince($now->subDays(7), $now),
        ];
    }

    public function ctrSince(CarbonImmutable $since, ?CarbonImmutable $until = null): float
    {
        $until ??= CarbonImmutable::now();

        $impressions = DB::table('recommendation_logs')
            ->whereNull('filtered_reason')
            ->where('rank_position', '>=', 0)
            ->whereBetween('created_at', [$since, $until])
            ->count();

        if ($impressions === 0) {
            return 0.0;
        }

        $positiveSlugs = ['like', 'comment', 'share', 'view'];

        $positives = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->whereIn('it.slug', $positiveSlugs)
            ->whereBetween('pi.created_at', [$since, $until])
            ->count();

        return round(min(1.0, $positives / max(1, $impressions)), 4);
    }

    public function dwellMedianMs(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): float
    {
        $since ??= CarbonImmutable::now()->subDay();
        $until ??= CarbonImmutable::now();

        $values = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->where('it.slug', 'view')
            ->whereNotNull('pi.duration_ms')
            ->whereBetween('pi.created_at', [$since, $until])
            ->orderBy('pi.duration_ms')
            ->pluck('pi.duration_ms')
            ->all();

        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $mid = (int) floor($count / 2);

        if ($count % 2 === 1) {
            return (float) $values[$mid];
        }

        return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }

    /**
     * Coeficiente de Gini sobre a distribuição de impressões por autor.
     * 0 = totalmente uniforme, 1 = um único autor domina.
     */
    public function authorGini(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): float
    {
        $since ??= CarbonImmutable::now()->subDay();
        $until ??= CarbonImmutable::now();

        $rows = DB::table('recommendation_logs as rl')
            ->join('posts as p', 'p.id', '=', 'rl.post_id')
            ->whereNull('rl.filtered_reason')
            ->where('rl.rank_position', '>=', 0)
            ->whereBetween('rl.created_at', [$since, $until])
            ->select('p.user_id', DB::raw('count(*) as impressions'))
            ->groupBy('p.user_id')
            ->pluck('impressions')
            ->map(fn ($value) => (int) $value)
            ->sort()
            ->values()
            ->all();

        $n = count($rows);

        if ($n === 0) {
            return 0.0;
        }

        $sum = array_sum($rows);
        if ($sum === 0) {
            return 0.0;
        }

        $cumulative = 0.0;
        foreach ($rows as $i => $value) {
            $cumulative += ($i + 1) * $value;
        }

        $gini = (2 * $cumulative) / ($n * $sum) - ($n + 1) / $n;

        return round(max(0.0, min(1.0, $gini)), 4);
    }

    /**
     * Cobertura média de clusters: fração de clusters do usuário representados
     * no top-20 do feed (apenas usuários com clusters).
     */
    public function clusterCoverage(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): float
    {
        $since ??= CarbonImmutable::now()->subDay();
        $until ??= CarbonImmutable::now();

        $userClusters = DB::table('user_interest_clusters')
            ->select('user_id', DB::raw('count(*) as cluster_count'))
            ->groupBy('user_id')
            ->pluck('cluster_count', 'user_id');

        if ($userClusters->isEmpty()) {
            return 0.0;
        }

        $coverages = [];

        foreach ($userClusters as $userId => $clusterCount) {
            $sourcesShown = DB::table('recommendation_logs')
                ->where('user_id', $userId)
                ->whereNull('filtered_reason')
                ->where('rank_position', '>=', 0)
                ->where('rank_position', '<', 20)
                ->whereBetween('created_at', [$since, $until])
                ->whereNotNull('recommendation_source_id')
                ->distinct('recommendation_source_id')
                ->count('recommendation_source_id');

            $coverages[] = min(1.0, $sourcesShown / max(1, $clusterCount));
        }

        if ($coverages === []) {
            return 0.0;
        }

        return round(array_sum($coverages) / count($coverages), 4);
    }

    /**
     * @return array{hide: float, report: float}
     */
    public function negativeRates(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): array
    {
        $since ??= CarbonImmutable::now()->subDay();
        $until ??= CarbonImmutable::now();

        $impressions = DB::table('recommendation_logs')
            ->whereNull('filtered_reason')
            ->where('rank_position', '>=', 0)
            ->whereBetween('created_at', [$since, $until])
            ->count();

        if ($impressions === 0) {
            return ['hide' => 0.0, 'report' => 0.0];
        }

        $hides = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->where('it.slug', 'hide')
            ->whereBetween('pi.created_at', [$since, $until])
            ->count();

        $reports = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->where('it.slug', 'report')
            ->whereBetween('pi.created_at', [$since, $until])
            ->count();

        return [
            'hide' => round($hides / $impressions, 4),
            'report' => round($reports / $impressions, 4),
        ];
    }

    /**
     * Latência por percentil — lê do channel `recommendation` se houver,
     * caso contrário usa breakdown de scores se incluir `latency_ms`.
     *
     * @return array{p50: float, p95: float}
     */
    public function feedLatencyPercentiles(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): array
    {
        $since ??= CarbonImmutable::now()->subDay();
        $until ??= CarbonImmutable::now();

        $values = DB::table('recommendation_logs')
            ->whereBetween('created_at', [$since, $until])
            ->whereNotNull('scores_breakdown')
            ->whereRaw("scores_breakdown->>'latency_ms' IS NOT NULL")
            ->selectRaw("(scores_breakdown->>'latency_ms')::float as latency")
            ->orderBy('latency')
            ->pluck('latency')
            ->map(fn ($value) => (float) $value)
            ->all();

        return [
            'p50' => $this->percentile($values, 0.5),
            'p95' => $this->percentile($values, 0.95),
        ];
    }

    public function jobErrorRate(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): float
    {
        $since ??= CarbonImmutable::now()->subDay();
        $until ??= CarbonImmutable::now();

        $failed = DB::table('failed_jobs')
            ->whereBetween('failed_at', [$since, $until])
            ->count();

        $total = DB::table('jobs')->count() + $failed;

        if ($total === 0) {
            return 0.0;
        }

        return round($failed / $total, 4);
    }

    public function catalogCoverage(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): float
    {
        $since ??= CarbonImmutable::now()->subDays(7);
        $until ??= CarbonImmutable::now();

        $totalPosts = DB::table('posts')->whereNotNull('embedding')->count();

        if ($totalPosts === 0) {
            return 0.0;
        }

        $impressedPosts = DB::table('recommendation_logs')
            ->whereNull('filtered_reason')
            ->where('rank_position', '>=', 0)
            ->whereBetween('created_at', [$since, $until])
            ->distinct('post_id')
            ->count('post_id');

        return round(min(1.0, $impressedPosts / $totalPosts), 4);
    }

    /**
     * Métricas comparando variantes de experimento (ex.: treatment vs control).
     *
     * @return array<string, array{impressions: int, ctr: float}>
     */
    public function variantComparison(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): array
    {
        $since ??= CarbonImmutable::now()->subDay();
        $until ??= CarbonImmutable::now();

        $variants = DB::table('recommendation_logs')
            ->whereBetween('created_at', [$since, $until])
            ->whereNull('filtered_reason')
            ->where('rank_position', '>=', 0)
            ->whereNotNull('experiment_variant')
            ->select('experiment_variant', DB::raw('count(*) as impressions'), DB::raw('count(distinct user_id) as users'))
            ->groupBy('experiment_variant')
            ->get();

        $result = [];

        foreach ($variants as $row) {
            $impressions = (int) $row->impressions;
            $users = (int) $row->users;

            $positives = DB::table('post_interactions as pi')
                ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
                ->whereIn('it.slug', ['like', 'comment', 'share'])
                ->whereBetween('pi.created_at', [$since, $until])
                ->whereExists(function ($q) use ($row) {
                    $q->select(DB::raw(1))
                        ->from('recommendation_logs as rl')
                        ->whereColumn('rl.user_id', 'pi.user_id')
                        ->whereColumn('rl.post_id', 'pi.post_id')
                        ->where('rl.experiment_variant', $row->experiment_variant);
                })
                ->count();

            $result[(string) $row->experiment_variant] = [
                'impressions' => $impressions,
                'users' => $users,
                'ctr' => $impressions > 0 ? round($positives / $impressions, 4) : 0.0,
            ];
        }

        return $result;
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);

        if ($n === 0) {
            return 0.0;
        }

        $index = (int) floor($p * ($n - 1));

        return (float) $sorted[$index];
    }
}
