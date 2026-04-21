<?php

use App\Livewire\Post\WhyDidISeeThis;
use App\Models\Post;
use App\Models\RecommendationSource;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Database\Seeders\RecommendationSourceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(RecommendationSourceSeeder::class);
});

test('modal_renders_human_readable_reason', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->text()->for($author, 'author')->createQuietly();

    DB::table('recommendation_logs')->insert([
        'request_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'post_id' => $post->id,
        'recommendation_source_id' => RecommendationSource::where('slug', 'ann_short_term')->value('id'),
        'score' => 0.85,
        'rank_position' => 2,
        'scores_breakdown' => json_encode(['source' => 'ann_short_term']),
        'filtered_reason' => null,
        'created_at' => now(),
    ]);

    $expectedPhrase = config('recommendation.source_reasons.ann_short_term');

    $this->actingAs($user);

    Livewire::test(WhyDidISeeThis::class, ['post' => $post])
        ->call('openModal')
        ->assertSet('open', true)
        ->assertSee($expectedPhrase);
});

test('modal_handles_missing_trace_gracefully', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->text()->for($author, 'author')->createQuietly();

    $this->actingAs($user);

    Livewire::test(WhyDidISeeThis::class, ['post' => $post])
        ->call('openModal')
        ->assertSet('open', true)
        ->assertSee('Não temos mais essa informação');
});
