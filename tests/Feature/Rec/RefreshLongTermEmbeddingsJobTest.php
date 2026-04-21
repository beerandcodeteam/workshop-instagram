<?php

use App\Jobs\RefreshLongTermEmbeddingsJob;
use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Services\Recommendation\UserEmbeddingService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

function setPostEmbedding(int $postId, array $vector): void
{
    DB::table('posts')->where('id', $postId)->update([
        'embedding' => '['.implode(',', $vector).']',
        'embedding_updated_at' => now(),
        'embedding_model_id' => DB::table('embedding_models')->where('slug', 'gemini-embedding-2-preview')->value('id'),
    ]);
}

function makeInteraction(User $user, Post $post, string $slug, $createdAt): PostInteraction
{
    $type = InteractionType::where('slug', $slug)->firstOrFail();

    return PostInteraction::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'interaction_type_id' => $type->id,
        'weight' => $type->default_weight,
        'created_at' => $createdAt,
    ]);
}

test('job_populates_long_term_embedding_for_users_with_activity', function () {
    $user = User::factory()->create();

    $postA = Post::factory()->text()->createQuietly();
    $postB = Post::factory()->text()->createQuietly();

    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vecA = array_fill(0, $dims, 0.0);
    $vecA[0] = 1.0;
    $vecB = array_fill(0, $dims, 0.0);
    $vecB[1] = 1.0;

    setPostEmbedding($postA->id, $vecA);
    setPostEmbedding($postB->id, $vecB);

    makeInteraction($user, $postA, 'like', now()->subDays(2));
    makeInteraction($user, $postB, 'like', now()->subDays(2));
    makeInteraction($user, $postA, 'comment', now()->subDays(1));

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    $fresh = $user->fresh();

    expect($fresh->long_term_embedding)->not->toBeNull();
    expect($fresh->long_term_embedding)->toHaveCount($dims);
});

test('job_respects_180_day_window', function () {
    $user = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();
    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dims, 0.0);
    $vec[0] = 1.0;
    setPostEmbedding($post->id, $vec);

    makeInteraction($user, $post, 'like', now()->subDays(200));

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    expect($user->fresh()->long_term_embedding)->toBeNull();
});

test('job_applies_weighted_mean_with_decay', function () {
    $user = User::factory()->create();

    $recentPost = Post::factory()->text()->createQuietly();
    $oldPost = Post::factory()->text()->createQuietly();

    $dims = (int) config('services.gemini.embedding.dimensions', 1536);

    $recentVec = array_fill(0, $dims, 0.0);
    $recentVec[0] = 1.0;

    $oldVec = array_fill(0, $dims, 0.0);
    $oldVec[1] = 1.0;

    setPostEmbedding($recentPost->id, $recentVec);
    setPostEmbedding($oldPost->id, $oldVec);

    // Interaction recente fraca (like, w=1.0) e antiga forte (share, w=2.0).
    // Mas como está há 90d (3 half-lives), o peso efetivo cai para ~0.25.
    // Adicionamos múltiplos likes recentes para garantir threshold > 2.0.
    makeInteraction($user, $recentPost, 'like', now()->subHours(1));
    makeInteraction($user, $recentPost, 'like', now()->subHours(2));
    makeInteraction($user, $recentPost, 'like', now()->subHours(3));
    makeInteraction($user, $oldPost, 'share', now()->subDays(90));

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    $vector = $user->fresh()->long_term_embedding;

    expect($vector)->not->toBeNull();
    // dimensão 0 (recente) deve dominar a dimensão 1 (antiga decaída).
    expect($vector[0])->toBeGreaterThan($vector[1]);
});

test('job_writes_null_below_threshold', function () {
    $user = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();
    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dims, 0.0);
    $vec[0] = 1.0;
    setPostEmbedding($post->id, $vec);

    // Apenas uma view (peso 0.5) — abaixo do threshold padrão (2.0).
    makeInteraction($user, $post, 'view', now()->subHours(1));

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    expect($user->fresh()->long_term_embedding)->toBeNull();
});

test('job_skips_inactive_users', function () {
    $activeUser = User::factory()->create();
    $inactiveUser = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();
    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dims, 0.0);
    $vec[0] = 1.0;
    setPostEmbedding($post->id, $vec);

    foreach (range(1, 3) as $_) {
        makeInteraction($activeUser, $post, 'like', now()->subDay());
    }

    foreach (range(1, 3) as $_) {
        makeInteraction($inactiveUser, $post, 'like', now()->subDays(45));
    }

    DB::table('users')->where('id', $inactiveUser->id)->update([
        'long_term_embedding' => null,
        'long_term_embedding_updated_at' => null,
    ]);

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    expect($activeUser->fresh()->long_term_embedding)->not->toBeNull();
    expect($inactiveUser->fresh()->long_term_embedding_updated_at)->toBeNull();
});

test('long_term_embedding_updated_at_is_touched', function () {
    $user = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();
    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dims, 0.0);
    $vec[0] = 1.0;
    setPostEmbedding($post->id, $vec);

    foreach (range(1, 3) as $_) {
        makeInteraction($user, $post, 'like', now()->subHours(2));
    }

    expect($user->fresh()->long_term_embedding_updated_at)->toBeNull();

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    expect($user->fresh()->long_term_embedding_updated_at)->not->toBeNull();
});
