<?php

namespace App\Observers;

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\Post;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

use function dispatch_sync;

class PostObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Handle the Post "created" event.
     */
    public function created(Post $post): void
    {
        dispatch_sync(new GeneratePostEmbeddingJob($post));
    }

    /**
     * Handle the Post "updated" event.
     */
    public function updated(Post $post): void
    {
        //
    }

    /**
     * Handle the Post "deleted" event.
     */
    public function deleted(Post $post): void
    {
        //
    }

    /**
     * Handle the Post "restored" event.
     */
    public function restored(Post $post): void
    {
        //
    }

    /**
     * Handle the Post "force deleted" event.
     */
    public function forceDeleted(Post $post): void
    {
        //
    }
}
