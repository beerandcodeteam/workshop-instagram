<?php

use App\Models\Post;
use App\Models\User;
use App\Services\Recommendation\CandidateGenerator;
use App\Services\Recommendation\SeenFilter;
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

function candidateVector(int $dim, int $hotIndex, float $value = 1.0): array
{
    $vec = array_fill(0, $dim, 0.0);
    $vec[$hotIndex] = $value;

    return $vec;
}

function writePostEmbedding(Post $post, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::table('posts')->where('id', $post->id)->update([
        'embedding' => $literal,
        'embedding_updated_at' => now(),
    ]);
}

function writeUserEmbedding(User $user, string $column, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::table('users')->where('id', $user->id)->update([
        $column => $literal,
        "{$column}_updated_at" => now(),
    ]);
}

test('annByLongTerm_returns_closest_posts_by_cosine', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));
    $viewer->refresh();

    $closePost = Post::factory()->text()->createQuietly();
    $farPost = Post::factory()->text()->createQuietly();

    writePostEmbedding($closePost, candidateVector($dim, 0));
    writePostEmbedding($farPost, candidateVector($dim, 10));

    $generator = app(CandidateGenerator::class);
    $candidates = $generator->annByLongTerm($viewer, 10);

    expect($candidates)->toHaveCount(2);
    expect($candidates[0]->postId)->toBe($closePost->id);
    expect($candidates[0]->source)->toBe('ann_long_term');
    expect($candidates[0]->sourceScore)->toBeGreaterThan($candidates[1]->sourceScore);
});

test('annByShortTerm_returns_closest_posts_to_short_term', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'short_term_embedding', candidateVector($dim, 3));
    $viewer->refresh();

    $closePost = Post::factory()->text()->createQuietly();
    $farPost = Post::factory()->text()->createQuietly();

    writePostEmbedding($closePost, candidateVector($dim, 3));
    writePostEmbedding($farPost, candidateVector($dim, 20));

    $generator = app(CandidateGenerator::class);
    $candidates = $generator->annByShortTerm($viewer, 10);

    expect($candidates)->toHaveCount(2);
    expect($candidates[0]->postId)->toBe($closePost->id);
    expect($candidates[0]->source)->toBe('ann_short_term');
});

test('trending_reads_from_redis_sorted_set', function () {
    $viewer = User::factory()->create();

    $postA = Post::factory()->text()->createQuietly();
    $postB = Post::factory()->text()->createQuietly();

    $key = config('recommendation.trending.redis_key');
    Redis::zadd($key, 10.0, $postA->id);
    Redis::zadd($key, 5.0, $postB->id);

    $generator = app(CandidateGenerator::class);
    $candidates = $generator->trending($viewer, 10);

    expect($candidates)->toHaveCount(2);
    expect($candidates[0]->postId)->toBe($postA->id);
    expect($candidates[0]->source)->toBe('trending');
    expect($candidates[0]->sourceScore)->toBe(10.0);
});

test('exploration_excludes_posts_from_authors_already_seen', function () {
    $viewer = User::factory()->create();
    $knownAuthor = User::factory()->create();
    $unknownAuthor = User::factory()->create();

    $knownPost = Post::factory()->text()->for($knownAuthor, 'author')->createQuietly();
    $unknownPost = Post::factory()->text()->for($unknownAuthor, 'author')->createQuietly();

    $dim = (int) config('services.gemini.embedding.dimensions', 1536);
    writePostEmbedding($knownPost, candidateVector($dim, 0));
    writePostEmbedding($unknownPost, candidateVector($dim, 0));

    // Marca known como já visto via interaction.
    makeTrendingInteraction($viewer, $knownPost, 'like', now()->subMinutes(5));

    $generator = app(CandidateGenerator::class);
    $candidates = $generator->exploration($viewer, 10);

    $ids = array_map(static fn ($c) => $c->postId, $candidates);

    expect($ids)->toContain($unknownPost->id);
    expect($ids)->not->toContain($knownPost->id);
});

test('generate_dedups_across_sources', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));
    writeUserEmbedding($viewer, 'short_term_embedding', candidateVector($dim, 0));
    $viewer->refresh();

    $author = User::factory()->create();
    $post = Post::factory()->text()->for($author, 'author')->createQuietly();
    writePostEmbedding($post, candidateVector($dim, 0));

    $key = config('recommendation.trending.redis_key');
    Redis::zadd($key, 10.0, $post->id);

    $generator = app(CandidateGenerator::class);
    $candidates = $generator->generate($viewer);

    expect($candidates)->toHaveCount(1);
    expect(array_keys($candidates))->toBe([$post->id]);
});

test('generate_filters_already_seen', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));
    $viewer->refresh();

    $author = User::factory()->create();
    $visiblePost = Post::factory()->text()->for($author, 'author')->createQuietly();
    $seenPost = Post::factory()->text()->for($author, 'author')->createQuietly();

    writePostEmbedding($visiblePost, candidateVector($dim, 0));
    writePostEmbedding($seenPost, candidateVector($dim, 0));

    app(SeenFilter::class)
        ->markSeen($viewer, [$seenPost->id]);

    $generator = app(CandidateGenerator::class);
    $candidates = $generator->generate($viewer);

    expect($candidates)->toHaveKey($visiblePost->id);
    expect($candidates)->not->toHaveKey($seenPost->id);
});

test('generate_filters_reports_over_threshold', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);
    config()->set('recommendation.candidates.reports_threshold', 3);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));
    $viewer->refresh();

    $author = User::factory()->create();
    $cleanPost = Post::factory()->text()->for($author, 'author')->createQuietly();
    $reportedPost = Post::factory()->text()->for($author, 'author')->createQuietly();

    writePostEmbedding($cleanPost, candidateVector($dim, 0));
    writePostEmbedding($reportedPost, candidateVector($dim, 0));

    $reportedPost->forceFill(['reports_count' => 5])->save();

    $generator = app(CandidateGenerator::class);
    $candidates = $generator->generate($viewer);

    expect($candidates)->toHaveKey($cleanPost->id);
    expect($candidates)->not->toHaveKey($reportedPost->id);
});

test('generate_filters_own_posts', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));
    $viewer->refresh();

    $other = User::factory()->create();
    $ownPost = Post::factory()->text()->for($viewer, 'author')->createQuietly();
    $otherPost = Post::factory()->text()->for($other, 'author')->createQuietly();

    writePostEmbedding($ownPost, candidateVector($dim, 0));
    writePostEmbedding($otherPost, candidateVector($dim, 0));

    $generator = app(CandidateGenerator::class);
    $candidates = $generator->generate($viewer);

    expect($candidates)->toHaveKey($otherPost->id);
    expect($candidates)->not->toHaveKey($ownPost->id);
});
