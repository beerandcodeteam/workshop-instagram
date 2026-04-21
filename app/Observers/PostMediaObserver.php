<?php

namespace App\Observers;

use App\Models\PostMedia;

class PostMediaObserver
{
    public function created(PostMedia $media): void
    {
        // No-op no MVP. Hook reservado para invalidar/regerar o embedding
        // do post quando as mídias mudarem.
    }

    public function deleted(PostMedia $media): void
    {
        // No-op no MVP. Ver `created()`.
    }
}
