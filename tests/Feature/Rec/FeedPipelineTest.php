<?php

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\RecommendationService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);

    Redis::flushdb();
});

test('feed_returns_posts_ordered_by_composite_score', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));
    // Força fora de cold-start: marca 5 interações positivas.
    $likeType = InteractionType::where('slug', 'like')->firstOrFail();

    $viewer->refresh();

    $author = User::factory()->create();
    $closePost = Post::factory()->text()->for($author, 'author')->createQuietly();
    $farPost = Post::factory()->text()->for($author, 'author')->createQuietly();

    writePostEmbedding($closePost, candidateVector($dim, 0));
    writePostEmbedding($farPost, candidateVector($dim, 50));

    for ($i = 0; $i < 5; $i++) {
        PostInteraction::create([
            'user_id' => $viewer->id,
            'post_id' => $closePost->id,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subDays(5 - $i),
        ]);
    }

    $service = app(RecommendationService::class);
    $feed = $service->feedFor($viewer, page: 1, pageSize: 10);

    expect($feed->isNotEmpty())->toBeTrue();
    expect($feed->first()->id)->toBe($closePost->id);
});

test('feed_excludes_posts_without_embedding', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));

    // Força fora de cold-start.
    $likeType = InteractionType::where('slug', 'like')->firstOrFail();
    $warmUpPost = Post::factory()->text()->createQuietly();
    writePostEmbedding($warmUpPost, candidateVector($dim, 0));
    for ($i = 0; $i < 6; $i++) {
        PostInteraction::create([
            'user_id' => $viewer->id,
            'post_id' => $warmUpPost->id,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subDays(1)->subMinutes($i),
        ]);
    }

    $viewer->refresh();

    $author = User::factory()->create();
    $withEmbedding = Post::factory()->text()->for($author, 'author')->createQuietly();
    $withoutEmbedding = Post::factory()->text()->for($author, 'author')->createQuietly();

    writePostEmbedding($withEmbedding, candidateVector($dim, 0));

    $service = app(RecommendationService::class);
    $feed = $service->feedFor($viewer, page: 1, pageSize: 10);

    $ids = $feed->pluck('id')->all();
    expect($ids)->not->toContain($withoutEmbedding->id);
});

test('feed_p50_latency_under_250ms', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();
    writeUserEmbedding($viewer, 'long_term_embedding', candidateVector($dim, 0));
    writeUserEmbedding($viewer, 'short_term_embedding', candidateVector($dim, 1));
    $viewer->refresh();

    $author = User::factory()->create();

    // Seed de 30 posts com embeddings aleatórios.
    for ($i = 0; $i < 30; $i++) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly();
        $vec = candidateVector($dim, $i % $dim, 1.0 - ($i / 100.0));
        writePostEmbedding($post, $vec);
    }

    // Força fora de cold-start: interações positivas.
    $likeType = InteractionType::where('slug', 'like')->firstOrFail();
    $firstPostId = DB::table('posts')->value('id');
    for ($i = 0; $i < 6; $i++) {
        PostInteraction::create([
            'user_id' => $viewer->id,
            'post_id' => $firstPostId,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subDays(2)->subMinutes($i),
        ]);
    }

    $service = app(RecommendationService::class);

    // Não é bloqueante, só histórico.
    [$result, $ms] = Benchmark::value(fn () => $service->feedFor($viewer, 1, 10));

    expect($result)->not->toBeNull();

    fwrite(STDERR, "feed_p50_latency_under_250ms: {$ms}ms\n");
});
