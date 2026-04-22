<?php

use App\Models\PostInteraction;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

test('purges_interactions_older_than_1_year', function () {
    $oldA = PostInteraction::factory()->like()->create([
        'created_at' => now()->subYears(2),
    ]);
    $oldB = PostInteraction::factory()->view()->create([
        'created_at' => now()->subDays(400),
    ]);

    $exitCode = Artisan::call('app:purge-old-events');

    expect($exitCode)->toBe(0)
        ->and(PostInteraction::find($oldA->id))->toBeNull()
        ->and(PostInteraction::find($oldB->id))->toBeNull();
});

test('preserves_recent_interactions', function () {
    $recent = PostInteraction::factory()->like()->create([
        'created_at' => now()->subMonths(6),
    ]);
    $brandNew = PostInteraction::factory()->view()->create([
        'created_at' => now()->subDays(2),
    ]);

    Artisan::call('app:purge-old-events');

    expect(PostInteraction::find($recent->id))->not->toBeNull()
        ->and(PostInteraction::find($brandNew->id))->not->toBeNull();
});

test('purge_command_is_scheduled_weekly', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(
        fn ($event) => str_contains($event->command ?? '', 'app:purge-old-events'),
    );

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('0 5 * * 0');
});
