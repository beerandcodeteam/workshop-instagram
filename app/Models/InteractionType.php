<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'default_weight', 'half_life_hours', 'is_positive', 'is_active'])]
class InteractionType extends Model
{
    protected function casts(): array
    {
        return [
            'default_weight' => 'decimal:3',
            'half_life_hours' => 'integer',
            'is_positive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function postInteractions(): HasMany
    {
        return $this->hasMany(PostInteraction::class);
    }
}
