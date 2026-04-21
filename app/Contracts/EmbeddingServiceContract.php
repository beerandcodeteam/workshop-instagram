<?php

namespace App\Contracts;

interface EmbeddingServiceContract
{
    /**
     * Generate an embedding for the given multimodal parts.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, float>
     */
    public function embed(array $parts, string $taskType = 'RETRIEVAL_DOCUMENT'): array;
}
