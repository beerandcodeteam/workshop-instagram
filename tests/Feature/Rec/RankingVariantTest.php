<?php

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\RecommendationExperiment;
use App\Models\RecommendationLog;
use App\Models\User;
use App\Services\Recommendation\Candidate;
use App\Services\Recommendation\ExperimentService;
use App\Services\Recommendation\Ranker;
use App\Services\Recommendation\RecommendationService;
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

function rankingVariantVector(int $dim, int $hotIndex, float $value = 1.0): array
{
    $vec = array_fill(0, $dim, 0.0);
    $vec[$hotIndex] = $value;

    return $vec;
}

function rankingVariantWritePostEmbedding(Post $post, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::table('posts')->where('id', $post->id)->update([
        'embedding' => $literal,
        'embedding_updated_at' => now(),
    ]);
}

function rankingVariantWriteUserEmbedding(User $user, string $column, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::table('users')->where('id', $user->id)->update([
        $column => $literal,
        "{$column}_updated_at" => now(),
    ]);
}

test('variant_b_uses_alternative_scoring_formula', function () {
    config()->set('recommendation.score.beta_avoid', 0.5);
    config()->set('recommendation.score.gamma_recency', 0.15);
    config()->set('recommendation.score.delta_trending', 0.1);
    config()->set('recommendation.score.epsilon_context', 0.05);

    config()->set('recommendation.experiments.ranking_formula_v2.variant_b', [
        'beta_avoid' => 0.1,
        'gamma_recency' => 0.9,
        'delta_trending' => 0.5,
        'epsilon_context' => 0.0,
    ]);

    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    rankingVariantWriteUserEmbedding($user, 'long_term_embedding', rankingVariantVector($dim, 0));
    rankingVariantWriteUserEmbedding($user, 'avoid_embedding', rankingVariantVector($dim, 5));
    $user->refresh();

    $ranker = app(Ranker::class);

    $postEmbedding = rankingVariantVector($dim, 0);

    [$scoreA, $breakdownA] = $ranker->score(
        postEmbedding: $postEmbedding,
        user: $user,
        alphaOverride: 1.0,
        sourceTrendingScore: 1.0,
        recencyBoost: 1.0,
        variant: ExperimentService::VARIANT_A,
    );

    [$scoreB, $breakdownB] = $ranker->score(
        postEmbedding: $postEmbedding,
        user: $user,
        alphaOverride: 1.0,
        sourceTrendingScore: 1.0,
        recencyBoost: 1.0,
        variant: ExperimentService::VARIANT_B,
    );

    expect($breakdownA['variant'])->toBe(ExperimentService::VARIANT_A);
    expect($breakdownB['variant'])->toBe(ExperimentService::VARIANT_B);

    expect($breakdownA['gamma'])->toBe(0.15);
    expect($breakdownB['gamma'])->toBe(0.9);
    expect($breakdownA['delta'])->toBe(0.1);
    expect($breakdownB['delta'])->toBe(0.5);

    expect($scoreB)->not->toBe($scoreA);
    expect($scoreB)->toBeGreaterThan($scoreA);
});

test('ranking_logs_record_variant_served', function () {
    config()->set('recommendation.experiments.random_serving.enabled', false);

    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    rankingVariantWriteUserEmbedding($viewer, 'long_term_embedding', rankingVariantVector($dim, 0));

    $likeType = InteractionType::where('slug', 'like')->firstOrFail();
    $warmUpPost = Post::factory()->text()->createQuietly();
    rankingVariantWritePostEmbedding($warmUpPost, rankingVariantVector($dim, 0));

    for ($i = 0; $i < 6; $i++) {
        PostInteraction::create([
            'user_id' => $viewer->id,
            'post_id' => $warmUpPost->id,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subDays(5)->subMinutes($i),
        ]);
    }

    $viewer->refresh();

    RecommendationExperiment::create([
        'user_id' => $viewer->id,
        'experiment_name' => ExperimentService::RANKING_FORMULA_V2,
        'variant' => ExperimentService::VARIANT_B,
        'assigned_at' => now(),
    ]);

    $author = User::factory()->create();
    for ($i = 0; $i < 3; $i++) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly();
        rankingVariantWritePostEmbedding($post, rankingVariantVector($dim, $i));
    }

    $feed = app(RecommendationService::class)->feedFor($viewer, page: 1, pageSize: 10);

    expect($feed->isNotEmpty())->toBeTrue();

    $logs = RecommendationLog::where('user_id', $viewer->id)
        ->whereNull('filtered_reason')
        ->get();

    expect($logs)->not->toBeEmpty();

    $variants = $logs->pluck('experiment_variant')->unique()->values()->all();
    expect($variants)->toBe([ExperimentService::VARIANT_B]);

    $firstLog = $logs->first();
    expect($firstLog->scores_breakdown)->toHaveKey('variant');
    expect($firstLog->scores_breakdown['variant'])->toBe(ExperimentService::VARIANT_B);
});

test('ranker_returns_different_ordering_per_variant', function () {
    config()->set('recommendation.experiments.ranking_formula_v2.variant_b', [
        'beta_avoid' => 0.0,
        'gamma_recency' => 0.0,
        'delta_trending' => 5.0,
        'epsilon_context' => 0.0,
    ]);

    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    rankingVariantWriteUserEmbedding($user, 'long_term_embedding', rankingVariantVector($dim, 0));
    $user->refresh();

    $similarPost = Post::factory()->text()->createQuietly();
    rankingVariantWritePostEmbedding($similarPost, rankingVariantVector($dim, 0));
    DB::table('posts')->where('id', $similarPost->id)->update(['created_at' => now()->subHours(24)]);

    $trendingPost = Post::factory()->text()->createQuietly();
    rankingVariantWritePostEmbedding($trendingPost, rankingVariantVector($dim, 10));
    DB::table('posts')->where('id', $trendingPost->id)->update(['created_at' => now()]);

    $candidates = [
        $similarPost->id => Candidate::make($similarPost->id, 'ann_long_term', 1.0),
        $trendingPost->id => Candidate::make($trendingPost->id, 'trending', 1.0),
    ];

    $ranker = app(Ranker::class);

    $rankedA = $ranker->rank($candidates, $user, alphaOverride: 1.0, variant: ExperimentService::VARIANT_A);
    $rankedB = $ranker->rank($candidates, $user, alphaOverride: 1.0, variant: ExperimentService::VARIANT_B);

    expect($rankedA[0]->candidate->postId)->toBe($similarPost->id);
    expect($rankedB[0]->candidate->postId)->toBe($trendingPost->id);
});
