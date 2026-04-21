<?php

use App\Models\RecommendationExperiment;
use App\Models\User;
use App\Services\Recommendation\ExperimentService;
use Carbon\CarbonImmutable;

test('same_user_gets_same_variant_within_window', function () {
    config()->set('recommendation.experiments.random_serving.enabled', true);
    config()->set('recommendation.experiments.random_serving.control_pct', 50);

    $service = app(ExperimentService::class);
    $user = User::factory()->create();

    $now = CarbonImmutable::create(2026, 4, 21, 10, 0, 0);

    $first = $service->variantFor($user, ExperimentService::RANDOM_SERVING, $now);
    $laterSameDay = $service->variantFor($user, ExperimentService::RANDOM_SERVING, $now->addHours(8));

    expect($laterSameDay)->toBe($first);

    $rankingFirst = $service->variantFor($user, ExperimentService::RANKING_FORMULA_V2, $now);
    $rankingLater = $service->variantFor($user, ExperimentService::RANKING_FORMULA_V2, $now->addHours(8));

    expect($rankingLater)->toBe($rankingFirst);
});

test('variants_distribute_roughly_uniformly', function () {
    config()->set('recommendation.experiments.ranking_formula_v2.enabled', true);
    config()->set('recommendation.experiments.ranking_formula_v2.variant_b_pct', 50);

    $service = app(ExperimentService::class);

    $counts = [
        ExperimentService::VARIANT_A => 0,
        ExperimentService::VARIANT_B => 0,
    ];

    $sample = 1000;
    $users = User::factory()->count($sample)->create();

    foreach ($users as $user) {
        $variant = $service->variantFor($user, ExperimentService::RANKING_FORMULA_V2);
        $counts[$variant]++;
    }

    $total = $counts[ExperimentService::VARIANT_A] + $counts[ExperimentService::VARIANT_B];
    expect($total)->toBe($sample);

    $percentA = $counts[ExperimentService::VARIANT_A] / $sample;
    $percentB = $counts[ExperimentService::VARIANT_B] / $sample;

    expect(abs($percentA - 0.5))->toBeLessThan(0.1);
    expect(abs($percentB - 0.5))->toBeLessThan(0.1);
});

test('persisted_assignment_takes_precedence_over_hash', function () {
    config()->set('recommendation.experiments.ranking_formula_v2.enabled', true);
    config()->set('recommendation.experiments.ranking_formula_v2.variant_b_pct', 0);

    $service = app(ExperimentService::class);
    $user = User::factory()->create();

    expect($service->variantFor($user, ExperimentService::RANKING_FORMULA_V2))
        ->toBe(ExperimentService::VARIANT_A);

    RecommendationExperiment::create([
        'user_id' => $user->id,
        'experiment_name' => ExperimentService::RANKING_FORMULA_V2,
        'variant' => ExperimentService::VARIANT_B,
        'assigned_at' => now(),
    ]);

    expect($service->variantFor($user, ExperimentService::RANKING_FORMULA_V2))
        ->toBe(ExperimentService::VARIANT_B);
});
