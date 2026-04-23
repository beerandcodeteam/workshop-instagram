<?php

namespace App\Observers;

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\Post;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use function dispatch_sync;

class PostObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Post $post): void
    {
        dispatch_sync(new GeneratePostEmbeddingJob($post));
    }
}
