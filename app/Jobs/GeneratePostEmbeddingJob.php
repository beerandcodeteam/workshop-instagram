<?php

namespace App\Jobs;

use App\Models\EmbeddingModel;
use App\Models\Post;
use App\Services\GeminiCircuitBreaker;
use App\Services\GeminiEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GeneratePostEmbeddingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private Post $post, private bool $replace = false)
    {
        $this->onQueue('embeddings');
    }

    public function handle(GeminiEmbeddingService $gemini, GeminiCircuitBreaker $breaker): void
    {
        if ($breaker->isOpen()) {
            $this->release(300);

            return;
        }

        $post = $this->post->fresh();

        if ($post === null) {
            return;
        }

        $hasBody = trim((string) $post->body) !== '';
        $hasMedia = $post->media()->exists();

        if (! $hasBody && ! $hasMedia) {
            return;
        }

        if (! $this->replace && $post->embedding !== null) {
            return;
        }

        $parts = [];

        if ($hasBody) {
            $parts[] = ['text' => $post->body];
        }

        if ($hasMedia) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);

            foreach ($post->media as $mediaItem) {
                $bytes = Storage::get($mediaItem->file_path);

                if ($bytes === null) {
                    continue;
                }

                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $finfo->buffer($bytes),
                        'data' => base64_encode($bytes),
                    ],
                ];
            }
        }

        try {
            $embedding = $gemini->embed($parts);
        } catch (Throwable $e) {
            $breaker->recordFailure();

            throw $e;
        }

        $breaker->recordSuccess();

        $modelId = EmbeddingModel::where('slug', config('services.gemini.embedding.model'))
            ->value('id');

        $post->forceFill([
            'embedding' => $embedding,
            'embedding_updated_at' => now(),
            'embedding_model_id' => $modelId,
        ])->save();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 45];
    }
}
