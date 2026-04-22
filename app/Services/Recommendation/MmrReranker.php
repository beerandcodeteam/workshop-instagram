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

        // Pré-normaliza cada embedding uma única vez: depois disso, sim(a, b) = dot(â, b̂).
        $normalized = [];
        foreach ($pool as $index => $candidate) {
            $normalized[$index] = $this->normalize($candidate->embedding);
        }

        $remaining = array_keys($pool);
        $maxSim = array_fill_keys($remaining, 0.0);

        $selected = [];

        while ($remaining !== []) {
            $bestPosition = 0;
            $bestIndex = $remaining[0];
            $bestMmr = -\INF;

            foreach ($remaining as $position => $index) {
                $mmr = $lambda * $pool[$index]->score - (1.0 - $lambda) * $maxSim[$index];

                if ($mmr > $bestMmr) {
                    $bestMmr = $mmr;
                    $bestPosition = $position;
                    $bestIndex = $index;
                }
            }

            $selected[] = $pool[$bestIndex];
            array_splice($remaining, $bestPosition, 1);

            if ($remaining === []) {
                break;
            }

            // maxSim incremental: compara os remanescentes apenas contra o recém-selecionado.
            $chosenVector = $normalized[$bestIndex];
            foreach ($remaining as $index) {
                $sim = $this->dot($normalized[$index], $chosenVector);
                if ($sim > $maxSim[$index]) {
                    $maxSim[$index] = $sim;
                }
            }
        }

        return array_values(array_merge($selected, $tail));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalize(array $vector): array
    {
        $sum = 0.0;
        foreach ($vector as $v) {
            $sum += $v * $v;
        }

        if ($sum <= 0.0) {
            return $vector;
        }

        $norm = sqrt($sum);
        $out = [];
        foreach ($vector as $v) {
            $out[] = $v / $norm;
        }

        return $out;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function dot(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }
}
