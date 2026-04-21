<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['request_id', 'user_id', 'post_id', 'recommendation_source_id', 'score', 'rank_position', 'scores_breakdown', 'filtered_reason', 'experiment_variant', 'created_at'])]
class RecommendationLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'score' => 'decimal:6',
            'rank_position' => 'integer',
            'scores_breakdown' => 'array',
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

    public function source(): BelongsTo
    {
        return $this->belongsTo(RecommendationSource::class, 'recommendation_source_id');
    }
}
