<?php

use App\Livewire\Post\Comments;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
});

test('guest cannot comment (redirect to login)', function () {
    $post = Post::factory()->text()->create();

    Livewire::test(Comments::class, ['post' => $post])
        ->set('form.body', 'comentário de visitante')
        ->call('addComment')
        ->assertRedirect(route('login'));

    expect(Comment::count())->toBe(0);
});

test('authenticated user can add a comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $this->actingAs($user);

    Livewire::test(Comments::class, ['post' => $post])
        ->set('form.body', 'ótima publicação')
        ->call('addComment')
        ->assertHasNoErrors()
        ->assertSet('form.body', '')
        ->assertDispatched('comment.added');

    expect(Comment::where('post_id', $post->id)
        ->where('user_id', $user->id)
        ->where('body', 'ótima publicação')
        ->count())->toBe(1);
});

test('body is required', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $this->actingAs($user);

    Livewire::test(Comments::class, ['post' => $post])
        ->set('form.body', '')
        ->call('addComment')
        ->assertHasErrors(['form.body' => 'required']);

    expect(Comment::count())->toBe(0);
});

test('body max length is 2200', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $this->actingAs($user);

    Livewire::test(Comments::class, ['post' => $post])
        ->set('form.body', str_repeat('a', 2201))
        ->call('addComment')
        ->assertHasErrors(['form.body' => 'max']);

    Livewire::test(Comments::class, ['post' => $post])
        ->set('form.body', str_repeat('a', 2200))
        ->call('addComment')
        ->assertHasNoErrors();

    expect(Comment::where('post_id', $post->id)->count())->toBe(1);
});

test('comments are listed oldest-first', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $first = Comment::factory()->create([
        'post_id' => $post->id,
        'body' => 'primeiro',
        'created_at' => now()->subMinutes(3),
    ]);

    $second = Comment::factory()->create([
        'post_id' => $post->id,
        'body' => 'segundo',
        'created_at' => now()->subMinutes(2),
    ]);

    $third = Comment::factory()->create([
        'post_id' => $post->id,
        'body' => 'terceiro',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($user);

    Livewire::test(Comments::class, ['post' => $post])
        ->assertSeeInOrder([$first->body, $second->body, $third->body]);
});

test('comment count on the post reflects the number of comments', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $this->actingAs($user);

    $component = Livewire::test(Comments::class, ['post' => $post]);

    $component->set('form.body', 'um')->call('addComment')->assertHasNoErrors();
    $component->set('form.body', 'dois')->call('addComment')->assertHasNoErrors();
    $component->set('form.body', 'três')->call('addComment')->assertHasNoErrors();

    expect($post->fresh()->comments()->count())->toBe(3);
});
