<?php

use App\Jobs\RefreshShortTermEmbeddingJob;
use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);

    Redis::flushdb();
});

test('positive_interaction_dispatches_short_term_refresh', function () {
    Queue::fake();

    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();
    $type = InteractionType::where('slug', 'comment')->firstOrFail();

    Redis::del("rec:user:{$user->id}:st_lock");
    Redis::del("rec:user:{$user->id}:short_term_buffer");

    PostInteraction::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'interaction_type_id' => $type->id,
        'weight' => $type->default_weight,
        'created_at' => now(),
    ]);

    Queue::assertPushed(
        RefreshShortTermEmbeddingJob::class,
        fn (RefreshShortTermEmbeddingJob $job) => $job->userId === $user->id,
    );

    expect(Redis::llen("rec:user:{$user->id}:short_term_buffer"))->toBe(1);
});

test('negative_interaction_does_not_dispatch_short_term', function () {
    Queue::fake();

    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();
    $hideType = InteractionType::where('slug', 'hide')->firstOrFail();

    Redis::del("rec:user:{$user->id}:st_lock");

    PostInteraction::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'interaction_type_id' => $hideType->id,
        'weight' => $hideType->default_weight,
        'created_at' => now(),
    ]);

    Queue::assertNotPushed(RefreshShortTermEmbeddingJob::class);
});

test('dispatch_is_debounced_within_5s', function () {
    Queue::fake();

    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();
    $type = InteractionType::where('slug', 'like')->firstOrFail();

    Redis::del("rec:user:{$user->id}:st_lock");

    foreach (range(1, 3) as $_) {
        PostInteraction::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'interaction_type_id' => $type->id,
            'weight' => $type->default_weight,
            'created_at' => now(),
        ]);
    }

    Queue::assertPushed(RefreshShortTermEmbeddingJob::class, 1);
});
