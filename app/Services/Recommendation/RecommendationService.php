<?php

namespace App\Services\Recommendation;

use App\Contracts\RankingTraceLogger;
use App\Models\Post;
use App\Models\RecommendationSource;
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
        protected RankingTraceLogger $trace,
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

        if ($this->coldStart->isColdStart($user)) {
            return $this->coldStartFeed($user, $limit, $requestId);
        }

        $candidates = $this->candidateGenerator->generate($user);

        $this->trace->trace('feed.candidates', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'phase' => 'candidate_gen',
            'candidate_count' => count($candidates),
        ]);

        if ($candidates === []) {
            return new Collection;
        }

        $alpha = $this->promotedColdStartAlpha($user) ?? $this->ranker->resolveAlpha($user);

        $ranked = $this->ranker->rank($candidates, $user, alphaOverride: $alpha);

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

        $ranked = $this->mmrReranker->applyMmr($ranked);
        $ranked = $this->authorQuota->applyAuthorQuota($ranked);
        $ranked = $this->explorationSlot->enforce($ranked);

        $final = array_slice($ranked, 0, $limit);
        $finalIds = array_map(static fn (RankedCandidate $c) => $c->candidate->postId, $final);

        if ($finalIds === []) {
            return new Collection;
        }

        $this->persistLogs($requestId, $user, $final);

        $this->seenFilter->markSeen($user, $finalIds);

        return $this->hydratePosts($finalIds);
    }

    /**
     * @return Collection<int, Post>
     */
    private function coldStartFeed(User $user, int $limit, string $requestId): Collection
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

        $this->persistColdStartLogs($requestId, $user, $ids);
        $this->seenFilter->markSeen($user, $ids);

        return $this->hydratePosts($ids);
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
     */
    private function persistLogs(string $requestId, User $user, array $ranked): void
    {
        if ($ranked === []) {
            return;
        }

        $sources = RecommendationSource::pluck('id', 'slug')->all();
        $now = now();

        $rows = [];
        foreach ($ranked as $index => $item) {
            $sourceId = $sources[$item->candidate->source] ?? null;

            $rows[] = [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'post_id' => $item->candidate->postId,
                'recommendation_source_id' => $sourceId,
                'score' => $item->score,
                'rank_position' => $index,
                'scores_breakdown' => json_encode($item->scoresBreakdown),
                'created_at' => $now,
            ];
        }

        DB::table('recommendation_logs')->insert($rows);
    }

    /**
     * @param  list<int>  $postIds
     */
    private function persistColdStartLogs(string $requestId, User $user, array $postIds): void
    {
        $sourceId = RecommendationSource::where('slug', 'trending')->value('id');

        $now = now();
        $rows = [];
        foreach ($postIds as $index => $postId) {
            $rows[] = [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'post_id' => $postId,
                'recommendation_source_id' => $sourceId,
                'score' => 0,
                'rank_position' => $index,
                'scores_breakdown' => json_encode(['cold_start' => true]),
                'created_at' => $now,
            ];
        }

        DB::table('recommendation_logs')->insert($rows);
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
