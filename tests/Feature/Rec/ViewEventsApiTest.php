<?php

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\ViewSignalCalculator;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);

    Redis::flushdb();
});

test('authenticated_user_can_post_view_events', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    $this->actingAs($user)
        ->postJson(route('rec.view-events.store'), [
            'session_id' => 'sess-abc',
            'events' => [
                ['post_id' => $post->id, 'duration_ms' => 5000],
            ],
        ])
        ->assertStatus(202)
        ->assertJson(['received' => 1, 'recorded' => 1]);

    expect(PostInteraction::where('user_id', $user->id)->where('post_id', $post->id)->count())
        ->toBe(1);
});

test('guest_receives_401', function () {
    $post = Post::factory()->text()->createQuietly();

    $this->postJson(route('rec.view-events.store'), [
        'events' => [
            ['post_id' => $post->id, 'duration_ms' => 5000],
        ],
    ])->assertStatus(401);

    expect(PostInteraction::count())->toBe(0);
});

test('batch_with_mixed_durations_creates_correct_kinds', function () {
    $user = User::factory()->create();
    $postSkip = Post::factory()->text()->createQuietly();
    $postView = Post::factory()->text()->createQuietly();
    $postCapped = Post::factory()->text()->createQuietly();

    $this->actingAs($user)
        ->postJson(route('rec.view-events.store'), [
            'events' => [
                ['post_id' => $postSkip->id, 'duration_ms' => 500],
                ['post_id' => $postView->id, 'duration_ms' => 5000],
                ['post_id' => $postCapped->id, 'duration_ms' => 35000],
            ],
        ])
        ->assertStatus(202)
        ->assertJson(['received' => 3, 'recorded' => 3]);

    $skipTypeId = InteractionType::where('slug', ViewSignalCalculator::KIND_SKIP_FAST)->value('id');
    $viewTypeId = InteractionType::where('slug', ViewSignalCalculator::KIND_VIEW)->value('id');

    expect(PostInteraction::where('post_id', $postSkip->id)->where('interaction_type_id', $skipTypeId)->exists())
        ->toBeTrue();

    expect(PostInteraction::where('post_id', $postView->id)->where('interaction_type_id', $viewTypeId)->exists())
        ->toBeTrue();

    $capped = PostInteraction::where('post_id', $postCapped->id)->where('interaction_type_id', $viewTypeId)->first();
    expect($capped)->not->toBeNull();
    expect((float) $capped->weight)->toBe(1.0);
});

test('neutral_dwell_between_1_and_3s_is_not_recorded', function () {
    $user = User::factory()->create();
    $postA = Post::factory()->text()->createQuietly();
    $postB = Post::factory()->text()->createQuietly();

    $this->actingAs($user)
        ->postJson(route('rec.view-events.store'), [
            'events' => [
                ['post_id' => $postA->id, 'duration_ms' => 1500],
                ['post_id' => $postB->id, 'duration_ms' => 2999],
            ],
        ])
        ->assertStatus(202)
        ->assertJson(['received' => 2, 'recorded' => 0]);

    expect(PostInteraction::where('user_id', $user->id)->count())->toBe(0);
});

test('duration_ms_is_persisted', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    $this->actingAs($user)
        ->postJson(route('rec.view-events.store'), [
            'events' => [
                ['post_id' => $post->id, 'duration_ms' => 12345],
            ],
        ])
        ->assertStatus(202);

    $row = PostInteraction::where('user_id', $user->id)->firstOrFail();

    expect($row->duration_ms)->toBe(12345);
});

test('view_weight_follows_documented_curve', function (int $duration, string $kind, float $expectedWeight) {
    $classification = ViewSignalCalculator::classify($duration);

    expect($classification)->not->toBeNull();
    expect($classification['kind'])->toBe($kind);
    expect($classification['weight'])->toBe($expectedWeight);
})->with([
    'skip rápido <1s' => [500, ViewSignalCalculator::KIND_SKIP_FAST, -0.3],
    'borda inferior do view (3s)' => [3000, ViewSignalCalculator::KIND_VIEW, 0.2],
    'view leve a 10s' => [10000, ViewSignalCalculator::KIND_VIEW, 0.5],
    'view médio a 30s' => [30000, ViewSignalCalculator::KIND_VIEW, 0.8],
    'view longo capado em 1.0' => [60000, ViewSignalCalculator::KIND_VIEW, 1.0],
]);
