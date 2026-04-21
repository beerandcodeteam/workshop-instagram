<?php

use App\Jobs\GeneratePostEmbeddingJob;
use App\Models\Post;
use App\Services\GeminiCircuitBreaker;
use App\Services\GeminiEmbeddingService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
});

test('circuit_opens_after_consecutive_failures', function () {
    app()->forgetInstance(GeminiEmbeddingService::class);
    app()->bind(GeminiEmbeddingService::class, fn () => new GeminiEmbeddingService);

    Http::fake([
        '*generativelanguage.googleapis.com*' => Http::response('error', 500),
    ]);

    $post = Post::factory()->text()->createQuietly();
    $breaker = app(GeminiCircuitBreaker::class);

    for ($i = 0; $i < GeminiCircuitBreaker::FAILURE_THRESHOLD; $i++) {
        try {
            (new GeneratePostEmbeddingJob($post))->handle(
                app(GeminiEmbeddingService::class),
                $breaker,
            );
        } catch (Throwable) {
            // expected
        }
    }

    expect((bool) Redis::exists(GeminiCircuitBreaker::CIRCUIT_KEY))->toBeTrue();
    expect($breaker->isOpen())->toBeTrue();
});

test('circuit_closes_after_ttl', function () {
    $breaker = app(GeminiCircuitBreaker::class);

    Redis::setex(GeminiCircuitBreaker::CIRCUIT_KEY, 1, '1');

    expect($breaker->isOpen())->toBeTrue();

    Redis::del(GeminiCircuitBreaker::CIRCUIT_KEY);

    expect($breaker->isOpen())->toBeFalse();
});

test('job_is_released_when_circuit_is_open', function () {
    Redis::setex(GeminiCircuitBreaker::CIRCUIT_KEY, 300, '1');

    Http::fake();

    $post = Post::factory()->text()->createQuietly();

    $job = new class($post) extends GeneratePostEmbeddingJob
    {
        public ?int $releasedFor = null;

        public function release($delay = 0)
        {
            $this->releasedFor = $delay;
        }
    };

    $job->handle(
        app(GeminiEmbeddingService::class),
        app(GeminiCircuitBreaker::class),
    );

    expect($job->releasedFor)->toBe(300);

    Http::assertNothingSent();

    expect(Post::find($post->id)->embedding)->toBeNull();
});
