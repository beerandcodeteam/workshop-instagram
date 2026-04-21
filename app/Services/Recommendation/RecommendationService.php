<?php

namespace App\Services\Recommendation;

use App\Contracts\RankingTraceLogger;
use App\Jobs\PersistRankingTracesJob;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecommendationService
{
    public function __construct(
        protected CandidateGenerator $candidateGenerator,
        protected Ranker $ranker,
        protected MmrReranker $mmrReranker,
        protected AuthorQuota $authorQuota,
        protected SeenFilter $seenFilter,
        protected ColdStartFeedBuilder $coldStart,
        protected ExplorationSlot $explorationSlot,
        protected ClusterCoverageEnforcer $clusterCoverage,
        protected RankingTraceLogger $trace,
        protected KillSwitchService $killSwitch,
        protected ExperimentService $experiments,
    ) {}

    /**
     * Build a paginated feed of recommended posts for a user.
     *
     * @return Collection<int, Post>
     */
    public function feedFor(User $user, int $page, int $pageSize): Collection
    {
        $requestId = (string) Str::uuid();
        $limit = $page * $pageSize;

        $this->trace->trace('feed.start', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'phase' => 'start',
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        if ($this->killSwitch->isDisabled()) {
            return $this->fallbackChronologicalFeed($user, $limit, $requestId);
        }

        if ($this->experiments->variantFor($user, ExperimentService::RANDOM_SERVING) === ExperimentService::VARIANT_CONTROL) {
            return $this->controlGroupFeed($user, $limit, $requestId);
        }

        $rankingVariant = $this->experiments->variantFor($user, ExperimentService::RANKING_FORMULA_V2);

        if ($this->coldStart->isColdStart($user)) {
            return $this->coldStartFeed($user, $limit, $requestId, $rankingVariant);
        }

        $batch = $this->candidateGenerator->generateWithFiltered($user);
        $candidates = $batch['kept'];
        $filteredCandidates = $batch['filtered'];

        $this->trace->trace('feed.candidates', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'phase' => 'candidate_gen',
            'candidate_count' => count($candidates),
            'filtered_count' => count($filteredCandidates),
        ]);

        if ($candidates === []) {
            $this->queueTraces($this->buildFilteredRows($requestId, $user, $filteredCandidates, $rankingVariant));

            return new Collection;
        }

        $alpha = $this->promotedColdStartAlpha($user) ?? $this->ranker->resolveAlpha($user);

        $ranked = $this->ranker->rank($candidates, $user, alphaOverride: $alpha, variant: $rankingVariant);

        foreach ($ranked as $index => $item) {
            $this->trace->trace('feed.ranked', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'post_id' => $item->candidate->postId,
                'phase' => 'ranking',
                'source' => $item->candidate->source,
                'scores' => $item->scoresBreakdown,
                'rank_position' => $index,
            ]);
        }

        $afterMmr = $this->mmrReranker->applyMmr($ranked);
        $mmrDropped = $this->diff($ranked, $afterMmr);

        $afterQuota = $this->authorQuota->applyAuthorQuota($afterMmr);
        $quotaDropped = $this->diff($afterMmr, $afterQuota);

        $afterExploration = $this->explorationSlot->enforce($afterQuota);

        $afterCoverage = $this->clusterCoverage->enforce($user, $afterExploration);

        $final = array_slice($afterCoverage, 0, $limit);
        $finalIds = array_map(static fn (RankedCandidate $c) => $c->candidate->postId, $final);

        $rankedDroppedAfterLimit = array_slice($afterCoverage, $limit);

        $rows = $this->buildRankedRows($requestId, $user, $final, $rankingVariant);
        $rows = array_merge(
            $rows,
            $this->buildFilteredRankedRows($requestId, $user, $mmrDropped, 'mmr_dropped', $rankingVariant),
            $this->buildFilteredRankedRows($requestId, $user, $quotaDropped, 'quota_exceeded', $rankingVariant),
            $this->buildFilteredRankedRows($requestId, $user, $rankedDroppedAfterLimit, 'pagination_cut', $rankingVariant),
            $this->buildFilteredRows($requestId, $user, $filteredCandidates, $rankingVariant),
        );

        $this->queueTraces($rows);

        if ($finalIds !== []) {
            $this->seenFilter->markSeen($user, $finalIds);
        }

        return $this->hydratePosts($finalIds);
    }

    /**
     * Quando o kill-switch está ligado, retornamos posts mais recentes em ordem
     * cronológica direta — sem ANN, ranking, MMR, quota ou trace pesado.
     *
     * @return Collection<int, Post>
     */
    private function fallbackChronologicalFeed(User $user, int $limit, string $requestId): Collection
    {
        $this->trace->trace('feed.kill_switch', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'phase' => 'kill_switch',
            'limit' => $limit,
        ]);

        return Post::query()
            ->with(['author', 'type', 'media', 'likes:id,post_id,user_id'])
            ->withCount(['likes', 'comments'])
            ->where('user_id', '!=', $user->id)
            ->latest('posts.created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Post>
     */
    private function coldStartFeed(User $user, int $limit, string $requestId, string $variant = ExperimentService::VARIANT_A): Collection
    {
        $ids = $this->coldStart->build($user, $limit);

        $this->trace->trace('feed.cold_start', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'phase' => 'cold_start',
            'count' => count($ids),
        ]);

        if ($ids === []) {
            return new Collection;
        }

        $this->queueTraces($this->buildColdStartRows($requestId, $user, $ids, $variant));
        $this->seenFilter->markSeen($user, $ids);

        return $this->hydratePosts($ids);
    }

    /**
     * US-024: 1% dos usuários (rotação diária) recebem feed cronológico puro
     * como grupo de controle para medir uplift do pipeline de recomendação.
     *
     * @return Collection<int, Post>
     */
    private function controlGroupFeed(User $user, int $limit, string $requestId): Collection
    {
        $this->trace->trace('feed.control_group', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'phase' => 'control_group',
            'limit' => $limit,
        ]);

        $posts = Post::query()
            ->with(['author', 'type', 'media', 'likes:id,post_id,user_id'])
            ->withCount(['likes', 'comments'])
            ->where('user_id', '!=', $user->id)
            ->latest('posts.created_at')
            ->limit($limit)
            ->get();

        $ids = $posts->pluck('id')->all();

        if ($ids !== []) {
            $this->queueTraces($this->buildControlGroupRows($requestId, $user, $ids));
            $this->seenFilter->markSeen($user, $ids);
        }

        return $posts;
    }

    /**
     * Users promoted from cold start (no LT but ≥5 positive interactions) score with α=0.3.
     */
    private function promotedColdStartAlpha(User $user): ?float
    {
        if (! empty($user->long_term_embedding)) {
            return null;
        }

        $threshold = (int) config('recommendation.cold_start.interactions_threshold', 5);

        $positiveCount = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->where('pi.user_id', $user->id)
            ->where('it.is_positive', true)
            ->count();

        if ($positiveCount < $threshold) {
            return null;
        }

        return (float) config('recommendation.score.alpha_active_session', 0.3);
    }

    /**
     * @param  list<RankedCandidate>  $ranked
     * @return list<array<string, mixed>>
     */
    private function buildRankedRows(string $requestId, User $user, array $ranked, string $variant = ExperimentService::VARIANT_A): array
    {
        $rows = [];
        $now = now();

        foreach ($ranked as $index => $item) {
            $rows[] = [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'post_id' => $item->candidate->postId,
                'source_slug' => $item->candidate->source,
                'score' => $item->score,
                'rank_position' => $index,
                'scores_breakdown' => $item->scoresBreakdown,
                'filtered_reason' => null,
                'experiment_variant' => $variant,
                'created_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<RankedCandidate>  $ranked
     * @return list<array<string, mixed>>
     */
    private function buildFilteredRankedRows(string $requestId, User $user, array $ranked, string $reason, string $variant = ExperimentService::VARIANT_A): array
    {
        $rows = [];
        $now = now();

        foreach ($ranked as $item) {
            $rows[] = [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'post_id' => $item->candidate->postId,
                'source_slug' => $item->candidate->source,
                'score' => $item->score,
                'rank_position' => -1,
                'scores_breakdown' => $item->scoresBreakdown,
                'filtered_reason' => $reason,
                'experiment_variant' => $variant,
                'created_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{candidate: Candidate, reason: string}>  $filtered
     * @return list<array<string, mixed>>
     */
    private function buildFilteredRows(string $requestId, User $user, array $filtered, string $variant = ExperimentService::VARIANT_A): array
    {
        $rows = [];
        $now = now();

        foreach ($filtered as $entry) {
            $rows[] = [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'post_id' => $entry['candidate']->postId,
                'source_slug' => $entry['candidate']->source,
                'score' => $entry['candidate']->sourceScore,
                'rank_position' => -1,
                'scores_breakdown' => ['source_score' => $entry['candidate']->sourceScore],
                'filtered_reason' => $entry['reason'],
                'experiment_variant' => $variant,
                'created_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $postIds
     * @return list<array<string, mixed>>
     */
    private function buildColdStartRows(string $requestId, User $user, array $postIds, string $variant = ExperimentService::VARIANT_A): array
    {
        $rows = [];
        $now = now();

        foreach ($postIds as $index => $postId) {
            $rows[] = [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'post_id' => $postId,
                'source_slug' => 'trending',
                'score' => 0,
                'rank_position' => $index,
                'scores_breakdown' => ['cold_start' => true],
                'filtered_reason' => null,
                'experiment_variant' => $variant,
                'created_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $postIds
     * @return list<array<string, mixed>>
     */
    private function buildControlGroupRows(string $requestId, User $user, array $postIds): array
    {
        $rows = [];
        $now = now();

        foreach ($postIds as $index => $postId) {
            $rows[] = [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'post_id' => $postId,
                'source_slug' => null,
                'score' => 0,
                'rank_position' => $index,
                'scores_breakdown' => ['control_group' => true],
                'filtered_reason' => null,
                'experiment_variant' => ExperimentService::VARIANT_CONTROL,
                'created_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function queueTraces(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        PersistRankingTracesJob::dispatch($rows);
    }

    /**
     * Returns items present in $before but not in $after (matched by post_id).
     *
     * @param  list<RankedCandidate>  $before
     * @param  list<RankedCandidate>  $after
     * @return list<RankedCandidate>
     */
    private function diff(array $before, array $after): array
    {
        $afterIds = [];
        foreach ($after as $item) {
            $afterIds[$item->candidate->postId] = true;
        }

        $dropped = [];
        foreach ($before as $item) {
            if (! isset($afterIds[$item->candidate->postId])) {
                $dropped[] = $item;
            }
        }

        return $dropped;
    }

    /**
     * @param  list<int>  $postIds
     * @return Collection<int, Post>
     */
    private function hydratePosts(array $postIds): Collection
    {
        if ($postIds === []) {
            return new Collection;
        }

        $ordering = array_flip($postIds);

        $posts = Post::query()
            ->with(['author', 'type', 'media', 'likes:id,post_id,user_id'])
            ->withCount(['likes', 'comments'])
            ->whereIn('id', $postIds)
            ->get();

        return $posts
            ->sortBy(fn (Post $post) => $ordering[$post->id] ?? PHP_INT_MAX)
            ->values();
    }
}
