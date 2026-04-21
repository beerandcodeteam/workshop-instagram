<?php

use App\Jobs\RefreshShortTermEmbeddingJob;
use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\UserEmbeddingService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

function setShortTermPostEmbedding(int $postId, array $vector): void
{
    DB::table('posts')->where('id', $postId)->update([
        'embedding' => '['.implode(',', $vector).']',
        'embedding_updated_at' => now(),
        'embedding_model_id' => DB::table('embedding_models')->where('slug', 'gemini-embedding-2-preview')->value('id'),
    ]);
}

function makeShortTermInteraction(User $user, Post $post, string $slug, $createdAt): void
{
    $type = InteractionType::where('slug', $slug)->firstOrFail();

    PostInteraction::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'interaction_type_id' => $type->id,
        'weight' => $type->default_weight,
        'created_at' => $createdAt,
    ]);
}

test('job_populates_short_term_from_last_48h_interactions', function () {
    $user = User::factory()->create();

    $recentPost = Post::factory()->text()->createQuietly();
    $oldPost = Post::factory()->text()->createQuietly();

    $dims = (int) config('services.gemini.embedding.dimensions', 1536);

    $recentVec = array_fill(0, $dims, 0.0);
    $recentVec[0] = 1.0;
    setShortTermPostEmbedding($recentPost->id, $recentVec);

    $oldVec = array_fill(0, $dims, 0.0);
    $oldVec[1] = 1.0;
    setShortTermPostEmbedding($oldPost->id, $oldVec);

    makeShortTermInteraction($user, $recentPost, 'comment', now()->subHours(2));
    makeShortTermInteraction($user, $oldPost, 'comment', now()->subDays(5));

    Redis::del("rec:user:{$user->id}:short_term");

    (new RefreshShortTermEmbeddingJob($user->id))->handle(app(UserEmbeddingService::class));

    $vector = $user->fresh()->short_term_embedding;

    expect($vector)->not->toBeNull();
    // dimensão 0 (recente, 48h window) deve dominar — a antiga está fora da janela.
    expect($vector[0])->toBeGreaterThan(0.5);
    expect($vector[1])->toBeLessThan(0.1);
});

test('job_caches_to_redis', function () {
    $user = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();
    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dims, 0.0);
    $vec[0] = 1.0;
    setShortTermPostEmbedding($post->id, $vec);

    makeShortTermInteraction($user, $post, 'comment', now()->subMinutes(10));

    $cacheKey = "rec:user:{$user->id}:short_term";
    Redis::del($cacheKey);

    (new RefreshShortTermEmbeddingJob($user->id))->handle(app(UserEmbeddingService::class));

    expect((bool) Redis::exists($cacheKey))->toBeTrue();
});

test('debounce_drops_concurrent_dispatches', function () {
    Queue::fake();

    $user = User::factory()->create();

    Redis::del("rec:user:{$user->id}:st_lock");

    RefreshShortTermEmbeddingJob::dispatchDebounced($user->id);
    RefreshShortTermEmbeddingJob::dispatchDebounced($user->id);
    RefreshShortTermEmbeddingJob::dispatchDebounced($user->id);

    Queue::assertPushed(RefreshShortTermEmbeddingJob::class, 1);
});

test('short_term_is_null_when_no_recent_interaction', function () {
    $user = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();
    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dims, 0.0);
    $vec[0] = 1.0;
    setShortTermPostEmbedding($post->id, $vec);

    // Interação fora da janela de 48h.
    makeShortTermInteraction($user, $post, 'comment', now()->subDays(5));

    DB::table('users')->where('id', $user->id)->update([
        'short_term_embedding' => '['.implode(',', array_fill(0, $dims, 0.5)).']',
    ]);

    Redis::del("rec:user:{$user->id}:short_term");

    (new RefreshShortTermEmbeddingJob($user->id))->handle(app(UserEmbeddingService::class));

    expect($user->fresh()->short_term_embedding)->toBeNull();
});
