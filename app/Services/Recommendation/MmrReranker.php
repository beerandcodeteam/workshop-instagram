<?php

namespace App\Services\Recommendation;

class MmrReranker
{
    /**
     * Apply Maximal Marginal Relevance over a pool of ranked candidates.
     *
     * MMR(d) = λ · score(d) - (1-λ) · max_{d' in selected} sim(d, d')
     *
     * @param  list<RankedCandidate>  $ranked
     * @return list<RankedCandidate>
     */
    public function applyMmr(array $ranked, ?float $lambda = null, ?int $poolSize = null): array
    {
        $lambda ??= (float) config('recommendation.mmr.lambda', 0.7);
        $poolSize ??= (int) config('recommendation.mmr.pool_size', 100);

        if ($ranked === []) {
            return [];
        }

        $pool = array_slice($ranked, 0, $poolSize);
        $tail = array_slice($ranked, $poolSize);

        if ($lambda >= 1.0) {
            return array_values(array_merge($pool, $tail));
        }

        $selected = [];
        $remaining = $pool;

        while ($remaining !== []) {
            $bestIndex = 0;
            $bestMmr = -\INF;

            foreach ($remaining as $index => $candidate) {
                $maxSim = 0.0;
                foreach ($selected as $chosen) {
                    $sim = $this->cosine($candidate->embedding, $chosen->embedding);
                    if ($sim > $maxSim) {
                        $maxSim = $sim;
                    }
                }

                $mmr = $lambda * $candidate->score - (1.0 - $lambda) * $maxSim;

                if ($mmr > $bestMmr) {
                    $bestMmr = $mmr;
                    $bestIndex = $index;
                }
            }

            $selected[] = $remaining[$bestIndex];
            unset($remaining[$bestIndex]);
            $remaining = array_values($remaining);
        }

        return array_values(array_merge($selected, $tail));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);
        if ($denominator <= 0.0) {
            return 0.0;
        }

        return $dot / $denominator;
    }
}
