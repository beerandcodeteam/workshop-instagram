<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'long_term_embedding',
    'long_term_embedding_updated_at',
    'long_term_embedding_model_id',
    'short_term_embedding',
    'short_term_embedding_updated_at',
    'short_term_embedding_model_id',
    'avoid_embedding',
    'avoid_embedding_updated_at',
    'avoid_embedding_model_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'long_term_embedding' => 'array',
            'long_term_embedding_updated_at' => 'datetime',
            'short_term_embedding' => 'array',
            'short_term_embedding_updated_at' => 'datetime',
            'avoid_embedding' => 'array',
            'avoid_embedding_updated_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(PostInteraction::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function interestClusters(): HasMany
    {
        return $this->hasMany(UserInterestCluster::class);
    }

    public function longTermEmbeddingModel(): BelongsTo
    {
        return $this->belongsTo(EmbeddingModel::class, 'long_term_embedding_model_id');
    }

    public function shortTermEmbeddingModel(): BelongsTo
    {
        return $this->belongsTo(EmbeddingModel::class, 'short_term_embedding_model_id');
    }

    public function avoidEmbeddingModel(): BelongsTo
    {
        return $this->belongsTo(EmbeddingModel::class, 'avoid_embedding_model_id');
    }
}
