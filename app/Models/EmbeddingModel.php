<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'provider', 'dimensions', 'is_active', 'deprecated_at'])]
class EmbeddingModel extends Model
{
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'is_active' => 'boolean',
            'deprecated_at' => 'datetime',
        ];
    }
}
