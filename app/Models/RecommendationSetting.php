<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'updated_by'])]
class RecommendationSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
