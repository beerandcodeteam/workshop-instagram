<?php

use App\Jobs\CalculateUserCentroidJob;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
});

test('liking a post dispatches the centroid job for the user', function () {
    Queue::fake();

    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    Like::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    Queue::assertPushed(
        CalculateUserCentroidJob::class,
        fn (CalculateUserCentroidJob $job) => $job->userId === $user->id,
    );
});

test('unliking a post dispatches the centroid job for the user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    $like = Like::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    Queue::fake();

    $like->delete();

    Queue::assertPushed(
        CalculateUserCentroidJob::class,
        fn (CalculateUserCentroidJob $job) => $job->userId === $user->id,
    );
});
