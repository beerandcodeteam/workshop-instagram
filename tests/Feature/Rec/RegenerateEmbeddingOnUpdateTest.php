<?php

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\Post;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
});

test('updating_post_body_dispatches_regeneration_job', function () {
    $post = Post::factory()->text()->create();

    Queue::fake();

    $post->update(['body' => 'nova legenda']);

    Queue::assertPushed(GeneratePostEmbeddingJob::class, function ($job) {
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty('replace');
        $property->setAccessible(true);

        return $property->getValue($job) === true;
    });
});

test('updating_post_without_body_change_does_not_regenerate', function () {
    $post = Post::factory()->text()->create();

    Queue::fake();

    $post->update(['updated_at' => now()->addSecond()]);

    Queue::assertNotPushed(GeneratePostEmbeddingJob::class);
});

test('regeneration_overwrites_previous_embedding', function () {
    $post = Post::factory()->text()->create(['body' => 'legenda inicial']);

    $fresh = Post::find($post->id);
    expect($fresh->embedding)->not->toBeNull();

    $previousUpdatedAt = $fresh->embedding_updated_at;

    sleep(1);

    $post->update(['body' => 'legenda nova']);

    $afterUpdate = Post::find($post->id);

    expect($afterUpdate->embedding)->not->toBeNull();
    expect($afterUpdate->embedding_updated_at->gt($previousUpdatedAt))->toBeTrue();
});
