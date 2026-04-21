<?php

namespace Tests\Fakes;

use App\Services\GeminiEmbeddingService;

class FakeGeminiEmbeddingService extends GeminiEmbeddingService
{
    public function embed(array $parts, $task_type = 'RETRIEVAL_DOCUMENT')
    {
        $dimensions = (int) config('services.gemini.embedding.dimensions', 1536);

        return array_fill(0, $dimensions, 0.0);
    }
}
