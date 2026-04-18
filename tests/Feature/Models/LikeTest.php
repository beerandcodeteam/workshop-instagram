<?php

use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a like belongs to a user and a post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    $like = Like::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($like->user)->toBeInstanceOf(User::class);
    expect($like->user->id)->toBe($user->id);
    expect($like->post)->toBeInstanceOf(Post::class);
    expect($like->post->id)->toBe($post->id);
});

test('a user cannot like the same post twice', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->create();

    Like::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect(fn () => Like::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]))->toThrow(QueryException::class);
});

test('deleting a post cascades to likes', function () {
    $post = Post::factory()->text()->create();

    Like::factory()->count(3)->create(['post_id' => $post->id]);

    expect(Like::where('post_id', $post->id)->count())->toBe(3);

    $post->delete();

    expect(Like::where('post_id', $post->id)->count())->toBe(0);
});
