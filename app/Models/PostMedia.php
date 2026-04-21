<?php

namespace App\Models;

use App\Observers\PostMediaObserver;
use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['post_id', 'media_type_id', 'file_path', 'sort_order'])]
#[ObservedBy(PostMediaObserver::class)]
class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use HasFactory;

    protected $table = 'post_media';

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function mediaType(): BelongsTo
    {
        return $this->belongsTo(MediaType::class);
    }
}
