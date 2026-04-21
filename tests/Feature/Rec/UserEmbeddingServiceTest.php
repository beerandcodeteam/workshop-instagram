<?php

use App\Models\User;
use App\Services\Recommendation\UserEmbeddingService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

test('readShortTerm_returns_redis_cached_value', function () {
    $user = User::factory()->create();
    $service = app(UserEmbeddingService::class);

    $cached = array_fill(0, 1536, 0.0);
    $cached[0] = 0.42;

    Redis::set("rec:user:{$user->id}:short_term", json_encode($cached));

    $vector = $service->readShortTerm($user);

    expect($vector)->not->toBeNull();
    expect($vector)->toHaveCount(1536);
    expect($vector[0])->toBe(0.42);
});

test('readShortTerm_falls_back_to_postgres_on_miss', function () {
    $user = User::factory()->create();
    $service = app(UserEmbeddingService::class);

    Redis::del("rec:user:{$user->id}:short_term");

    $stored = array_fill(0, 1536, 0.0);
    $stored[10] = 0.7;

    DB::table('users')->where('id', $user->id)->update([
        'short_term_embedding' => '['.implode(',', $stored).']',
        'short_term_embedding_updated_at' => now(),
    ]);

    $vector = $service->readShortTerm($user);

    expect($vector)->not->toBeNull();
    expect($vector)->toHaveCount(1536);
    expect(round($vector[10], 4))->toBe(0.7);

    expect((bool) Redis::exists("rec:user:{$user->id}:short_term"))->toBeTrue();
});
