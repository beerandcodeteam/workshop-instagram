<?php

namespace App\Observers;

use App\Models\Comment;

class CommentObserver
{
    public function created(Comment $comment): void
    {
        // No-op no scaffold. Phase 1 grava em post_interactions
        // (kind=comment, weight=+1.5) em paralelo ao registro do comment.
    }

    public function deleted(Comment $comment): void
    {
        // No-op no scaffold.
    }
}
