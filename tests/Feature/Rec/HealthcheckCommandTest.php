<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

beforeEach(function () {
    Redis::flushdb();
    Cache::flush();

    config()->set('recommendation.healthcheck.embedding_error_rate_threshold', 5.0);
    config()->set('recommendation.healthcheck.realtime_queue_lag_seconds', 60);
    config()->set('recommendation.healthcheck.window_minutes', 15);
});

function insertFailedJob(string $jobClass, ?DateTimeInterface $failedAt = null): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'embeddings',
        'payload' => json_encode(['displayName' => $jobClass, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => $jobClass]]),
        'exception' => 'fake',
        'failed_at' => ($failedAt ?? now())->format('Y-m-d H:i:s'),
    ]);
}

function insertJobRow(string $queue, ?int $availableAt = null): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $availableAt ?? time(),
        'created_at' => time(),
    ]);
}

test('command_detects_embedding_job_error_rate_over_5_percent', function () {
    for ($i = 0; $i < 20; $i++) {
        insertFailedJob('App\\Jobs\\GeneratePostEmbeddingJob');
    }

    insertJobRow('embeddings');

    Log::shouldReceive('channel')
        ->with('recommendation')
        ->andReturnSelf()
        ->shouldReceive('warning')
        ->with('rec.healthcheck.alert', Mockery::on(fn ($ctx) => $ctx['alert'] === 'embedding_error_rate'))
        ->once();

    $exit = $this->artisan('rec:healthcheck')->run();

    expect($exit)->toBe(Command::FAILURE);
});

test('command_detects_realtime_queue_lag_over_60s', function () {
    insertJobRow('realtime', availableAt: time() - 120);

    Log::shouldReceive('channel')
        ->with('recommendation')
        ->andReturnSelf()
        ->shouldReceive('warning')
        ->with('rec.healthcheck.alert', Mockery::on(fn ($ctx) => $ctx['alert'] === 'realtime_queue_lag'))
        ->once();

    $exit = $this->artisan('rec:healthcheck')->run();

    expect($exit)->toBe(Command::FAILURE);
});

test('command_deduplicates_same_alert_within_same_day', function () {
    insertJobRow('realtime', availableAt: time() - 3600);

    Log::shouldReceive('channel')
        ->with('recommendation')
        ->andReturnSelf()
        ->shouldReceive('warning')
        ->with('rec.healthcheck.alert', Mockery::any())
        ->once();

    $this->artisan('rec:healthcheck')->run();
    $this->artisan('rec:healthcheck')->run();
});

test('healthcheck_is_scheduled_every_five_minutes', function () {
    $events = collect(Schedule::events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'rec:healthcheck'));

    expect($events)->not->toBeEmpty();
    expect($events->first()->expression)->toBe('*/5 * * * *');
});
