<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'mime_prefix', 'is_active'])]
class MediaType extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class);
    }
}
