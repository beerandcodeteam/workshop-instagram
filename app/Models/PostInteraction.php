<?php

namespace App\Models;

use App\Observers\PostInteractionObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'post_id', 'interaction_type_id', 'weight', 'session_id', 'duration_ms', 'context', 'created_at'])]
#[ObservedBy(PostInteractionObserver::class)]
class PostInteraction extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'duration_ms' => 'integer',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function interactionType(): BelongsTo
    {
        return $this->belongsTo(InteractionType::class);
    }
}
