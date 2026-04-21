<?php

namespace App\Models;

use App\Observers\PostObserver;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'post_type_id', 'body', 'embedding', 'embedding_updated_at', 'embedding_model_id', 'reports_count'])]
#[ObservedBy(PostObserver::class)]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedding_updated_at' => 'datetime',
            'reports_count' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('sort_order');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function postEmbeddings(): HasMany
    {
        return $this->hasMany(PostEmbedding::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(PostInteraction::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function embeddingModel(): BelongsTo
    {
        return $this->belongsTo(EmbeddingModel::class);
    }
}
