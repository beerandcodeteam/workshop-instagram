<?php

namespace App\Services\Recommendation;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;

class RecommendationService
{
    public function __construct(
        protected CandidateGenerator $candidateGenerator,
        protected Ranker $ranker,
    ) {}

    /**
     * Build a paginated feed of recommended posts for a user.
     *
     * @return Collection<int, Post>
     */
    public function feedFor(User $user, int $page, int $pageSize): Collection
    {
        // TODO Phase 5: implementar pipeline de retrieval em dois estágios
        // (CandidateGenerator -> Ranker -> MMR -> quotas -> RankingTrace).
        return new Collection;
    }
}
