<?php

use App\Models\Like;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\RecommendationLog;
use App\Models\User;
use App\Services\Recommendation\RecommendationService;
use App\Services\Recommendation\SeenFilter;
use App\Services\Recommendation\UserEmbeddingService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);

    Redis::flushdb();
});

function smokeVector(int $dim, int $hotIndex, float $value = 1.0): array
{
    $vec = array_fill(0, $dim, 0.0);
    $vec[$hotIndex] = $value;

    return $vec;
}

function smokeWritePostEmbedding(Post $post, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::table('posts')->where('id', $post->id)->update([
        'embedding' => $literal,
        'embedding_updated_at' => now(),
    ]);
}

function smokeWriteUserEmbedding(User $user, string $column, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::table('users')->where('id', $user->id)->update([
        $column => $literal,
        "{$column}_updated_at" => now(),
    ]);
}

test('full_happy_path_completes_without_errors', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);
    $reportsThreshold = (int) config('recommendation.candidates.reports_threshold', 3);

    config()->set('recommendation.cold_start.interactions_threshold', 5);

    $viewer = User::factory()->create();
    $author = User::factory()->create();

    $favoritePost = Post::factory()->text()->for($author, 'author')->createQuietly();
    $hideTarget = Post::factory()->text()->for($author, 'author')->createQuietly();
    $reportTarget = Post::factory()->text()->for($author, 'author')->createQuietly();

    smokeWritePostEmbedding($favoritePost, smokeVector($dim, 0));
    smokeWritePostEmbedding($hideTarget, smokeVector($dim, 2));
    smokeWritePostEmbedding($reportTarget, smokeVector($dim, 3));

    for ($i = 0; $i < 5; $i++) {
        Like::create([
            'user_id' => $viewer->id,
            'post_id' => $favoritePost->id,
        ]);

        if ($i < 4) {
            Like::where('user_id', $viewer->id)
                ->where('post_id', $favoritePost->id)
                ->delete();
        }
    }

    expect(PostInteraction::where('user_id', $viewer->id)->count())->toBeGreaterThanOrEqual(5);

    app(UserEmbeddingService::class)->refreshLongTerm($viewer->fresh());
    app(UserEmbeddingService::class)->refreshShortTerm($viewer->fresh());

    $viewer->refresh();

    expect($viewer->long_term_embedding)->not->toBeNull()
        ->and($viewer->short_term_embedding)->not->toBeNull();

    $service = app(RecommendationService::class);

    $feed = $service->feedFor($viewer, page: 1, pageSize: 10);

    expect($feed)->not->toBeEmpty()
        ->and($feed->pluck('id')->all())->toContain($favoritePost->id);

    PostInteraction::factory()->hide()->create([
        'user_id' => $viewer->id,
        'post_id' => $hideTarget->id,
        'created_at' => now(),
    ]);

    app(SeenFilter::class)->markSeen($viewer, [$hideTarget->id]);

    $feedAfterHide = $service->feedFor($viewer, page: 1, pageSize: 10);

    expect($feedAfterHide->pluck('id')->all())->not->toContain($hideTarget->id);

    DB::table('posts')->where('id', $reportTarget->id)->update([
        'reports_count' => $reportsThreshold,
    ]);

    $bystander = User::factory()->create();
    smokeWriteUserEmbedding($bystander, 'long_term_embedding', smokeVector($dim, 3));

    for ($i = 0; $i < 5; $i++) {
        PostInteraction::factory()->like()->create([
            'user_id' => $bystander->id,
            'post_id' => $favoritePost->id,
            'created_at' => now()->subDays($i),
        ]);
    }

    $bystander->refresh();

    $bystanderFeed = $service->feedFor($bystander, page: 1, pageSize: 20);

    expect($bystanderFeed->pluck('id')->all())->not->toContain($reportTarget->id);

    expect(RecommendationLog::where('user_id', $viewer->id)->count())
        ->toBeGreaterThan(0);

    $traceLog = RecommendationLog::where('user_id', $viewer->id)
        ->where('post_id', $favoritePost->id)
        ->whereNull('filtered_reason')
        ->first();

    expect($traceLog)->not->toBeNull();

    $exit = Artisan::call('rec:trace', [
        'user_id' => $viewer->id,
        'post_id' => $favoritePost->id,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain((string) $traceLog->request_id);
});
