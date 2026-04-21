<?php

use App\Jobs\AggregateViewSignalsJob;
use App\Jobs\RefreshShortTermEmbeddingJob;
use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\ViewSignalCalculator;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);

    Redis::flushdb();
});

test('job_triggers_st_refresh_for_users_with_new_view_events', function () {
    config()->set('recommendation.view_signals.refresh_threshold_delta', 1.0);

    $user = User::factory()->create();
    $viewType = InteractionType::where('slug', ViewSignalCalculator::KIND_VIEW)->firstOrFail();

    foreach (range(1, 3) as $_) {
        $post = Post::factory()->text()->createQuietly();

        PostInteraction::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'interaction_type_id' => $viewType->id,
            'weight' => 0.8,
            'duration_ms' => 25000,
            'created_at' => now()->subMinutes(2),
        ]);
    }

    Queue::fake();
    Redis::del(RefreshShortTermEmbeddingJob::lockKey($user->id));

    (new AggregateViewSignalsJob)->handle();

    Queue::assertPushed(
        RefreshShortTermEmbeddingJob::class,
        fn (RefreshShortTermEmbeddingJob $job) => $job->userId === $user->id,
    );
});

test('job_is_noop_if_delta_below_threshold', function () {
    config()->set('recommendation.view_signals.refresh_threshold_delta', 1.0);

    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();
    $viewType = InteractionType::where('slug', ViewSignalCalculator::KIND_VIEW)->firstOrFail();

    PostInteraction::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'interaction_type_id' => $viewType->id,
        'weight' => 0.286,
        'duration_ms' => 5000,
        'created_at' => now()->subMinutes(2),
    ]);

    Queue::fake();
    Redis::del(RefreshShortTermEmbeddingJob::lockKey($user->id));

    (new AggregateViewSignalsJob)->handle();

    Queue::assertNotPushed(RefreshShortTermEmbeddingJob::class);
});
