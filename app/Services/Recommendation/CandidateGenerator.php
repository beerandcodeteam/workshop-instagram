<?php

namespace App\Services\Recommendation;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CandidateGenerator
{
    public function __construct(
        protected TrendingPoolService $trendingPool,
        protected SeenFilter $seenFilter,
    ) {}

    /**
     * @return array<int, Candidate>
     */
    public function annByLongTerm(User $user, int $limit = 300): array
    {
        $vector = $user->long_term_embedding;

        if (! is_array($vector) || $vector === []) {
            return [];
        }

        return $this->runAnn($vector, $limit, 'ann_long_term');
    }

    /**
     * @return array<int, Candidate>
     */
    public function annByShortTerm(User $user, int $limit = 200): array
    {
        $vector = $user->short_term_embedding;

        if (! is_array($vector) || $vector === []) {
            return [];
        }

        return $this->runAnn($vector, $limit, 'ann_short_term');
    }

    /**
     * @return array<int, Candidate>
     */
    public function trending(User $user, int $limit = 100): array
    {
        $scores = $this->trendingPool->topWithScores($limit);

        $candidates = [];
        foreach ($scores as $postId => $score) {
            $candidates[] = Candidate::make((int) $postId, 'trending', (float) $score);
        }

        return $candidates;
    }

    /**
     * Pulls candidates near each interest cluster of the user.
     *
     * For every cluster we run an HNSW lookup on `posts.embedding <=>
     * cluster.embedding`, capped at `perClusterLimit`. The global pool is
     * limited to `totalLimit`, with slots distributed proportionally to each
     * cluster weight (so the dominant interest gets more room).
     *
     * @return array<int, Candidate>
     */
    public function annByClusters(User $user, ?int $totalLimit = null, ?int $perClusterLimit = null): array
    {
        $totalLimit ??= (int) config('recommendation.clusters.global_limit', 300);
        $perClusterLimit ??= (int) config('recommendation.clusters.per_cluster_limit', 100);

        if ($totalLimit <= 0 || $perClusterLimit <= 0) {
            return [];
        }

        $clusters = DB::table('user_interest_clusters')
            ->where('user_id', $user->id)
            ->orderBy('cluster_index')
            ->get(['cluster_index', 'weight', DB::raw('embedding::text as embedding_text')]);

        if ($clusters->isEmpty()) {
            return [];
        }

        $totalWeight = (float) $clusters->sum('weight');
        if ($totalWeight <= 0.0) {
            return [];
        }

        $perCluster = [];
        $byPost = [];

        foreach ($clusters as $cluster) {
            $vector = $this->parseVector((string) $cluster->embedding_text);
            if ($vector === []) {
                continue;
            }

            $rows = $this->annRows($vector, $perClusterLimit);

            $perCluster[(int) $cluster->cluster_index] = [
                'weight' => (float) $cluster->weight,
                'rows' => $rows,
            ];
        }

        if ($perCluster === []) {
            return [];
        }

        $allocations = $this->allocateSlots($perCluster, $totalLimit, $perClusterLimit, $totalWeight);

        foreach ($perCluster as $clusterIndex => $entry) {
            $slots = $allocations[$clusterIndex] ?? 0;
            if ($slots <= 0) {
                continue;
            }

            $taken = 0;
            foreach ($entry['rows'] as $row) {
                if ($taken >= $slots) {
                    break;
                }

                $postId = (int) $row->id;
                $similarity = 1.0 - (float) $row->distance;

                if (! isset($byPost[$postId]) || $byPost[$postId]->sourceScore < $similarity) {
                    $byPost[$postId] = Candidate::make($postId, 'ann_cluster', $similarity, [
                        'cluster_index' => $clusterIndex,
                    ]);
                }

                $taken++;
            }
        }

        return $byPost;
    }

    /**
     * @return array<int, Candidate>
     */
    public function exploration(User $user, int $limit = 50): array
    {
        $seenAuthorIds = DB::table('post_interactions as pi')
            ->join('posts as p', 'p.id', '=', 'pi.post_id')
            ->where('pi.user_id', $user->id)
            ->distinct()
            ->pluck('p.user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $seenAuthorIds[] = $user->id;

        $rows = DB::table('posts')
            ->select('id', 'user_id', 'created_at')
            ->whereNotNull('embedding')
            ->whereNull('deleted_at')
            ->whereNotIn('user_id', $seenAuthorIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $nowTs = now()->getTimestamp();
        $candidates = [];
        foreach ($rows as $row) {
            $ageSeconds = max(0, $nowTs - strtotime($row->created_at));
            $ageHours = $ageSeconds / 3600.0;
            $sourceScore = exp(-log(2) * $ageHours / 24.0);

            $candidates[] = Candidate::make((int) $row->id, 'explore', $sourceScore);
        }

        return $candidates;
    }

    /**
     * Run all candidate sources, dedup by post_id, apply hard filters.
     *
     * @return array<int, Candidate> indexed by post_id
     */
    public function generate(User $user): array
    {
        return $this->generateWithFiltered($user)['kept'];
    }

    /**
     * Same as generate(), but also returns candidates that were hard-filtered
     * along with the reason they were excluded.
     *
     * @return array{
     *   kept: array<int, Candidate>,
     *   filtered: list<array{candidate: Candidate, reason: string}>,
     * }
     */
    public function generateWithFiltered(User $user): array
    {
        $longLimit = (int) config('recommendation.candidates.ann_long_term_limit', 300);
        $shortLimit = (int) config('recommendation.candidates.ann_short_term_limit', 200);
        $trendingLimit = (int) config('recommendation.candidates.trending_limit', 100);
        $explorationLimit = (int) config('recommendation.candidates.exploration_limit', 50);
        $clustersLimit = (int) config('recommendation.clusters.global_limit', 300);
        $perClusterLimit = (int) config('recommendation.clusters.per_cluster_limit', 100);

        $sources = [
            $this->annByLongTerm($user, $longLimit),
            $this->annByShortTerm($user, $shortLimit),
            $this->annByClusters($user, $clustersLimit, $perClusterLimit),
            $this->trending($user, $trendingLimit),
            $this->exploration($user, $explorationLimit),
        ];

        $byPost = [];
        foreach ($sources as $list) {
            foreach ($list as $candidate) {
                if (! isset($byPost[$candidate->postId])) {
                    $byPost[$candidate->postId] = $candidate;

                    continue;
                }

                if ($candidate->sourceScore > $byPost[$candidate->postId]->sourceScore) {
                    $byPost[$candidate->postId] = $candidate;
                }
            }
        }

        if ($byPost === []) {
            return ['kept' => [], 'filtered' => []];
        }

        return $this->applyHardFilters($user, $byPost);
    }

    /**
     * @param  array<int, Candidate>  $byPost
     * @return array{
     *   kept: array<int, Candidate>,
     *   filtered: list<array{candidate: Candidate, reason: string}>,
     * }
     */
    private function applyHardFilters(User $user, array $byPost): array
    {
        $reportsThreshold = (int) config('recommendation.candidates.reports_threshold', 3);

        $postIds = array_keys($byPost);

        $metadata = DB::table('posts')
            ->whereIn('id', $postIds)
            ->get(['id', 'user_id', 'embedding', 'deleted_at', 'reports_count'])
            ->keyBy('id');

        $seenPostIds = $this->seenFilter->seenFor($user);

        $kept = [];
        $filtered = [];

        foreach ($byPost as $postId => $candidate) {
            $post = $metadata->get($postId);

            if ($post === null || $post->embedding === null || $post->deleted_at !== null) {
                $filtered[] = ['candidate' => $candidate, 'reason' => 'missing_embedding'];

                continue;
            }

            if ((int) $post->user_id === (int) $user->id) {
                $filtered[] = ['candidate' => $candidate, 'reason' => 'self_author'];

                continue;
            }

            if ((int) $post->reports_count >= $reportsThreshold) {
                $filtered[] = ['candidate' => $candidate, 'reason' => 'reports_threshold'];

                continue;
            }

            if (isset($seenPostIds[$postId])) {
                $filtered[] = ['candidate' => $candidate, 'reason' => 'already_seen'];

                continue;
            }

            $kept[$postId] = $candidate;
        }

        return ['kept' => $kept, 'filtered' => $filtered];
    }

    /**
     * @param  list<float>  $vector
     * @return array<int, Candidate>
     */
    private function runAnn(array $vector, int $limit, string $source): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = $this->annRows($vector, $limit);

        $candidates = [];
        foreach ($rows as $row) {
            $distance = (float) $row->distance;
            $similarity = 1.0 - $distance;

            $candidates[] = Candidate::make((int) $row->id, $source, $similarity);
        }

        return $candidates;
    }

    /**
     * @param  list<float>  $vector
     * @return Collection<int, object>
     */
    private function annRows(array $vector, int $limit): Collection
    {
        $literal = '['.implode(',', $vector).']';

        return DB::table('posts')
            ->selectRaw('id, (embedding <=> ?::vector) as distance', [$literal])
            ->whereNotNull('embedding')
            ->whereNull('deleted_at')
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($limit)
            ->get();
    }

    /**
     * Largest-remainder method: distribute $totalLimit slots across clusters
     * proportionally to weight, capped at per-cluster row availability.
     *
     * @param  array<int, array{weight: float, rows: Collection<int, object>}>  $perCluster
     * @return array<int, int>
     */
    private function allocateSlots(array $perCluster, int $totalLimit, int $perClusterLimit, float $totalWeight): array
    {
        $rawShares = [];
        $allocations = [];
        $remainders = [];

        $availableTotal = 0;
        foreach ($perCluster as $clusterIndex => $entry) {
            $available = min($perClusterLimit, $entry['rows']->count());
            $availableTotal += $available;
        }

        $cap = min($totalLimit, $availableTotal);

        foreach ($perCluster as $clusterIndex => $entry) {
            $share = $cap * ($entry['weight'] / $totalWeight);
            $rawShares[$clusterIndex] = $share;

            $available = min($perClusterLimit, $entry['rows']->count());
            $floor = (int) min($available, floor($share));

            $allocations[$clusterIndex] = $floor;
            $remainders[$clusterIndex] = $share - $floor;
        }

        $assigned = array_sum($allocations);
        $remaining = $cap - $assigned;

        if ($remaining <= 0) {
            return $allocations;
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $clusterIndex) {
            if ($remaining <= 0) {
                break;
            }

            $available = min($perClusterLimit, $perCluster[$clusterIndex]['rows']->count());
            if ($allocations[$clusterIndex] >= $available) {
                continue;
            }

            $allocations[$clusterIndex]++;
            $remaining--;
        }

        return $allocations;
    }

    /**
     * @return list<float>
     */
    private function parseVector(string $text): array
    {
        $trimmed = trim($text, "[] \t\n\r");

        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }
}
