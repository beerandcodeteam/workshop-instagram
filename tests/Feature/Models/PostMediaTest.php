<?php

use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Database\QueryException;

test('post_media belongs to a post', function () {
    $post = Post::factory()->text()->create();
    $media = PostMedia::factory()->create(['post_id' => $post->id]);

    expect($media->post)->toBeInstanceOf(Post::class);
    expect($media->post->id)->toBe($post->id);
});

test('unique sort_order per post is enforced', function () {
    $post = Post::factory()->text()->create();

    PostMedia::factory()->create([
        'post_id' => $post->id,
        'sort_order' => 0,
    ]);

    expect(fn () => PostMedia::factory()->create([
        'post_id' => $post->id,
        'sort_order' => 0,
    ]))->toThrow(QueryException::class);
});

test('deleting a post cascades to post_media', function () {
    $post = Post::factory()->image(2)->create();

    expect(PostMedia::where('post_id', $post->id)->count())->toBe(2);

    $post->delete();

    expect(PostMedia::where('post_id', $post->id)->count())->toBe(0);
});
