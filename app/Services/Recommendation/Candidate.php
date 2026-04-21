<?php

namespace App\Services\Recommendation;

class Candidate
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $postId,
        public string $source,
        public float $sourceScore,
        public array $metadata = [],
    ) {}

    public static function make(int $postId, string $source, float $sourceScore, array $metadata = []): self
    {
        return new self($postId, $source, $sourceScore, $metadata);
    }
}
