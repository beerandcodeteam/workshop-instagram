<?php

use App\Jobs\PurgeRecommendationLogsJob;
use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\RecommendationLog;
use App\Models\User;
use App\Services\Recommendation\RecommendationService;
use App\Services\Recommendation\SeenFilter;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);

    Redis::flushdb();
});

function traceLoggerVector(int $dim, int $hotIndex, float $value = 1.0): array
{
    $vec = array_fill(0, $dim, 0.0);
    $vec[$hotIndex] = $value;

    return $vec;
}

function traceLoggerWritePostEmbedding(Post $post, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::table('posts')->where('id', $post->id)->update([
        'embedding' => $literal,
        'embedding_updated_at' => now(),
    ]);
}

function traceLoggerWriteUserEmbedding(User $user, string $column, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::table('users')->where('id', $user->id)->update([
        $column => $literal,
        "{$column}_updated_at" => now(),
    ]);
}

function traceLoggerSeedWarmUser(int $extraInteractions = 6): array
{
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    traceLoggerWriteUserEmbedding($viewer, 'long_term_embedding', traceLoggerVector($dim, 0));

    $likeType = InteractionType::where('slug', 'like')->firstOrFail();

    $warmUpPost = Post::factory()->text()->createQuietly();
    traceLoggerWritePostEmbedding($warmUpPost, traceLoggerVector($dim, 0));

    for ($i = 0; $i < $extraInteractions; $i++) {
        PostInteraction::create([
            'user_id' => $viewer->id,
            'post_id' => $warmUpPost->id,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subDays(5)->subMinutes($i),
        ]);
    }

    $viewer->refresh();

    return [$viewer, $dim];
}

test('feed_request_emits_n_traces', function () {
    [$viewer, $dim] = traceLoggerSeedWarmUser();
    $author = User::factory()->create();

    for ($i = 0; $i < 4; $i++) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly();
        traceLoggerWritePostEmbedding($post, traceLoggerVector($dim, $i));
    }

    $feed = app(RecommendationService::class)->feedFor($viewer, page: 1, pageSize: 10);

    expect($feed->isNotEmpty())->toBeTrue();

    $traces = RecommendationLog::where('user_id', $viewer->id)
        ->whereNull('filtered_reason')
        ->get();

    expect($traces)->toHaveCount($feed->count());

    $requestIds = $traces->pluck('request_id')->unique();
    expect($requestIds)->toHaveCount(1);
});

test('trace_includes_source_score_and_final_position', function () {
    [$viewer, $dim] = traceLoggerSeedWarmUser();
    $author = User::factory()->create();

    for ($i = 0; $i < 3; $i++) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly();
        traceLoggerWritePostEmbedding($post, traceLoggerVector($dim, $i));
    }

    $feed = app(RecommendationService::class)->feedFor($viewer, page: 1, pageSize: 10);

    expect($feed->isNotEmpty())->toBeTrue();

    $firstPostId = $feed->first()->id;

    $trace = RecommendationLog::where('user_id', $viewer->id)
        ->where('post_id', $firstPostId)
        ->whereNull('filtered_reason')
        ->first();

    expect($trace)->not->toBeNull();
    expect($trace->recommendation_source_id)->not->toBeNull();
    expect((float) $trace->score)->toBeGreaterThanOrEqual(0.0);
    expect($trace->rank_position)->toBe(0);
    expect($trace->scores_breakdown)->toBeArray();
    expect($trace->scores_breakdown)->toHaveKey('source');
});

test('traces_older_than_7_days_are_purged', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->text()->for($author, 'author')->createQuietly();

    DB::table('recommendation_logs')->insert([
        [
            'request_id' => (string) Str::uuid(),
            'user_id' => $viewer->id,
            'post_id' => $post->id,
            'recommendation_source_id' => null,
            'score' => 0.5,
            'rank_position' => 0,
            'scores_breakdown' => null,
            'created_at' => now()->subDays(10),
        ],
        [
            'request_id' => (string) Str::uuid(),
            'user_id' => $viewer->id,
            'post_id' => $post->id,
            'recommendation_source_id' => null,
            'score' => 0.6,
            'rank_position' => 0,
            'scores_breakdown' => null,
            'created_at' => now()->subDays(3),
        ],
    ]);

    expect(RecommendationLog::count())->toBe(2);

    (new PurgeRecommendationLogsJob)->handle();

    expect(RecommendationLog::count())->toBe(1);
    expect(RecommendationLog::first()->created_at->diffInDays(now()))->toBeLessThan(7);
});

test('filtered_candidate_is_logged_with_filtered_reason', function () {
    [$viewer, $dim] = traceLoggerSeedWarmUser();
    $author = User::factory()->create();

    $kept = Post::factory()->text()->for($author, 'author')->createQuietly();
    $alreadySeen = Post::factory()->text()->for($author, 'author')->createQuietly();

    traceLoggerWritePostEmbedding($kept, traceLoggerVector($dim, 0));
    traceLoggerWritePostEmbedding($alreadySeen, traceLoggerVector($dim, 0));

    app(SeenFilter::class)->markSeen($viewer, [$alreadySeen->id]);

    app(RecommendationService::class)->feedFor($viewer, page: 1, pageSize: 10);

    $filteredTrace = RecommendationLog::where('user_id', $viewer->id)
        ->where('post_id', $alreadySeen->id)
        ->whereNotNull('filtered_reason')
        ->first();

    expect($filteredTrace)->not->toBeNull();
    expect($filteredTrace->filtered_reason)->toBe('already_seen');
});
