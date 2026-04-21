<?php

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\Candidate;
use App\Services\Recommendation\Ranker;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

test('score_combines_lt_and_st_with_alpha', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    writeUserEmbedding($user, 'long_term_embedding', candidateVector($dim, 0));
    writeUserEmbedding($user, 'short_term_embedding', candidateVector($dim, 1));
    $user->refresh();

    $postAlignedLt = Post::factory()->text()->createQuietly();
    $postAlignedSt = Post::factory()->text()->createQuietly();

    writePostEmbedding($postAlignedLt, candidateVector($dim, 0));
    writePostEmbedding($postAlignedSt, candidateVector($dim, 1));

    $ranker = app(Ranker::class);

    $candidates = [
        $postAlignedLt->id => Candidate::make($postAlignedLt->id, 'ann_long_term', 1.0),
        $postAlignedSt->id => Candidate::make($postAlignedSt->id, 'ann_short_term', 1.0),
    ];

    // alpha=1.0 deveria priorizar LT.
    $rankedLt = $ranker->rank($candidates, $user, alphaOverride: 1.0);
    expect($rankedLt[0]->candidate->postId)->toBe($postAlignedLt->id);

    // alpha=0.0 deveria priorizar ST.
    $rankedSt = $ranker->rank($candidates, $user, alphaOverride: 0.0);
    expect($rankedSt[0]->candidate->postId)->toBe($postAlignedSt->id);
});

test('score_penalizes_posts_close_to_avoid', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    writeUserEmbedding($user, 'long_term_embedding', candidateVector($dim, 0));
    writeUserEmbedding($user, 'avoid_embedding', candidateVector($dim, 5));
    $user->refresh();

    $ranker = app(Ranker::class);

    $cleanEmbedding = candidateVector($dim, 0);
    $avoidedEmbedding = candidateVector($dim, 5);
    $avoidedEmbedding[0] = 0.5;

    [$cleanScore] = $ranker->score($cleanEmbedding, $user, alphaOverride: 1.0, recencyBoost: 0.0);
    [$avoidedScore] = $ranker->score($avoidedEmbedding, $user, alphaOverride: 1.0, recencyBoost: 0.0);

    expect($cleanScore)->toBeGreaterThan($avoidedScore);
});

test('score_falls_back_when_lt_is_null', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    writeUserEmbedding($user, 'short_term_embedding', candidateVector($dim, 2));
    $user->refresh();

    $postAligned = Post::factory()->text()->createQuietly();
    $postFar = Post::factory()->text()->createQuietly();

    writePostEmbedding($postAligned, candidateVector($dim, 2));
    writePostEmbedding($postFar, candidateVector($dim, 100));

    $ranker = app(Ranker::class);

    $candidates = [
        $postAligned->id => Candidate::make($postAligned->id, 'ann_short_term', 1.0),
        $postFar->id => Candidate::make($postFar->id, 'ann_short_term', 0.2),
    ];

    $ranked = $ranker->rank($candidates, $user);

    expect($ranked[0]->candidate->postId)->toBe($postAligned->id);
    expect($ranked[0]->scoresBreakdown['cos_lt'])->toBe(0.0);
    expect($ranked[0]->scoresBreakdown['cos_st'])->toBeGreaterThan(0.0);
});

test('recency_boost_decays_with_half_life_6h', function () {
    config()->set('recommendation.score.recency_half_life_hours', 6);

    $ranker = app(Ranker::class);

    $now = now();
    $boostNow = $ranker->recencyBoost($now);
    $boost6h = $ranker->recencyBoost($now->copy()->subHours(6));
    $boost12h = $ranker->recencyBoost($now->copy()->subHours(12));

    expect($boostNow)->toBeGreaterThan($boost6h);
    expect($boost6h)->toBeGreaterThan($boost12h);
    expect(abs($boost6h - 0.5))->toBeLessThan(0.01);
    expect(abs($boost12h - 0.25))->toBeLessThan(0.01);
});

test('alpha_shifts_toward_short_term_when_session_is_active', function () {
    config()->set('recommendation.score.alpha_default', 0.8);
    config()->set('recommendation.score.alpha_active_session', 0.3);
    config()->set('recommendation.score.alpha_active_threshold', 5);

    $likeType = InteractionType::where('slug', 'like')->firstOrFail();

    $quietUser = User::factory()->create();
    $activeUser = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();

    for ($i = 0; $i < 6; $i++) {
        PostInteraction::create([
            'user_id' => $activeUser->id,
            'post_id' => $post->id,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subMinutes($i + 1),
        ]);
    }

    $ranker = app(Ranker::class);

    expect($ranker->resolveAlpha($quietUser))->toBe(0.8);
    expect($ranker->resolveAlpha($activeUser))->toBe(0.3);
});
