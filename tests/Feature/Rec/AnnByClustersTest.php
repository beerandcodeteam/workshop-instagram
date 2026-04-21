<?php

use App\Models\Post;
use App\Models\User;
use App\Models\UserInterestCluster;
use App\Services\Recommendation\CandidateGenerator;
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

function annHotVector(int $dim, int $hotIndex): array
{
    $vec = array_fill(0, $dim, 0.0);
    $vec[$hotIndex] = 1.0;

    return $vec;
}

function annWritePostEmbedding(Post $post, array $vector): void
{
    DB::table('posts')->where('id', $post->id)->update([
        'embedding' => '['.implode(',', $vector).']',
        'embedding_updated_at' => now(),
        'embedding_model_id' => DB::table('embedding_models')->where('slug', 'gemini-embedding-2-preview')->value('id'),
    ]);
}

function annPersistCluster(User $user, int $clusterIndex, array $embedding, float $weight, int $sampleCount): UserInterestCluster
{
    return UserInterestCluster::create([
        'user_id' => $user->id,
        'cluster_index' => $clusterIndex,
        'embedding' => $embedding,
        'weight' => $weight,
        'sample_count' => $sampleCount,
        'embedding_model_id' => DB::table('embedding_models')->where('slug', 'gemini-embedding-2-preview')->value('id'),
        'computed_at' => now(),
    ]);
}

test('generator_returns_candidates_from_each_cluster_proportional_to_weight', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    $author = User::factory()->create();

    annPersistCluster($user, 0, annHotVector($dim, 0), 0.6, 60);
    annPersistCluster($user, 1, annHotVector($dim, 100), 0.4, 40);

    $cluster0Posts = collect(range(1, 30))->map(function () use ($author, $dim) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly();
        annWritePostEmbedding($post, annHotVector($dim, 0));

        return $post->id;
    })->all();

    $cluster1Posts = collect(range(1, 30))->map(function () use ($author, $dim) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly();
        annWritePostEmbedding($post, annHotVector($dim, 100));

        return $post->id;
    })->all();

    $candidates = app(CandidateGenerator::class)
        ->annByClusters($user, totalLimit: 50, perClusterLimit: 100);

    expect($candidates)->not->toBeEmpty();

    $countByCluster = [0 => 0, 1 => 0];

    foreach ($candidates as $candidate) {
        expect($candidate->source)->toBe('ann_cluster');

        $clusterIndex = $candidate->metadata['cluster_index'];
        expect([0, 1])->toContain($clusterIndex);

        if (in_array($candidate->postId, $cluster0Posts, true)) {
            $countByCluster[0]++;
        } elseif (in_array($candidate->postId, $cluster1Posts, true)) {
            $countByCluster[1]++;
        }
    }

    expect($countByCluster[0])->toBeGreaterThan($countByCluster[1]);

    $expectedC0 = (int) round(50 * 0.6);
    $expectedC1 = (int) round(50 * 0.4);

    expect($countByCluster[0])->toBeGreaterThanOrEqual($expectedC0 - 1);
    expect($countByCluster[0])->toBeLessThanOrEqual($expectedC0 + 1);
    expect($countByCluster[1])->toBeGreaterThanOrEqual($expectedC1 - 1);
    expect($countByCluster[1])->toBeLessThanOrEqual($expectedC1 + 1);
});

test('generator_noops_if_user_has_no_clusters', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();
    $author = User::factory()->create();

    $post = Post::factory()->text()->for($author, 'author')->createQuietly();
    annWritePostEmbedding($post, annHotVector($dim, 0));

    $candidates = app(CandidateGenerator::class)->annByClusters($user);

    expect($candidates)->toBe([]);
});
