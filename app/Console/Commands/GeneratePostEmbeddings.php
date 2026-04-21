<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:generate-post-embeddings {--force : Regenerate embeddings even for posts that already have one} {--chunk=100 : Number of posts to load per batch} {--since= : Only process posts created at or after this date (YYYY-MM-DD)} {--post-ids= : Comma-separated list of specific post IDs to process} {--dispatch-queue : Dispatch jobs to the queue instead of running synchronously}')]
#[Description('Generate Gemini embeddings for posts (skips posts that already have an embedding unless --force is passed)')]
class GeneratePostEmbeddings extends Command
{
    public function handle(): int
    {
        $query = Post::query()->with('media');

        if (! $this->option('force')) {
            $query->whereNull('embedding');
        }

        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', $since);
        }

        if ($postIds = $this->option('post-ids')) {
            $ids = array_filter(array_map('trim', explode(',', $postIds)));
            $query->whereIn('id', $ids);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No posts to process.');

            return self::SUCCESS;
        }

        $this->info("Generating embeddings for {$total} posts...");

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $failures = 0;
        $chunk = max(1, (int) $this->option('chunk'));
        $useQueue = (bool) $this->option('dispatch-queue');

        $query->chunkById($chunk, function ($posts) use ($progress, &$failures, $useQueue) {
            foreach ($posts as $post) {
                try {
                    if ($this->option('force')) {
                        $post->forceFill([
                            'embedding' => null,
                            'embedding_updated_at' => null,
                            'embedding_model_id' => null,
                        ])->save();
                    }

                    if ($useQueue) {
                        GeneratePostEmbeddingJob::dispatch($post, $this->option('force'));
                    } else {
                        dispatch_sync(new GeneratePostEmbeddingJob($post, $this->option('force')));
                    }
                } catch (Throwable $e) {
                    $failures++;
                    $this->newLine();
                    $this->error("Post #{$post->id}: {$e->getMessage()}");
                }

                $progress->advance();
            }
        });

        $progress->finish();
        $this->newLine(2);

        $succeeded = $total - $failures;
        $this->info("Done. Succeeded: {$succeeded}. Failed: {$failures}.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
