<?php

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\ColdStartFeedBuilder;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);

    Redis::flushdb();
});

test('new_user_gets_trending_blended_with_recent_posts', function () {
    $newUser = User::factory()->create();
    $author = User::factory()->create();

    // Cria alguns posts para servir de trending.
    $trendingPosts = collect();
    for ($i = 0; $i < 5; $i++) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly(['created_at' => now()->subDays(3)]);
        writePostEmbedding($post, candidateVector(
            (int) config('services.gemini.embedding.dimensions', 1536),
            0,
        ));
        $trendingPosts->push($post);
    }

    // Cria posts recentes.
    $recentPosts = collect();
    for ($i = 0; $i < 3; $i++) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly(['created_at' => now()->subMinutes($i + 1)]);
        writePostEmbedding($post, candidateVector(
            (int) config('services.gemini.embedding.dimensions', 1536),
            0,
        ));
        $recentPosts->push($post);
    }

    $key = config('recommendation.trending.redis_key');
    foreach ($trendingPosts as $i => $post) {
        Redis::zadd($key, 10.0 - $i, $post->id);
    }

    $ids = app(ColdStartFeedBuilder::class)->build($newUser, 12);

    expect($ids)->not->toBeEmpty();

    // Valida que o feed mistura trending e recent.
    $trendingIds = $trendingPosts->pluck('id')->all();
    $recentIds = $recentPosts->pluck('id')->all();

    $hasTrending = collect($ids)->intersect($trendingIds)->isNotEmpty();
    $hasRecent = collect($ids)->intersect($recentIds)->isNotEmpty();

    expect($hasTrending)->toBeTrue();
    expect($hasRecent)->toBeTrue();
});

test('cold_start_interleaves_one_recent_per_five_trending', function () {
    config()->set('recommendation.cold_start.recent_per_trending', 5);

    $newUser = User::factory()->create();
    $author = User::factory()->create();

    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $trending = collect();
    for ($i = 0; $i < 20; $i++) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly(['created_at' => now()->subDays(3)->subMinutes($i)]);
        writePostEmbedding($post, candidateVector($dim, 0));
        $trending->push($post);
    }

    $recent = collect();
    for ($i = 0; $i < 5; $i++) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly(['created_at' => now()->subMinutes($i + 1)]);
        writePostEmbedding($post, candidateVector($dim, 0));
        $recent->push($post);
    }

    $key = config('recommendation.trending.redis_key');
    foreach ($trending as $i => $post) {
        Redis::zadd($key, 100.0 - $i, $post->id);
    }

    $ids = app(ColdStartFeedBuilder::class)->build($newUser, 12);

    expect($ids)->toHaveCount(12);

    $recentIds = $recent->pluck('id')->flip()->all();

    // O 6º slot (index 5) e 12º slot (index 11) devem ser "recent" (1 a cada 5).
    expect(isset($recentIds[$ids[5]]))->toBeTrue();
    expect(isset($recentIds[$ids[11]]))->toBeTrue();
});

test('user_promoted_to_recommendation_path_after_5th_positive_interaction', function () {
    $user = User::factory()->create();
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);
    $author = User::factory()->create();

    $post = Post::factory()->text()->for($author, 'author')->createQuietly();
    writePostEmbedding($post, candidateVector($dim, 0));

    $coldStart = app(ColdStartFeedBuilder::class);

    expect($coldStart->isColdStart($user->fresh()))->toBeTrue();

    $likeType = InteractionType::where('slug', 'like')->firstOrFail();
    for ($i = 0; $i < 5; $i++) {
        PostInteraction::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subMinutes($i + 1),
        ]);
    }

    expect($coldStart->isColdStart($user->fresh()))->toBeFalse();
});
