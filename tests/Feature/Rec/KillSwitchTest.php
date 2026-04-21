<?php

use App\Models\Post;
use App\Models\User;
use App\Services\Recommendation\KillSwitchService;
use App\Services\Recommendation\RecommendationService;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);

    Cache::flush();
    Redis::flushdb();
});

test('rec_disable_falls_back_to_chronological_feed', function () {
    $dim = (int) config('services.gemini.embedding.dimensions', 1536);

    $viewer = User::factory()->create();

    DB::table('users')->where('id', $viewer->id)->update([
        'long_term_embedding' => '['.implode(',', array_fill(0, $dim, 0.1)).']',
        'long_term_embedding_updated_at' => now(),
    ]);

    $author = User::factory()->create();

    $oldPost = Post::factory()->text()->for($author, 'author')->createQuietly();
    $newerPost = Post::factory()->text()->for($author, 'author')->createQuietly();
    $newestPost = Post::factory()->text()->for($author, 'author')->createQuietly();

    DB::table('posts')->where('id', $oldPost->id)->update(['created_at' => now()->subDays(10)]);
    DB::table('posts')->where('id', $newerPost->id)->update(['created_at' => now()->subDays(2)]);
    DB::table('posts')->where('id', $newestPost->id)->update(['created_at' => now()]);

    $this->artisan('rec:disable', ['--reason' => 'incidente-teste'])->assertSuccessful();

    $feed = app(RecommendationService::class)->feedFor($viewer->fresh(), page: 1, pageSize: 10);

    expect($feed->isNotEmpty())->toBeTrue();
    expect($feed->first()->id)->toBe($newestPost->id);
    expect($feed->pluck('id')->all())->toBe([$newestPost->id, $newerPost->id, $oldPost->id]);
});

test('rec_disable_requires_reason', function () {
    expect(app(KillSwitchService::class)->isDisabled())->toBeFalse();

    $exit = $this->artisan('rec:disable')->run();

    expect($exit)->toBe(Command::INVALID);
    expect(app(KillSwitchService::class)->isDisabled())->toBeFalse();
});

test('rec_enable_restores_recommendation_pipeline', function () {
    $killSwitch = app(KillSwitchService::class);

    $this->artisan('rec:disable', ['--reason' => 'teste-reset'])->assertSuccessful();
    expect($killSwitch->isDisabled())->toBeTrue();

    $this->artisan('rec:enable')->assertSuccessful();

    expect($killSwitch->isDisabled())->toBeFalse();
    expect($killSwitch->status())->toBeNull();
});
