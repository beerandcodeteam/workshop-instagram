<?php

use App\Models\Post;
use App\Models\User;
use App\Services\Recommendation\CandidateGenerator;
use App\Services\Recommendation\SeenFilter;
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

test('seen_posts_are_not_returned_in_next_feed', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));
    $viewer->refresh();

    $author = User::factory()->create();
    $postA = Post::factory()->text()->for($author, 'author')->createQuietly();
    $postB = Post::factory()->text()->for($author, 'author')->createQuietly();

    writePostEmbedding($postA, candidateVector($dim, 0));
    writePostEmbedding($postB, candidateVector($dim, 0));

    app(SeenFilter::class)->markSeen($viewer, [$postA->id]);

    $candidates = app(CandidateGenerator::class)->generate($viewer);

    expect($candidates)->not->toHaveKey($postA->id);
    expect($candidates)->toHaveKey($postB->id);
});

test('seen_ttl_is_48_hours', function () {
    $expectedTtl = 48 * 3600;
    config()->set('recommendation.seen.ttl_seconds', $expectedTtl);

    $viewer = User::factory()->create();

    $seen = app(SeenFilter::class);
    $seen->markSeen($viewer, [1, 2, 3]);

    $ttl = Redis::ttl($seen->key($viewer));

    expect($ttl)->toBeGreaterThan($expectedTtl - 5);
    expect($ttl)->toBeLessThanOrEqual($expectedTtl);
});
