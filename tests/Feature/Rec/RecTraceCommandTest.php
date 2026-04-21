<?php

use App\Models\Post;
use App\Models\RecommendationSource;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);
});

function insertTrace(User $user, Post $post, array $overrides = []): string
{
    $requestId = $overrides['request_id'] ?? (string) Str::uuid();

    DB::table('recommendation_logs')->insert(array_merge([
        'request_id' => $requestId,
        'user_id' => $user->id,
        'post_id' => $post->id,
        'recommendation_source_id' => RecommendationSource::where('slug', 'ann_long_term')->value('id'),
        'score' => 0.72,
        'rank_position' => 3,
        'scores_breakdown' => json_encode(['cos_lt' => 0.8, 'cos_st' => 0.6, 'final' => 0.72, 'source' => 'ann_long_term']),
        'filtered_reason' => null,
        'experiment_variant' => null,
        'created_at' => now(),
    ], $overrides));

    return $requestId;
}

test('command_outputs_scores_and_position_for_user_post_pair', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->text()->for($author, 'author')->createQuietly();

    insertTrace($user, $post, [
        'score' => 0.8123,
        'rank_position' => 5,
    ]);

    $exitCode = Artisan::call('rec:trace', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('0.8123');
    expect($output)->toContain('ann_long_term');
    expect($output)->toContain('5');
});

test('command_shows_filtered_reason_for_excluded_candidates', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->text()->for($author, 'author')->createQuietly();

    insertTrace($user, $post, [
        'rank_position' => -1,
        'filtered_reason' => 'already_seen',
    ]);

    $exitCode = Artisan::call('rec:trace', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        '--negative' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('already_seen');
});

test('command_handles_expired_trace_gracefully', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->text()->for($author, 'author')->createQuietly();

    $exitCode = Artisan::call('rec:trace', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Nenhum trace encontrado');
    expect($output)->toContain('7 dias');
});
