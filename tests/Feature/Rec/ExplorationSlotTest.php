<?php

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\Candidate;
use App\Services\Recommendation\ExplorationSlot;
use App\Services\Recommendation\RankedCandidate;
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

function rankedForExplore(int $postId, int $authorId, string $source, float $score): RankedCandidate
{
    return new RankedCandidate(
        candidate: Candidate::make($postId, $source, $score),
        authorId: $authorId,
        score: $score,
        scoresBreakdown: ['final' => $score, 'source' => $source],
        embedding: [],
    );
}

test('feed_includes_at_least_one_exploration_post_per_10', function () {
    $ranked = [];
    for ($i = 0; $i < 10; $i++) {
        $ranked[] = rankedForExplore($i + 1, 10, 'ann_long_term', 0.9 - $i * 0.01);
    }
    for ($i = 0; $i < 5; $i++) {
        $postId = 100 + $i;
        $ranked[] = rankedForExplore($postId, 20, 'explore', 0.5 - $i * 0.01);
    }

    $slot = app(ExplorationSlot::class);
    $out = $slot->enforce($ranked, windowSize: 10, minimum: 1);

    $first10 = array_slice($out, 0, 10);
    $sources = array_map(fn ($r) => $r->candidate->source, $first10);

    expect(in_array('explore', $sources, true))->toBeTrue();
});

test('exploration_source_is_tagged_in_ranking_logs', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));

    // Promove para fora do cold-start.
    $likeType = InteractionType::where('slug', 'like')->firstOrFail();
    $warmUp = Post::factory()->text()->createQuietly();
    writePostEmbedding($warmUp, candidateVector($dim, 5));
    for ($i = 0; $i < 6; $i++) {
        PostInteraction::create([
            'user_id' => $viewer->id,
            'post_id' => $warmUp->id,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subDays(1)->subMinutes($i),
        ]);
    }
    $viewer->refresh();

    // Cria autor novo (nunca interagido) com post recente.
    $newAuthor = User::factory()->create();
    $explorePost = Post::factory()->text()->for($newAuthor, 'author')->createQuietly();
    writePostEmbedding($explorePost, candidateVector($dim, 3));

    $service = app(RecommendationService::class);
    $feed = $service->feedFor($viewer, page: 1, pageSize: 10);

    expect($feed->pluck('id')->all())->toContain($explorePost->id);

    $log = DB::table('recommendation_logs')
        ->join('recommendation_sources', 'recommendation_sources.id', '=', 'recommendation_logs.recommendation_source_id')
        ->where('recommendation_logs.post_id', $explorePost->id)
        ->where('recommendation_logs.user_id', $viewer->id)
        ->select('recommendation_sources.slug as source_slug')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->source_slug)->toBe('explore');
});
