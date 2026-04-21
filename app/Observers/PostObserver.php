<?php

namespace App\Observers;

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\Post;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PostObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Post $post): void
    {
        GeneratePostEmbeddingJob::dispatch($post);
    }

    public function updated(Post $post): void
    {
        if ($post->wasChanged('body')) {
            GeneratePostEmbeddingJob::dispatch($post, true);
        }
    }

    public function deleted(Post $post): void
    {
        //
    }

    public function restored(Post $post): void
    {
        //
    }

    public function forceDeleted(Post $post): void
    {
        //
    }
}
