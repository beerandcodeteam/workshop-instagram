<?php

use App\Livewire\Pages\Feed\Index;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

test('feed route renders for an authenticated user', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire('pages::feed.index');
});

test('feed is ordered newest first', function () {
    $this->actingAs(User::factory()->create());

    $older = Post::factory()->text()->create(['created_at' => now()->subDay()]);
    $newer = Post::factory()->text()->create(['created_at' => now()]);

    $component = Livewire::test(Index::class);

    $posts = $component->viewData('posts');

    expect($posts->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

test('feed shows the empty state when there are no posts', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class)
        ->assertSee('Ainda não há publicações no feed');
});

test('feed exposes each post\'s like count and comment count', function () {
    $this->actingAs(User::factory()->create());

    $post = Post::factory()->text()->create();

    Like::factory()->count(2)->create(['post_id' => $post->id]);
    Comment::factory()->count(3)->create(['post_id' => $post->id]);

    $component = Livewire::test(Index::class);

    $loaded = $component->viewData('posts')->firstWhere('id', $post->id);

    expect($loaded->likes_count)->toBe(2);
    expect($loaded->comments_count)->toBe(3);
});
