<?php

namespace App\Services\Recommendation;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ColdStartFeedBuilder
{
    public function __construct(
        protected TrendingPoolService $trendingPool,
        protected SeenFilter $seenFilter,
    ) {}

    /**
     * Build a cold-start feed blending trending with recent posts.
     * One recent post is interleaved per `recent_per_trending` trending slots.
     *
     * @return list<int> post ids in display order
     */
    public function build(User $user, int $limit): array
    {
        $recentEvery = (int) config('recommendation.cold_start.recent_per_trending', 5);
        $reportsThreshold = (int) config('recommendation.candidates.reports_threshold', 3);

        $seen = $this->seenFilter->seenFor($user);

        $trendingIds = [];
        foreach ($this->trendingPool->topIds($limit * 2) as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $trendingIds[] = $id;
        }

        $filterTrending = DB::table('posts')
            ->whereIn('id', $trendingIds === [] ? [0] : $trendingIds)
            ->whereNotNull('embedding')
            ->whereNull('deleted_at')
            ->where('user_id', '!=', $user->id)
            ->where('reports_count', '<', $reportsThreshold)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->flip()
            ->all();

        $validTrending = array_values(array_filter(
            $trendingIds,
            static fn (int $id) => isset($filterTrending[$id]),
        ));

        $recent = DB::table('posts')
            ->select('id')
            ->whereNotNull('embedding')
            ->whereNull('deleted_at')
            ->where('user_id', '!=', $user->id)
            ->where('reports_count', '<', $reportsThreshold)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit * 2)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn (int $id) => ! isset($seen[$id]))
            ->values()
            ->all();

        $result = [];
        $used = [];
        $trendingIdx = 0;
        $recentIdx = 0;

        while (count($result) < $limit) {
            $slotIndex = count($result);
            $isRecentSlot = ($slotIndex + 1) % ($recentEvery + 1) === 0;

            if ($isRecentSlot) {
                $id = $this->nextUnused($recent, $recentIdx, $used);
                if ($id === null) {
                    $id = $this->nextUnused($validTrending, $trendingIdx, $used);
                }
            } else {
                $id = $this->nextUnused($validTrending, $trendingIdx, $used);
                if ($id === null) {
                    $id = $this->nextUnused($recent, $recentIdx, $used);
                }
            }

            if ($id === null) {
                break;
            }

            $result[] = $id;
            $used[$id] = true;
        }

        return $result;
    }

    /**
     * @param  list<int>  $list
     * @param  array<int, true>  $used
     */
    private function nextUnused(array $list, int &$idx, array $used): ?int
    {
        while ($idx < count($list)) {
            $candidate = $list[$idx];
            $idx++;
            if (! isset($used[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    public function isColdStart(User $user): bool
    {
        if (! empty($user->long_term_embedding)) {
            return false;
        }

        $threshold = (int) config('recommendation.cold_start.interactions_threshold', 5);

        $positiveCount = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->where('pi.user_id', $user->id)
            ->where('it.is_positive', true)
            ->count();

        return $positiveCount < $threshold;
    }
}
