<?php

use App\Models\Like;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

test('liking_a_post_creates_a_post_interaction_row', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    Like::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    $interaction = PostInteraction::where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->first();

    expect($interaction)->not->toBeNull()
        ->and($interaction->type->slug)->toBe('like')
        ->and((float) $interaction->weight)->toBe(1.0);
});

test('unliking_a_post_creates_an_unlike_interaction', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    $like = Like::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    $like->delete();

    $interactions = PostInteraction::where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->with('type')
        ->get();

    expect($interactions)->toHaveCount(2);

    $slugs = $interactions->map(fn ($i) => $i->type->slug)->all();
    expect($slugs)->toContain('like')->toContain('unlike');

    $unlike = $interactions->firstWhere(fn ($i) => $i->type->slug === 'unlike');
    expect((float) $unlike->weight)->toBe(-0.5);
});
