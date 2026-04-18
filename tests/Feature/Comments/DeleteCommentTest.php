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

test('author can delete their own comment', function () {
    $author = User::factory()->create();
    $post = Post::factory()->text()->create();

    $comment = Comment::factory()->create([
        'user_id' => $author->id,
        'post_id' => $post->id,
    ]);

    $this->actingAs($author);

    Livewire::test(Comments::class, ['post' => $post])
        ->call('deleteComment', $comment->id)
        ->assertHasNoErrors()
        ->assertDispatched('comment.deleted');

    expect(Comment::where('id', $comment->id)->exists())->toBeFalse();
});

test('non-author cannot delete someone else\'s comment (403)', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $post = Post::factory()->text()->create();

    $comment = Comment::factory()->create([
        'user_id' => $author->id,
        'post_id' => $post->id,
    ]);

    $this->actingAs($other);

    Livewire::test(Comments::class, ['post' => $post])
        ->call('deleteComment', $comment->id)
        ->assertForbidden();

    expect(Comment::where('id', $comment->id)->exists())->toBeTrue();
});

test('comment count decreases after deletion', function () {
    $author = User::factory()->create();
    $post = Post::factory()->text()->create();

    $comments = Comment::factory()->count(3)->create([
        'user_id' => $author->id,
        'post_id' => $post->id,
    ]);

    expect($post->fresh()->comments()->count())->toBe(3);

    $this->actingAs($author);

    Livewire::test(Comments::class, ['post' => $post])
        ->call('deleteComment', $comments->first()->id)
        ->assertHasNoErrors();

    expect($post->fresh()->comments()->count())->toBe(2);
});
