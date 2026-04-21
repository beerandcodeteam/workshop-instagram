<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'experiment_name', 'variant', 'assigned_at', 'expired_at'])]
class RecommendationExperiment extends Model
{
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
