<?php

use App\Jobs\RefreshShortTermEmbeddingJob;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

test('liking a post dispatches the short-term refresh job for the user', function () {
    Queue::fake();

    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    Redis::del("rec:user:{$user->id}:st_lock");

    Like::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    Queue::assertPushed(
        RefreshShortTermEmbeddingJob::class,
        fn (RefreshShortTermEmbeddingJob $job) => $job->userId === $user->id,
    );
});

test('unliking a post does not dispatch a short-term refresh', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    $like = Like::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    Queue::fake();
    Redis::del("rec:user:{$user->id}:st_lock");

    $like->delete();

    Queue::assertNotPushed(RefreshShortTermEmbeddingJob::class);
});
