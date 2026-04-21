<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\EmbeddingModel;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:rebackfill-post-embeddings-for-model {model_slug : Slug of the target embedding model (e.g. gemini-embedding-2-preview)} {--chunk=100 : Number of posts to load per batch} {--dispatch-queue : Dispatch jobs to the queue instead of running synchronously}')]
#[Description('Regenerate embeddings for posts whose embedding_model_id differs from the target model (use when switching embedding providers).')]
class RebackfillPostEmbeddingsForModel extends Command
{
    public function handle(): int
    {
        $slug = $this->argument('model_slug');

        $model = EmbeddingModel::where('slug', $slug)->first();

        if (! $model) {
            $this->error("Embedding model '{$slug}' not found.");

            return self::FAILURE;
        }

        $query = Post::query()
            ->with('media')
            ->where(function ($q) use ($model) {
                $q->whereNull('embedding_model_id')
                    ->orWhere('embedding_model_id', '!=', $model->id);
            });

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No posts to rebackfill. All embeddings already use the target model.');

            return self::SUCCESS;
        }

        $this->info("Rebackfilling {$total} posts to model '{$model->slug}'...");

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $failures = 0;
        $chunk = max(1, (int) $this->option('chunk'));
        $useQueue = (bool) $this->option('dispatch-queue');

        $query->chunkById($chunk, function ($posts) use ($progress, &$failures, $useQueue) {
            foreach ($posts as $post) {
                try {
                    if ($useQueue) {
                        GeneratePostEmbeddingJob::dispatch($post, true);
                    } else {
                        dispatch_sync(new GeneratePostEmbeddingJob($post, true));
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
