<?php

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

test('post_interactions_table_has_expected_indexes', function () {
    $indexes = collect(DB::select(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'post_interactions'"
    ))->pluck('indexname')->all();

    expect($indexes)
        ->toContain('post_interactions_user_created_idx')
        ->toContain('post_interactions_post_created_idx')
        ->toContain('post_interactions_type_created_idx')
        ->toContain('post_interactions_user_post_type_idx')
        ->toContain('post_interactions_session_idx');
});

test('a_post_interaction_belongs_to_user_post_and_type', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();
    $type = InteractionType::where('slug', 'like')->firstOrFail();

    $interaction = PostInteraction::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'interaction_type_id' => $type->id,
        'weight' => $type->default_weight,
        'created_at' => now(),
    ]);

    expect($interaction->user)->toBeInstanceOf(User::class)
        ->and($interaction->user->id)->toBe($user->id)
        ->and($interaction->post)->toBeInstanceOf(Post::class)
        ->and($interaction->post->id)->toBe($post->id)
        ->and($interaction->type)->toBeInstanceOf(InteractionType::class)
        ->and($interaction->type->slug)->toBe('like');
});

test('factory_states_produce_valid_interactions', function () {
    $like = PostInteraction::factory()->like()->create();
    expect($like->type->slug)->toBe('like');

    $comment = PostInteraction::factory()->comment()->create();
    expect($comment->type->slug)->toBe('comment');

    $view = PostInteraction::factory()->view()->create();
    expect($view->type->slug)->toBe('view');

    $hide = PostInteraction::factory()->hide()->create();
    expect($hide->type->slug)->toBe('hide');

    $report = PostInteraction::factory()->report()->create();
    expect($report->type->slug)->toBe('report');
});
