<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'cluster_index', 'embedding', 'weight', 'sample_count', 'embedding_model_id', 'computed_at'])]
class UserInterestCluster extends Model
{
    protected function casts(): array
    {
        return [
            'cluster_index' => 'integer',
            'embedding' => 'array',
            'weight' => 'decimal:4',
            'sample_count' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function embeddingModel(): BelongsTo
    {
        return $this->belongsTo(EmbeddingModel::class);
    }
}
