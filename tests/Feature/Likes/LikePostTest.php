<?php

use App\Livewire\Pages\Feed\Index as FeedIndex;
use App\Livewire\Post\LikeButton;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
});

test('guest cannot like a post (redirect to login)', function () {
    $post = Post::factory()->text()->create();

    Livewire::test(LikeButton::class, ['post' => $post])
        ->call('toggle')
        ->assertRedirect(route('login'));

    expect(Like::count())->toBe(0);
});

test('authenticated user can like a post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $this->actingAs($user);

    Livewire::test(LikeButton::class, ['post' => $post])
        ->assertSet('isLiked', false)
        ->assertSet('likesCount', 0)
        ->call('toggle')
        ->assertSet('isLiked', true)
        ->assertSet('likesCount', 1);

    expect(Like::where('user_id', $user->id)->where('post_id', $post->id)->count())
        ->toBe(1);
});

test('a second click removes the like', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $this->actingAs($user);

    Livewire::test(LikeButton::class, ['post' => $post])
        ->call('toggle')
        ->assertSet('isLiked', true)
        ->assertSet('likesCount', 1)
        ->call('toggle')
        ->assertSet('isLiked', false)
        ->assertSet('likesCount', 0);

    expect(Like::where('user_id', $user->id)->where('post_id', $post->id)->count())
        ->toBe(0);
});

test('liking twice in the same session creates only one row', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    Like::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(LikeButton::class, ['post' => $post->fresh()]);

    $component->set('isLiked', false)
        ->call('toggle')
        ->assertSet('isLiked', true);

    expect(Like::where('user_id', $user->id)->where('post_id', $post->id)->count())
        ->toBe(1);
});

test('like count reflects total likes across users', function () {
    $post = Post::factory()->text()->create();

    Like::factory()->count(3)->create(['post_id' => $post->id]);

    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    Livewire::test(LikeButton::class, ['post' => $post->fresh()])
        ->assertSet('likesCount', 3)
        ->assertSet('isLiked', false)
        ->call('toggle')
        ->assertSet('likesCount', 4)
        ->assertSet('isLiked', true);

    expect(Like::where('post_id', $post->id)->count())->toBe(4);
});

test('like button shows the correct state for the current user on feed load', function () {
    $author = User::factory()->create();
    $viewer = User::factory()->create();

    $likedPost = Post::factory()->text()->create([
        'user_id' => $author->id,
        'body' => 'post curtido pelo viewer',
    ]);

    $unlikedPost = Post::factory()->text()->create([
        'user_id' => $author->id,
        'body' => 'post sem curtida do viewer',
    ]);

    Like::create(['user_id' => $viewer->id, 'post_id' => $likedPost->id]);
    Like::factory()->count(2)->create(['post_id' => $unlikedPost->id]);

    $this->actingAs($viewer);

    Livewire::test(FeedIndex::class)
        ->assertSeeLivewire(LikeButton::class);

    Livewire::test(LikeButton::class, ['post' => $likedPost->fresh()->loadCount('likes')->load('likes:id,post_id,user_id')])
        ->assertSet('isLiked', true)
        ->assertSet('likesCount', 1);

    Livewire::test(LikeButton::class, ['post' => $unlikedPost->fresh()->loadCount('likes')->load('likes:id,post_id,user_id')])
        ->assertSet('isLiked', false)
        ->assertSet('likesCount', 2);
});
