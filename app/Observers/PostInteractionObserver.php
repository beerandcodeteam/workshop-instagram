<?php

namespace App\Observers;

use App\Models\PostInteraction;

class PostInteractionObserver
{
    public function created(PostInteraction $interaction): void
    {
        // No-op no scaffold. Phase 1 dispara debounce de
        // RefreshShortTermEmbeddingJob e atualização do buffer Redis.
    }
}
