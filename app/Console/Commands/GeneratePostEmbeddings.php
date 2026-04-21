<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:generate-post-embeddings {--force : Regenerate embeddings even for posts that already have one} {--chunk=100 : Number of posts to load per batch}')]
#[Description('Generate Gemini embeddings for posts (skips posts that already have an embedding unless --force is passed)')]
class GeneratePostEmbeddings extends Command
{
    public function handle(): int
    {
        $query = Post::query()->with('media');

        if (! $this->option('force')) {
            $query->doesntHave('postEmbeddings');
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

        $query->chunkById($chunk, function ($posts) use ($progress, &$failures) {
            foreach ($posts as $post) {
                try {
                    if ($this->option('force')) {
                        $post->postEmbeddings()->delete();
                    }

                    dispatch_sync(new GeneratePostEmbeddingJob($post));
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
