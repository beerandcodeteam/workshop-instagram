<?php

use App\Livewire\Post\ShareButton;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use Database\Seeders\InteractionTypeSeeder;
use Database\Seeders\PostTypeSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(InteractionTypeSeeder::class);
});

test('share_action_records_a_share_interaction', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    $this->actingAs($user);

    Livewire::test(ShareButton::class, ['post' => $post])
        ->call('share')
        ->assertDispatched('post.shared');

    $interaction = PostInteraction::where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->with('type')
        ->first();

    expect($interaction)->not->toBeNull()
        ->and($interaction->type->slug)->toBe('share')
        ->and((float) $interaction->weight)->toBe(2.0);
});

test('share_requires_authentication', function () {
    $post = Post::factory()->text()->createQuietly();

    Livewire::test(ShareButton::class, ['post' => $post])
        ->call('share')
        ->assertRedirect(route('login'));

    expect(PostInteraction::count())->toBe(0);
});
