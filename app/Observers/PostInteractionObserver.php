<?php

namespace App\Observers;

use App\Jobs\RefreshShortTermEmbeddingJob;
use App\Models\PostInteraction;
use Illuminate\Support\Facades\Redis;

class PostInteractionObserver
{
    public function created(PostInteraction $interaction): void
    {
        if (! $interaction->type?->is_positive) {
            return;
        }

        $this->appendToBuffer($interaction);

        RefreshShortTermEmbeddingJob::dispatchDebounced($interaction->user_id);
    }

    private function appendToBuffer(PostInteraction $interaction): void
    {
        $key = "rec:user:{$interaction->user_id}:short_term_buffer";
        $maxItems = (int) config('recommendation.short_term.buffer_max_items', 50);

        $payload = json_encode([
            'post_id' => $interaction->post_id,
            'interaction_type_id' => $interaction->interaction_type_id,
            'weight' => (float) $interaction->weight,
            'created_at' => $interaction->created_at?->toIso8601String(),
        ]);

        Redis::lpush($key, $payload);
        Redis::ltrim($key, 0, $maxItems - 1);
    }
}
