<?php

namespace App\Observers;

use App\Models\Comment;
use App\Models\InteractionType;
use App\Models\PostInteraction;

class CommentObserver
{
    public function created(Comment $comment): void
    {
        $type = InteractionType::where('slug', 'comment')->first();

        if ($type === null) {
            return;
        }

        PostInteraction::create([
            'user_id' => $comment->user_id,
            'post_id' => $comment->post_id,
            'interaction_type_id' => $type->id,
            'weight' => $type->default_weight,
            'created_at' => now(),
        ]);
    }
}
