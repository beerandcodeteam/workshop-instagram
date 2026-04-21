<?php

namespace App\Services\Recommendation;

class RankedCandidate
{
    /**
     * @param  array<string, float>  $scoresBreakdown
     */
    public function __construct(
        public Candidate $candidate,
        public int $authorId,
        public float $score,
        public array $scoresBreakdown,
        public array $embedding,
    ) {}
}
