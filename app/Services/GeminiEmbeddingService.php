<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeminiEmbeddingService
{

    public function embed(array $parts, $task_type = 'RETRIEVAL_DOCUMENT')
    {

        return Http::withHeaders([
            'x-goog-api-key' => config('services.gemini.key'),
            'Content-Type' => 'application/json',
        ])->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2-preview:embedContent', [
            'content' => ['parts' => $parts],
            'task_type' => $task_type,
            'output_dimensionality' => 1536
        ])->throw()->json('embedding.values');

    }

}