<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeminiEmbeddingService
{
    public function embed(array $parts, $task_type = 'RETRIEVAL_DOCUMENT')
    {
        $response = Http::withHeaders([
            'x-goog-api-key' => config('services.gemini.key'),
            'Content-Type' => 'application/json',
        ])->post(config('services.gemini.embedding.endpoint').'/gemini-embedding-2-preview:embedContent', [
            'content' => ['parts' => $parts],
            'task_type' => $task_type,
            'output_dimensionality' => config('services.gemini.embedding.dimensions'),
        ])->throw();

        return $response->json('embedding.values');
    }
}
