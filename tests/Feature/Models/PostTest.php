<?php

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostType;
use App\Models\User;

test('a post belongs to an author', function () {
    $user = User::factory()->create();
    $post = Post::factory()->text()->for($user, 'author')->create();

    expect($post->author)->toBeInstanceOf(User::class);
    expect($post->author->id)->toBe($user->id);
});

test('a post belongs to a post type', function () {
    $post = Post::factory()->text()->create();

    expect($post->type)->toBeInstanceOf(PostType::class);
    expect($post->type->slug)->toBe('text');
});

test('post factory text state creates a post with body and no media', function () {
    $post = Post::factory()->text()->create();

    expect($post->type->slug)->toBe('text');
    expect($post->body)->not->toBeNull();
    expect($post->media)->toHaveCount(0);
});

test('post factory image state creates a post with N media rows in order', function () {
    $post = Post::factory()->image(3)->create();

    expect($post->type->slug)->toBe('image');
    expect($post->media)->toHaveCount(3);

    $sortOrders = $post->media->pluck('sort_order')->all();
    expect($sortOrders)->toBe([0, 1, 2]);

    $post->media->each(fn (PostMedia $media) => expect($media->post_id)->toBe($post->id));
});

test('post factory video state creates a post with exactly one media row', function () {
    $post = Post::factory()->video()->create();

    expect($post->type->slug)->toBe('video');
    expect($post->media)->toHaveCount(1);
    expect($post->media->first()->sort_order)->toBe(0);
});
