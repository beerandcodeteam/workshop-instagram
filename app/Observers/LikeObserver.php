<?php

namespace App\Observers;

use App\Jobs\CalculateUserCentroidJob;
use App\Models\Like;

class LikeObserver
{
    public function created(Like $like): void
    {
        CalculateUserCentroidJob::dispatch($like->user_id);
    }

    public function deleted(Like $like): void
    {
        CalculateUserCentroidJob::dispatch($like->user_id);
    }
}
