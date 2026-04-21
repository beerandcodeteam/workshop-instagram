<?php

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\EmbeddingModel;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\GeminiCircuitBreaker;
use App\Services\GeminiEmbeddingService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
});

function fakeGeminiResponse(): array
{
    $dims = (int) config('services.gemini.embedding.dimensions', 1536);

    return [
        'embedding' => [
            'values' => array_fill(0, $dims, 0.1),
        ],
    ];
}

test('creating_a_post_dispatches_the_embedding_job_on_the_embeddings_queue', function () {
    Queue::fake();

    Post::factory()->text()->create();

    Queue::assertPushedOn('embeddings', GeneratePostEmbeddingJob::class);
});

test('job_persists_embedding_to_posts_table', function () {
    app()->forgetInstance(GeminiEmbeddingService::class);
    app()->bind(GeminiEmbeddingService::class, fn () => new GeminiEmbeddingService);

    Http::fake([
        '*generativelanguage.googleapis.com*' => Http::response(fakeGeminiResponse(), 200),
    ]);

    $post = Post::factory()->text()->createQuietly();

    (new GeneratePostEmbeddingJob($post))->handle(
        app(GeminiEmbeddingService::class),
        app(GeminiCircuitBreaker::class),
    );

    $fresh = Post::find($post->id);

    expect($fresh->embedding)->not->toBeNull();
    expect($fresh->embedding)->toHaveCount(1536);
});

test('job_populates_embedding_updated_at_and_model_id', function () {
    app()->forgetInstance(GeminiEmbeddingService::class);
    app()->bind(GeminiEmbeddingService::class, fn () => new GeminiEmbeddingService);

    Http::fake([
        '*generativelanguage.googleapis.com*' => Http::response(fakeGeminiResponse(), 200),
    ]);

    $post = Post::factory()->text()->createQuietly();
    $modelId = EmbeddingModel::where('slug', 'gemini-embedding-2-preview')->value('id');

    (new GeneratePostEmbeddingJob($post))->handle(
        app(GeminiEmbeddingService::class),
        app(GeminiCircuitBreaker::class),
    );

    $fresh = Post::find($post->id);

    expect($fresh->embedding_updated_at)->not->toBeNull();
    expect($fresh->embedding_model_id)->toBe($modelId);
});

test('job_retries_on_transient_failure', function () {
    app()->forgetInstance(GeminiEmbeddingService::class);
    app()->bind(GeminiEmbeddingService::class, fn () => new GeminiEmbeddingService);

    Http::fakeSequence()
        ->push('error', 500)
        ->push(fakeGeminiResponse(), 200);

    $post = Post::factory()->text()->createQuietly();

    try {
        (new GeneratePostEmbeddingJob($post))->handle(
            app(GeminiEmbeddingService::class),
            app(GeminiCircuitBreaker::class),
        );
    } catch (Throwable) {
        // First attempt fails — retry simulation below.
    }

    expect(Post::find($post->id)->embedding)->toBeNull();

    (new GeneratePostEmbeddingJob($post))->handle(
        app(GeminiEmbeddingService::class),
        app(GeminiCircuitBreaker::class),
    );

    expect(Post::find($post->id)->embedding)->not->toBeNull();
});

test('job_sends_media_via_inline_data', function () {
    Storage::fake();

    app()->forgetInstance(GeminiEmbeddingService::class);
    app()->bind(GeminiEmbeddingService::class, fn () => new GeminiEmbeddingService);

    Http::fake([
        '*generativelanguage.googleapis.com*' => Http::response(fakeGeminiResponse(), 200),
    ]);

    $post = Post::factory()->text()->createQuietly();

    $imageBytes = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AL+AH//Z');
    Storage::put('posts/test.jpg', $imageBytes);

    PostMedia::factory()->create([
        'post_id' => $post->id,
        'file_path' => 'posts/test.jpg',
        'sort_order' => 0,
    ]);

    (new GeneratePostEmbeddingJob($post))->handle(
        app(GeminiEmbeddingService::class),
        app(GeminiCircuitBreaker::class),
    );

    Http::assertSent(function ($request) {
        $payload = $request->data();
        $parts = $payload['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (isset($part['inline_data']['mime_type'])) {
                return true;
            }
        }

        return false;
    });
});

test('job_no_ops_on_post_without_body_and_media', function () {
    app()->forgetInstance(GeminiEmbeddingService::class);
    app()->bind(GeminiEmbeddingService::class, fn () => new GeminiEmbeddingService);

    Http::fake();

    $post = Post::factory()->text()->createQuietly(['body' => '']);

    (new GeneratePostEmbeddingJob($post))->handle(
        app(GeminiEmbeddingService::class),
        app(GeminiCircuitBreaker::class),
    );

    expect(Post::find($post->id)->embedding)->toBeNull();

    Http::assertNothingSent();
});

test('text_only_post_generates_embedding_without_media_parts', function () {
    app()->forgetInstance(GeminiEmbeddingService::class);
    app()->bind(GeminiEmbeddingService::class, fn () => new GeminiEmbeddingService);

    Http::fake([
        '*generativelanguage.googleapis.com*' => Http::response(fakeGeminiResponse(), 200),
    ]);

    $post = Post::factory()->text()->createQuietly(['body' => 'caption apenas texto']);

    (new GeneratePostEmbeddingJob($post))->handle(
        app(GeminiEmbeddingService::class),
        app(GeminiCircuitBreaker::class),
    );

    Http::assertSent(function ($request) {
        $parts = $request->data()['content']['parts'] ?? [];

        if (count($parts) !== 1) {
            return false;
        }

        return isset($parts[0]['text']);
    });

    expect(Post::find($post->id)->embedding)->not->toBeNull();
});
