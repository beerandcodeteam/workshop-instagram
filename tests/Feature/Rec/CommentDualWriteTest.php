<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

test('commenting_creates_a_post_interaction_row', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    Comment::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'body' => 'Que legal!',
    ]);

    $interaction = PostInteraction::where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->first();

    expect($interaction)->not->toBeNull()
        ->and($interaction->type->slug)->toBe('comment')
        ->and((float) $interaction->weight)->toBe(1.5);
});

test('deleting_a_comment_does_not_create_a_reverse_interaction', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    $comment = Comment::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'body' => 'Bem bacana.',
    ]);

    $comment->delete();

    $interactions = PostInteraction::where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->with('type')
        ->get();

    expect($interactions)->toHaveCount(1)
        ->and($interactions->first()->type->slug)->toBe('comment');
});
