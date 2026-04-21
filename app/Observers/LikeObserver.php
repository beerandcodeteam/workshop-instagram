<?php

namespace App\Observers;

use App\Models\InteractionType;
use App\Models\Like;
use App\Models\PostInteraction;

class LikeObserver
{
    public function created(Like $like): void
    {
        $type = InteractionType::where('slug', 'like')->first();

        if ($type === null) {
            return;
        }

        PostInteraction::create([
            'user_id' => $like->user_id,
            'post_id' => $like->post_id,
            'interaction_type_id' => $type->id,
            'weight' => $type->default_weight,
            'created_at' => now(),
        ]);
    }

    public function deleted(Like $like): void
    {
        $type = InteractionType::where('slug', 'unlike')->first();

        if ($type === null) {
            return;
        }

        PostInteraction::create([
            'user_id' => $like->user_id,
            'post_id' => $like->post_id,
            'interaction_type_id' => $type->id,
            'weight' => -0.5,
            'created_at' => now(),
        ]);
    }
}
