<?php

use App\Jobs\RefreshTrendingPoolJob;
use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\TrendingPoolService;
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

function makeTrendingInteraction(User $user, Post $post, string $slug, $createdAt): PostInteraction
{
    $type = InteractionType::where('slug', $slug)->firstOrFail();

    return PostInteraction::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'interaction_type_id' => $type->id,
        'weight' => $type->default_weight,
        'created_at' => $createdAt,
    ]);
}

test('job_writes_top_n_to_redis_sorted_set', function () {
    config()->set('recommendation.trending.pool_size', 3);

    $users = User::factory()->count(5)->create();
    $posts = collect(range(1, 5))->map(fn () => Post::factory()->text()->createQuietly());

    foreach ($posts as $i => $post) {
        foreach ($users->take($i + 1) as $user) {
            makeTrendingInteraction($user, $post, 'like', now()->subHours(1));
        }
    }

    (new RefreshTrendingPoolJob)->handle(app(TrendingPoolService::class));

    $ids = app(TrendingPoolService::class)->topIds(10);

    expect($ids)->toHaveCount(3);
    expect($ids[0])->toBe($posts[4]->id);
    expect($ids[1])->toBe($posts[3]->id);
    expect($ids[2])->toBe($posts[2]->id);
});

test('trending_excludes_posts_with_reports_over_threshold', function () {
    config()->set('recommendation.trending.reports_threshold', 3);

    $user = User::factory()->create();

    $goodPost = Post::factory()->text()->createQuietly();
    $reportedPost = Post::factory()->text()->createQuietly();
    $reportedPost->forceFill(['reports_count' => 5])->save();

    makeTrendingInteraction($user, $goodPost, 'like', now()->subHours(1));
    makeTrendingInteraction($user, $reportedPost, 'like', now()->subHours(1));

    (new RefreshTrendingPoolJob)->handle(app(TrendingPoolService::class));

    $ids = app(TrendingPoolService::class)->topIds(10);

    expect($ids)->toContain($goodPost->id);
    expect($ids)->not->toContain($reportedPost->id);
});

test('trending_normalizes_by_impressions', function () {
    $users = User::factory()->count(20)->create();

    $heavilyViewedPost = Post::factory()->text()->createQuietly();
    $moderatelyEngagedPost = Post::factory()->text()->createQuietly();

    // heavilyViewedPost: muitas views (mais impressões, score dividido).
    foreach ($users as $user) {
        makeTrendingInteraction($user, $heavilyViewedPost, 'view', now()->subHours(1));
    }
    foreach ($users->take(3) as $user) {
        makeTrendingInteraction($user, $heavilyViewedPost, 'like', now()->subHours(1));
    }

    // moderatelyEngagedPost: poucas views mas muitas curtidas (melhor taxa).
    foreach ($users->take(2) as $user) {
        makeTrendingInteraction($user, $moderatelyEngagedPost, 'view', now()->subHours(1));
    }
    foreach ($users->take(5) as $user) {
        makeTrendingInteraction($user, $moderatelyEngagedPost, 'like', now()->subHours(1));
    }

    (new RefreshTrendingPoolJob)->handle(app(TrendingPoolService::class));

    $scores = app(TrendingPoolService::class)->topWithScores(10);

    expect($scores)->toHaveKey($moderatelyEngagedPost->id);
    expect($scores)->toHaveKey($heavilyViewedPost->id);
    expect($scores[$moderatelyEngagedPost->id])
        ->toBeGreaterThan($scores[$heavilyViewedPost->id]);
});

test('trending_respects_24h_window', function () {
    config()->set('recommendation.trending.window_hours', 24);

    $user = User::factory()->create();

    $recentPost = Post::factory()->text()->createQuietly();
    $oldPost = Post::factory()->text()->createQuietly();

    makeTrendingInteraction($user, $recentPost, 'like', now()->subHours(2));
    makeTrendingInteraction($user, $oldPost, 'like', now()->subHours(30));

    (new RefreshTrendingPoolJob)->handle(app(TrendingPoolService::class));

    $ids = app(TrendingPoolService::class)->topIds(10);

    expect($ids)->toContain($recentPost->id);
    expect($ids)->not->toContain($oldPost->id);
});
