<?php

namespace App\Observers;

use App\Jobs\CalculateCentroidJob;
use App\Models\Like;

class LikeObserver
{
    public function created(Like $like)
    {
        CalculateCentroidJob::dispatch($like->user_id);
    }

    public function deleted(Like $like)
    {
        CalculateCentroidJob::dispatch($like->user_id);
    }
}
