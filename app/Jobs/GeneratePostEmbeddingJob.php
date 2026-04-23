<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\GeminiEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use function base64_encode;

class GeneratePostEmbeddingJob implements ShouldQueue
{
    use Queueable;

    private array $parts = [];
    /**
     * Create a new job instance.
     */
    public function __construct(private Post $post)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(GeminiEmbeddingService $gemini): void
    {
        if (trim($this->post->body) !== '')
        {
            $this->parts[] = ['text' => $this->post->body];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        if ($this->post->media)
        {
            foreach ($this->post->media as $mediaItem)
            {
                $bytes = Storage::get($mediaItem->file_path);
                $this->parts[] = [
                    'inline_data' => [
                        'mime_type' => $finfo->buffer($bytes),
                        'data' => base64_encode($bytes)
                    ]
                ];
            }
        }

        $embedding = $gemini->embed($this->parts);

        $this->post->embedding()->create(['embedding' => $embedding]);
    }
}
