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

test('command_processes_all_posts_without_embedding', function () {
    $withEmbedding = Post::factory()->text()->create();
    $withoutEmbeddingA = Post::factory()->text()->createQuietly();
    $withoutEmbeddingB = Post::factory()->text()->createQuietly();

    Queue::fake();

    $this->artisan('app:generate-post-embeddings', ['--dispatch-queue' => true])
        ->assertExitCode(0);

    Queue::assertPushed(GeneratePostEmbeddingJob::class, 2);
});

test('force_regenerates_existing_embeddings', function () {
    Post::factory()->text()->create();
    Post::factory()->text()->create();

    Queue::fake();

    $this->artisan('app:generate-post-embeddings', [
        '--force' => true,
        '--dispatch-queue' => true,
    ])->assertExitCode(0);

    Queue::assertPushed(GeneratePostEmbeddingJob::class, 2);
});

test('since_filters_by_created_at', function () {
    $old = Post::factory()->text()->createQuietly(['created_at' => now()->subDays(10)]);
    $newA = Post::factory()->text()->createQuietly(['created_at' => now()->subDays(1)]);
    $newB = Post::factory()->text()->createQuietly(['created_at' => now()]);

    Queue::fake();

    $this->artisan('app:generate-post-embeddings', [
        '--since' => now()->subDays(5)->toDateString(),
        '--dispatch-queue' => true,
    ])->assertExitCode(0);

    Queue::assertPushed(GeneratePostEmbeddingJob::class, 2);
});

test('post_ids_filters_to_subset', function () {
    $a = Post::factory()->text()->createQuietly();
    $b = Post::factory()->text()->createQuietly();
    $c = Post::factory()->text()->createQuietly();

    Queue::fake();

    $this->artisan('app:generate-post-embeddings', [
        '--post-ids' => "{$a->id},{$c->id}",
        '--dispatch-queue' => true,
    ])->assertExitCode(0);

    Queue::assertPushed(GeneratePostEmbeddingJob::class, 2);
    Queue::assertPushed(GeneratePostEmbeddingJob::class, function ($job) use ($a) {
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty('post');
        $property->setAccessible(true);

        return $property->getValue($job)->id === $a->id;
    });
    Queue::assertPushed(GeneratePostEmbeddingJob::class, function ($job) use ($c) {
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty('post');
        $property->setAccessible(true);

        return $property->getValue($job)->id === $c->id;
    });
});

test('dispatch_queue_enqueues_instead_of_running_sync', function () {
    Post::factory()->text()->createQuietly();
    Post::factory()->text()->createQuietly();

    Queue::fake();

    $this->artisan('app:generate-post-embeddings', ['--dispatch-queue' => true])
        ->assertExitCode(0);

    Queue::assertPushedOn('embeddings', GeneratePostEmbeddingJob::class);
    Queue::assertPushed(GeneratePostEmbeddingJob::class, 2);
});
