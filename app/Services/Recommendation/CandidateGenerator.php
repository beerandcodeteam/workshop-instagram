<?php

namespace App\Services\Recommendation;

use App\Models\User;
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

        $sources = [
            $this->annByLongTerm($user, $longLimit),
            $this->annByShortTerm($user, $shortLimit),
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

        $literal = '['.implode(',', $vector).']';

        $rows = DB::table('posts')
            ->selectRaw('id, (embedding <=> ?::vector) as distance', [$literal])
            ->whereNotNull('embedding')
            ->whereNull('deleted_at')
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($limit)
            ->get();

        $candidates = [];
        foreach ($rows as $row) {
            $distance = (float) $row->distance;
            $similarity = 1.0 - $distance;

            $candidates[] = Candidate::make((int) $row->id, $source, $similarity);
        }

        return $candidates;
    }
}
