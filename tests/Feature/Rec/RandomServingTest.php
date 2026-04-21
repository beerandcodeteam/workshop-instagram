<?php

use App\Models\Post;
use App\Models\RecommendationExperiment;
use App\Models\RecommendationLog;
use App\Models\User;
use App\Services\Recommendation\ExperimentService;
use App\Services\Recommendation\RecommendationService;
use Carbon\CarbonImmutable;
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

test('control_group_receives_chronological_feed', function () {
    $viewer = User::factory()->create();

    RecommendationExperiment::create([
        'user_id' => $viewer->id,
        'experiment_name' => ExperimentService::RANDOM_SERVING,
        'variant' => ExperimentService::VARIANT_CONTROL,
        'assigned_at' => now(),
    ]);

    $author = User::factory()->create();

    $oldPost = Post::factory()->text()->for($author, 'author')->createQuietly();
    $middlePost = Post::factory()->text()->for($author, 'author')->createQuietly();
    $newestPost = Post::factory()->text()->for($author, 'author')->createQuietly();

    DB::table('posts')->where('id', $oldPost->id)->update(['created_at' => now()->subDays(10)]);
    DB::table('posts')->where('id', $middlePost->id)->update(['created_at' => now()->subDays(2)]);
    DB::table('posts')->where('id', $newestPost->id)->update(['created_at' => now()]);

    $feed = app(RecommendationService::class)->feedFor($viewer, page: 1, pageSize: 10);

    expect($feed->pluck('id')->all())->toBe([$newestPost->id, $middlePost->id, $oldPost->id]);

    $logs = RecommendationLog::where('user_id', $viewer->id)->get();
    expect($logs)->toHaveCount(3);
    expect($logs->pluck('experiment_variant')->unique()->values()->all())
        ->toBe([ExperimentService::VARIANT_CONTROL]);
});

test('control_assignment_rotates_daily', function () {
    config()->set('recommendation.experiments.random_serving.enabled', true);
    config()->set('recommendation.experiments.random_serving.control_pct', 50);

    $service = app(ExperimentService::class);

    $user = null;
    $variantDay1 = null;
    $variantDay2 = null;

    for ($i = 0; $i < 50; $i++) {
        $candidate = User::factory()->create();
        $day1 = CarbonImmutable::create(2026, 4, 21, 10, 0, 0);
        $day2 = $day1->addDay();

        $v1 = $service->variantFor($candidate, ExperimentService::RANDOM_SERVING, $day1);
        $v2 = $service->variantFor($candidate, ExperimentService::RANDOM_SERVING, $day2);

        if ($v1 !== $v2) {
            $user = $candidate;
            $variantDay1 = $v1;
            $variantDay2 = $v2;
            break;
        }
    }

    expect($user)->not->toBeNull('expected at least one user to rotate between days');
    expect($variantDay1)->not->toBe($variantDay2);
});

test('approximately_1_percent_of_users_assigned_to_control', function () {
    config()->set('recommendation.experiments.random_serving.enabled', true);
    config()->set('recommendation.experiments.random_serving.control_pct', 1);

    $service = app(ExperimentService::class);
    $now = CarbonImmutable::create(2026, 4, 21, 10, 0, 0);

    $sample = 5000;
    $users = User::factory()->count($sample)->create();

    $controlCount = 0;
    foreach ($users as $user) {
        if ($service->variantFor($user, ExperimentService::RANDOM_SERVING, $now) === ExperimentService::VARIANT_CONTROL) {
            $controlCount++;
        }
    }

    $rate = $controlCount / $sample;

    expect($rate)->toBeGreaterThan(0.003);
    expect($rate)->toBeLessThan(0.02);
});
