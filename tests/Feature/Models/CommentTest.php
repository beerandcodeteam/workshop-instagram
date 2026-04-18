<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

test('a comment belongs to an author and a post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $comment = Comment::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($comment->author)->toBeInstanceOf(User::class);
    expect($comment->author->id)->toBe($user->id);
    expect($comment->post)->toBeInstanceOf(Post::class);
    expect($comment->post->id)->toBe($post->id);
});

test('deleting a post cascades to comments', function () {
    $post = Post::factory()->text()->create();

    Comment::factory()->count(3)->create(['post_id' => $post->id]);

    expect(Comment::where('post_id', $post->id)->count())->toBe(3);

    $post->delete();

    expect(Comment::where('post_id', $post->id)->count())->toBe(0);
});
