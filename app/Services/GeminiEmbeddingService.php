<?php

namespace App\Services;

use App\Contracts\EmbeddingServiceContract;
use Illuminate\Support\Facades\Http;

class GeminiEmbeddingService implements EmbeddingServiceContract
{
    public function embed(array $parts, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $response = Http::withHeaders([
            'x-goog-api-key' => config('services.gemini.key'),
            'Content-Type' => 'application/json',
        ])->post(config('services.gemini.embedding.endpoint').'/'.config('services.gemini.embedding.model').':embedContent', [
            'content' => ['parts' => $parts],
            'task_type' => $taskType,
            'output_dimensionality' => config('services.gemini.embedding.dimensions'),
        ])->throw();

        return $response->json('embedding.values');
    }
}
