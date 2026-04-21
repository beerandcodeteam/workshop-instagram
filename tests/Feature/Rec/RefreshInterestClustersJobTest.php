<?php

use App\Jobs\RefreshInterestClustersJob;
use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use App\Models\UserInterestCluster;
use App\Services\Recommendation\InterestClusterService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);
});

function clusterVector(int $dim, array $hotIndices, float $value = 1.0): array
{
    $vec = array_fill(0, $dim, 0.0);
    foreach ($hotIndices as $i) {
        $vec[$i] = $value;
    }

    return $vec;
}

function setClusterPostEmbedding(int $postId, array $vector): void
{
    DB::table('posts')->where('id', $postId)->update([
        'embedding' => '['.implode(',', $vector).']',
        'embedding_updated_at' => now(),
        'embedding_model_id' => DB::table('embedding_models')->where('slug', 'gemini-embedding-2-preview')->value('id'),
    ]);
}

function makeClusterInteraction(User $user, Post $post, string $slug = 'like', $createdAt = null): PostInteraction
{
    $type = InteractionType::where('slug', $slug)->firstOrFail();

    return PostInteraction::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'interaction_type_id' => $type->id,
        'weight' => $type->default_weight,
        'created_at' => $createdAt ?? now()->subDays(2),
    ]);
}

/**
 * Cria N posts ao redor de um centro (vetor com algumas posições "quentes"),
 * gerando uma interação positiva do usuário em cada um deles. Para tornar os
 * pontos linearmente separáveis e o k-means converge, cada cluster usa
 * dimensões "quentes" exclusivas.
 */
function seedClusterAround(User $user, int $dim, array $hotIndices, int $count, float $jitter = 0.0): void
{
    for ($i = 0; $i < $count; $i++) {
        $post = Post::factory()->text()->createQuietly();

        $vec = clusterVector($dim, $hotIndices);

        if ($jitter > 0.0) {
            // Adiciona pequena variação para evitar pontos idênticos.
            foreach (range(0, 4) as $offset) {
                $idx = ($hotIndices[0] + 100 + $offset) % $dim;
                $vec[$idx] = ($i % 7) * $jitter / 6.0;
            }
        }

        setClusterPostEmbedding($post->id, $vec);
        makeClusterInteraction($user, $post, 'like', now()->subDays(rand(1, 30)));
    }
}

test('job_creates_3_to_7_clusters_per_eligible_user', function () {
    $user = User::factory()->create();
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    seedClusterAround($user, $dim, [0, 1], 12, 0.05);
    seedClusterAround($user, $dim, [200, 201], 12, 0.05);
    seedClusterAround($user, $dim, [800, 801], 12, 0.05);

    (new RefreshInterestClustersJob)->handle(app(InterestClusterService::class));

    $count = UserInterestCluster::where('user_id', $user->id)->count();

    expect($count)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(7);
});

test('job_skips_users_below_interaction_threshold', function () {
    $user = User::factory()->create();
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    seedClusterAround($user, $dim, [0, 1], 5);
    seedClusterAround($user, $dim, [200, 201], 5);

    (new RefreshInterestClustersJob)->handle(app(InterestClusterService::class));

    expect(UserInterestCluster::where('user_id', $user->id)->count())->toBe(0);
});

test('cluster_weights_sum_to_1', function () {
    $user = User::factory()->create();
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    seedClusterAround($user, $dim, [0, 1], 12);
    seedClusterAround($user, $dim, [200, 201], 12);
    seedClusterAround($user, $dim, [800, 801], 12);

    (new RefreshInterestClustersJob)->handle(app(InterestClusterService::class));

    $sum = (float) UserInterestCluster::where('user_id', $user->id)->sum('weight');

    expect($sum)->toBeGreaterThan(0.99)->toBeLessThan(1.01);
});

test('sample_count_is_populated', function () {
    $user = User::factory()->create();
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    seedClusterAround($user, $dim, [0, 1], 12);
    seedClusterAround($user, $dim, [200, 201], 12);
    seedClusterAround($user, $dim, [800, 801], 12);

    (new RefreshInterestClustersJob)->handle(app(InterestClusterService::class));

    $clusters = UserInterestCluster::where('user_id', $user->id)->get();

    expect($clusters)->not->toBeEmpty();

    foreach ($clusters as $cluster) {
        expect($cluster->sample_count)->toBeGreaterThan(0);
    }

    expect($clusters->sum('sample_count'))->toBe(36);
});

test('replaces_not_updates_existing_rows', function () {
    $user = User::factory()->create();
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    seedClusterAround($user, $dim, [0, 1], 12);
    seedClusterAround($user, $dim, [200, 201], 12);
    seedClusterAround($user, $dim, [800, 801], 12);

    $service = app(InterestClusterService::class);

    $service->computeFor($user);

    $firstIds = UserInterestCluster::where('user_id', $user->id)->pluck('id')->all();
    expect($firstIds)->not->toBeEmpty();

    $service->computeFor($user);

    $secondIds = UserInterestCluster::where('user_id', $user->id)->pluck('id')->all();

    expect($secondIds)->not->toBeEmpty();
    expect(array_intersect($firstIds, $secondIds))->toBeEmpty();
});
