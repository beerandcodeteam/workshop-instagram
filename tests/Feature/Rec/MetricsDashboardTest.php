<?php

use App\Livewire\Pages\Admin\RecMetrics;
use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\RecommendationSource;
use App\Models\User;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);

    Redis::flushdb();
});

function metricsAdmin(): User
{
    $admin = User::factory()->create([
        'email' => 'admin-metrics-'.uniqid().'@example.com',
    ]);

    config()->set('recommendation.admin_emails', [$admin->email]);

    return $admin;
}

test('non_admin_gets_403', function () {
    $regular = User::factory()->create();
    config()->set('recommendation.admin_emails', ['someone-else@example.com']);

    $this->actingAs($regular);

    $this->get('/admin/rec/metrics')->assertStatus(403);
});

test('admin_sees_all_widgets', function () {
    $admin = metricsAdmin();

    $this->actingAs($admin);

    $response = $this->get('/admin/rec/metrics')->assertOk();

    foreach (['ctr', 'dwell', 'gini', 'cluster_coverage', 'negative_rates', 'latency', 'job_error_rate', 'catalog_coverage'] as $widget) {
        $response->assertSee('data-widget="'.$widget.'"', escape: false);
    }
});

test('widgets_compute_from_recent_ranking_logs_and_interactions', function () {
    $admin = metricsAdmin();
    $viewer = User::factory()->create();
    $author = User::factory()->create();

    $likeType = InteractionType::where('slug', 'like')->firstOrFail();
    $viewType = InteractionType::where('slug', 'view')->firstOrFail();
    $hideType = InteractionType::where('slug', 'hide')->firstOrFail();

    $sourceId = RecommendationSource::where('slug', 'ann_long_term')->value('id');

    $dim = (int) config('services.gemini.embedding.dimensions', 1536);
    $vec = array_fill(0, $dim, 0.0);
    $vec[0] = 1.0;
    $literal = '['.implode(',', $vec).']';

    $posts = collect(range(1, 5))->map(function () use ($author, $literal) {
        $post = Post::factory()->text()->for($author, 'author')->createQuietly();
        DB::table('posts')->where('id', $post->id)->update([
            'embedding' => $literal,
            'embedding_updated_at' => now(),
        ]);

        return $post;
    });

    $requestId = (string) Str::uuid();
    $rows = [];
    foreach ($posts as $i => $post) {
        $rows[] = [
            'request_id' => $requestId,
            'user_id' => $viewer->id,
            'post_id' => $post->id,
            'recommendation_source_id' => $sourceId,
            'score' => 0.5,
            'rank_position' => $i,
            'scores_breakdown' => json_encode(['source' => 'ann_long_term', 'latency_ms' => 30 + $i * 10]),
            'filtered_reason' => null,
            'experiment_variant' => null,
            'created_at' => now()->subMinutes(30),
        ];
    }
    DB::table('recommendation_logs')->insert($rows);

    foreach ($posts->take(3) as $post) {
        PostInteraction::create([
            'user_id' => $viewer->id,
            'post_id' => $post->id,
            'interaction_type_id' => $likeType->id,
            'weight' => $likeType->default_weight,
            'created_at' => now()->subMinutes(20),
        ]);
    }

    foreach ($posts as $post) {
        PostInteraction::create([
            'user_id' => $viewer->id,
            'post_id' => $post->id,
            'interaction_type_id' => $viewType->id,
            'weight' => $viewType->default_weight,
            'duration_ms' => 5000,
            'created_at' => now()->subMinutes(10),
        ]);
    }

    PostInteraction::create([
        'user_id' => $viewer->id,
        'post_id' => $posts->first()->id,
        'interaction_type_id' => $hideType->id,
        'weight' => $hideType->default_weight,
        'created_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(RecMetrics::class);

    $metrics = $component->instance()->metrics;

    expect($metrics['ctr']['ctr_24h'])->toBeGreaterThan(0.0);
    expect($metrics['dwell_median_ms'])->toBeGreaterThan(0.0);
    expect($metrics['author_gini'])->toBeFloat();
    expect($metrics['negative_rates']['hide'])->toBeGreaterThan(0.0);
    expect($metrics['latency']['p50'])->toBeGreaterThan(0.0);
    expect($metrics['catalog_coverage'])->toBeGreaterThan(0.0);
});
