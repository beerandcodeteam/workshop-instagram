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

function setAvoidPostEmbedding(int $postId, array $vector): void
{
    DB::table('posts')->where('id', $postId)->update([
        'embedding' => '['.implode(',', $vector).']',
        'embedding_updated_at' => now(),
        'embedding_model_id' => DB::table('embedding_models')->where('slug', 'gemini-embedding-2-preview')->value('id'),
    ]);
}

function makeAvoidInteraction(User $user, Post $post, string $slug, $createdAt): void
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

test('avoid_embedding_is_populated_from_hides_and_reports', function () {
    $user = User::factory()->create();

    $hidePost = Post::factory()->text()->createQuietly();
    $reportPost = Post::factory()->text()->createQuietly();

    $dims = (int) config('services.gemini.embedding.dimensions', 1536);

    $hideVec = array_fill(0, $dims, 0.0);
    $hideVec[0] = 1.0;
    setAvoidPostEmbedding($hidePost->id, $hideVec);

    $reportVec = array_fill(0, $dims, 0.0);
    $reportVec[1] = 1.0;
    setAvoidPostEmbedding($reportPost->id, $reportVec);

    // Disparar uma interação positiva também, para entrar no loop "ativo nos últimos 7d".
    makeAvoidInteraction($user, $hidePost, 'like', now()->subHours(2));

    makeAvoidInteraction($user, $hidePost, 'hide', now()->subHours(3));
    makeAvoidInteraction($user, $reportPost, 'report', now()->subHours(4));

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    $fresh = $user->fresh();

    expect($fresh->avoid_embedding)->not->toBeNull();
    expect($fresh->avoid_embedding)->toHaveCount($dims);
    expect($fresh->avoid_embedding_updated_at)->not->toBeNull();
});

test('avoid_is_null_for_users_without_negative_signals', function () {
    $user = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();
    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dims, 0.0);
    $vec[0] = 1.0;
    setAvoidPostEmbedding($post->id, $vec);

    foreach (range(1, 3) as $_) {
        makeAvoidInteraction($user, $post, 'like', now()->subHours(2));
    }

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    expect($user->fresh()->avoid_embedding)->toBeNull();
});

test('skip_fast_signals_contribute_to_avoid', function () {
    $user = User::factory()->create();

    $post = Post::factory()->text()->createQuietly();

    $dims = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dims, 0.0);
    $vec[0] = 1.0;
    setAvoidPostEmbedding($post->id, $vec);

    // gatilho de atividade
    makeAvoidInteraction($user, $post, 'like', now()->subHours(1));

    // skip_fast: peso -0.3, half-life 6h. Várias amostras recentes para ultrapassar threshold (1.0).
    foreach (range(1, 5) as $_) {
        makeAvoidInteraction($user, $post, 'skip_fast', now()->subMinutes(30));
    }

    (new RefreshLongTermEmbeddingsJob)->handle(app(UserEmbeddingService::class));

    expect($user->fresh()->avoid_embedding)->not->toBeNull();
});
