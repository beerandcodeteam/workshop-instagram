<?php

namespace App\Services\Recommendation;

class ExplorationSlot
{
    /**
     * Guarantee at least `minimum` exploration posts per `windowSize` slots.
     * Promotes explore-tagged candidates to the front of a window when missing.
     *
     * @param  list<RankedCandidate>  $ranked
     * @return list<RankedCandidate>
     */
    public function enforce(array $ranked, ?int $windowSize = null, ?int $minimum = null): array
    {
        $windowSize ??= (int) config('recommendation.exploration.window_size', 10);
        $minimum ??= (int) config('recommendation.exploration.per_window', 1);

        if ($ranked === [] || $windowSize <= 0 || $minimum <= 0) {
            return $ranked;
        }

        $result = $ranked;

        for ($start = 0; $start < count($result); $start += $windowSize) {
            $end = min($start + $windowSize, count($result));
            $windowExploreCount = 0;

            for ($i = $start; $i < $end; $i++) {
                if ($result[$i]->candidate->source === 'explore') {
                    $windowExploreCount++;
                }
            }

            $needed = $minimum - $windowExploreCount;
            if ($needed <= 0) {
                continue;
            }

            // Procura próximo explore fora da janela e promove.
            for ($j = $end; $j < count($result) && $needed > 0; $j++) {
                if ($result[$j]->candidate->source !== 'explore') {
                    continue;
                }

                $explore = $result[$j];
                array_splice($result, $j, 1);
                $insertPos = $start + $windowSize - $needed;
                $insertPos = min($insertPos, count($result));
                array_splice($result, $insertPos, 0, [$explore]);
                $needed--;
            }
        }

        return array_values($result);
    }
}
