<?php

use App\Models\User;
use App\Services\Recommendation\Ranker;
use App\Services\Recommendation\RecommendationSettingsService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);

    Redis::flushdb();
});

function recConfigVector(int $dim, int $hotIndex, float $value = 1.0): array
{
    $vec = array_fill(0, $dim, 0.0);
    $vec[$hotIndex] = $value;

    return $vec;
}

function recConfigWriteUserVector(User $user, string $column, array $vec): void
{
    DB::table('users')->where('id', $user->id)->update([
        $column => '['.implode(',', $vec).']',
        "{$column}_updated_at" => now(),
    ]);
}

test('score_formula_reads_weights_from_config', function () {
    config()->set('recommendation.score.alpha_default', 0.7);
    config()->set('recommendation.score.beta_avoid', 0.4);
    config()->set('recommendation.score.gamma_recency', 0.2);
    config()->set('recommendation.score.delta_trending', 0.15);
    config()->set('recommendation.score.epsilon_context', 0.05);

    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    recConfigWriteUserVector($user, 'long_term_embedding', recConfigVector($dim, 0));
    recConfigWriteUserVector($user, 'short_term_embedding', recConfigVector($dim, 1));
    recConfigWriteUserVector($user, 'avoid_embedding', recConfigVector($dim, 5));
    $user->refresh();

    $postEmbedding = recConfigVector($dim, 0);

    [, $breakdown] = app(Ranker::class)->score(
        postEmbedding: $postEmbedding,
        user: $user,
        sourceTrendingScore: 1.0,
        recencyBoost: 1.0,
        contextBoost: 1.0,
    );

    expect($breakdown['alpha'])->toBe(0.7);

    $expected = 0.7 * $breakdown['cos_lt']
        + 0.3 * $breakdown['cos_st']
        - 0.4 * $breakdown['cos_av']
        + 0.2 * 1.0
        + 0.15 * 1.0
        + 0.05 * 1.0;

    expect($breakdown['final'])->toEqualWithDelta($expected, 1e-6);
});

test('changing_config_takes_effect_without_restart', function () {
    config()->set('recommendation.score.alpha_default', 0.9);

    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    recConfigWriteUserVector($user, 'long_term_embedding', recConfigVector($dim, 0));
    recConfigWriteUserVector($user, 'short_term_embedding', recConfigVector($dim, 1));
    $user->refresh();

    $postEmbedding = recConfigVector($dim, 0);

    [, $first] = app(Ranker::class)->score($postEmbedding, $user);

    expect($first['alpha'])->toBe(0.9);

    config()->set('recommendation.score.alpha_default', 0.2);
    app()->forgetInstance(Ranker::class);

    [, $second] = app(Ranker::class)->score($postEmbedding, $user);

    expect($second['alpha'])->toBe(0.2);
    expect($second['final'])->not->toBe($first['final']);
});

test('settings_service_overrides_config_values_and_caches', function () {
    config()->set('recommendation.score.alpha_default', 0.8);

    $service = app(RecommendationSettingsService::class);

    expect($service->get('score.alpha_default'))->toBe(0.8);

    $service->set('score.alpha_default', 0.25, updatedBy: 'tester@example.com');

    expect($service->get('score.alpha_default'))->toBe(0.25);

    $service->forgetOverride('score.alpha_default');

    expect($service->get('score.alpha_default'))->toBe(0.8);
});
