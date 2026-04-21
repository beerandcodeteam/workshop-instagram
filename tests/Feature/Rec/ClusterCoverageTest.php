<?php

use App\Models\User;
use App\Models\UserInterestCluster;
use App\Services\Recommendation\Candidate;
use App\Services\Recommendation\ClusterCoverageEnforcer;
use App\Services\Recommendation\RankedCandidate;
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

function coverageHotVector(int $dim, int $hotIndex): array
{
    $vec = array_fill(0, $dim, 0.0);
    $vec[$hotIndex] = 1.0;

    return $vec;
}

function coverageCluster(User $user, int $clusterIndex, array $embedding, float $weight, int $sampleCount): UserInterestCluster
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

function coverageRanked(int $postId, array $embedding, float $score): RankedCandidate
{
    return new RankedCandidate(
        candidate: Candidate::make($postId, 'ann_long_term', $score),
        authorId: 1,
        score: $score,
        scoresBreakdown: ['final' => $score],
        embedding: $embedding,
    );
}

test('top_20_represents_at_least_70_percent_of_user_clusters', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();

    coverageCluster($user, 0, coverageHotVector($dim, 0), 0.5, 50);
    coverageCluster($user, 1, coverageHotVector($dim, 100), 0.3, 30);
    coverageCluster($user, 2, coverageHotVector($dim, 800), 0.2, 20);

    $ranked = [];
    $postId = 1;

    for ($i = 0; $i < 19; $i++) {
        $ranked[] = coverageRanked($postId++, coverageHotVector($dim, 0), 1.0 - $i * 0.01);
    }

    $ranked[] = coverageRanked($postId++, coverageHotVector($dim, 100), 0.5);

    for ($i = 0; $i < 5; $i++) {
        $ranked[] = coverageRanked($postId++, coverageHotVector($dim, 800), 0.4 - $i * 0.01);
    }

    $beforeTop20Clusters = collect(array_slice($ranked, 0, 20))
        ->map(fn (RankedCandidate $c) => $c->embedding[800] > 0 ? 2 : ($c->embedding[100] > 0 ? 1 : 0))
        ->unique()
        ->count();

    expect($beforeTop20Clusters)->toBeLessThan(3);

    $enforced = app(ClusterCoverageEnforcer::class)->enforce($user, $ranked);

    $top20 = array_slice($enforced, 0, 20);

    $clustersInTop20 = [];
    foreach ($top20 as $candidate) {
        if ($candidate->embedding[800] > 0) {
            $clustersInTop20[2] = true;
        } elseif ($candidate->embedding[100] > 0) {
            $clustersInTop20[1] = true;
        } elseif ($candidate->embedding[0] > 0) {
            $clustersInTop20[0] = true;
        }
    }

    $coverage = count($clustersInTop20) / 3;

    expect($coverage)->toBeGreaterThanOrEqual(0.7);
});

test('coverage_is_skipped_for_users_with_fewer_than_3_clusters', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $user = User::factory()->create();

    coverageCluster($user, 0, coverageHotVector($dim, 0), 0.7, 70);
    coverageCluster($user, 1, coverageHotVector($dim, 100), 0.3, 30);

    $ranked = [];
    $postId = 1;
    for ($i = 0; $i < 25; $i++) {
        $ranked[] = coverageRanked($postId++, coverageHotVector($dim, 0), 1.0 - $i * 0.01);
    }

    $enforced = app(ClusterCoverageEnforcer::class)->enforce($user, $ranked);

    expect(count($enforced))->toBe(count($ranked));
    expect($enforced[0]->candidate->postId)->toBe($ranked[0]->candidate->postId);
});
