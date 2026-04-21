<?php

namespace App\Jobs;

use App\Models\Like;
use App\Models\PostEmbedding;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CalculateUserCentroidJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(): void
    {
        $user = User::findOrFail($this->userId);

        $likedPostIds = Like::where('user_id', $user->id)->pluck('post_id');

        $embeddings = PostEmbedding::whereIn('post_id', $likedPostIds)
            ->get()
            ->pluck('embedding');

        if ($embeddings->isEmpty()) {
            $user->update(['embedding' => null]);

            return;
        }

        $dimensions = count($embeddings->first());
        $centroid = array_fill(0, $dimensions, 0.0);

        foreach ($embeddings as $embedding) {
            foreach ($embedding as $i => $value) {
                $centroid[$i] += $value;
            }
        }

        $total = $embeddings->count();
        foreach ($centroid as $i => $sum) {
            $centroid[$i] = $sum / $total;
        }

        $user->update(['embedding' => $centroid]);
    }
}
