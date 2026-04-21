<?php

namespace App\Services\Recommendation;

class AuthorQuota
{
    /**
     * Enforce a cap of `perAuthor` posts per author within the first `topK` slots.
     *
     * Overflowed items are pushed to the bottom (stable) so that their slots can be
     * backfilled by the next eligible candidate respecting the quota.
     *
     * @param  list<RankedCandidate>  $ranked
     * @return list<RankedCandidate>
     */
    public function applyAuthorQuota(array $ranked, ?int $topK = null, ?int $perAuthor = null): array
    {
        $topK ??= (int) config('recommendation.author_quota.top_k', 20);
        $perAuthor ??= (int) config('recommendation.author_quota.per_author', 2);

        if ($ranked === [] || $topK <= 0 || $perAuthor <= 0) {
            return $ranked;
        }

        $selectedTop = [];
        $overflow = [];
        $counts = [];

        foreach ($ranked as $item) {
            if (count($selectedTop) >= $topK) {
                $overflow[] = $item;

                continue;
            }

            $authorId = $item->authorId;
            $current = $counts[$authorId] ?? 0;

            if ($current >= $perAuthor) {
                $overflow[] = $item;

                continue;
            }

            $selectedTop[] = $item;
            $counts[$authorId] = $current + 1;
        }

        if (count($selectedTop) < $topK) {
            $stillNeeded = $topK - count($selectedTop);
            $promoted = [];
            $leftover = [];

            foreach ($overflow as $item) {
                if ($stillNeeded > 0) {
                    $promoted[] = $item;
                    $stillNeeded--;
                } else {
                    $leftover[] = $item;
                }
            }

            $selectedTop = array_merge($selectedTop, $promoted);
            $overflow = $leftover;
        }

        return array_values(array_merge($selectedTop, $overflow));
    }
}
