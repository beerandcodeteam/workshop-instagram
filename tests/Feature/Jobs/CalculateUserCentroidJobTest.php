<?php

use App\Jobs\CalculateUserCentroidJob;
use App\Models\Like;
use App\Models\Post;
use App\Models\PostEmbedding;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
});

function vec(float $fill, int $dimensions = 1536): array
{
    return array_fill(0, $dimensions, $fill);
}

test('centroid is the average of liked posts embeddings', function () {
    $user = User::factory()->create();

    $postA = Post::factory()->text()->createQuietly();
    $postB = Post::factory()->text()->createQuietly();

    PostEmbedding::create(['post_id' => $postA->id, 'embedding' => vec(0.2)]);
    PostEmbedding::create(['post_id' => $postB->id, 'embedding' => vec(0.8)]);

    Like::create(['user_id' => $user->id, 'post_id' => $postA->id]);
    Like::create(['user_id' => $user->id, 'post_id' => $postB->id]);

    (new CalculateUserCentroidJob($user->id))->handle();

    $embedding = $user->fresh()->embedding;

    expect($embedding)->toHaveCount(1536);
    expect(round($embedding[0], 4))->toBe(0.5);
    expect(round($embedding[1535], 4))->toBe(0.5);
});

test('centroid is reset to null when user has no likes', function () {
    $user = User::factory()->create(['embedding' => vec(0.5)]);

    (new CalculateUserCentroidJob($user->id))->handle();

    expect($user->fresh()->embedding)->toBeNull();
});

test('posts without embeddings are ignored in the centroid', function () {
    $user = User::factory()->create();

    $postWithEmbedding = Post::factory()->text()->createQuietly();
    $postWithoutEmbedding = Post::factory()->text()->createQuietly();

    PostEmbedding::create([
        'post_id' => $postWithEmbedding->id,
        'embedding' => vec(0.3),
    ]);

    Like::create(['user_id' => $user->id, 'post_id' => $postWithEmbedding->id]);
    Like::create(['user_id' => $user->id, 'post_id' => $postWithoutEmbedding->id]);

    (new CalculateUserCentroidJob($user->id))->handle();

    expect(round($user->fresh()->embedding[0], 4))->toBe(0.3);
});
