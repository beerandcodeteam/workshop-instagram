<?php

use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;

test('user has many posts / likes / comments', function () {
    $user = User::factory()->create();

    Post::factory()->text()->count(2)->create(['user_id' => $user->id]);

    $otherPost = Post::factory()->text()->create();
    Like::factory()->count(3)->for($user)->create();
    Comment::factory()->count(2)->create(['user_id' => $user->id, 'post_id' => $otherPost->id]);

    $user->refresh();

    expect($user->posts)->toHaveCount(2);
    expect($user->likes)->toHaveCount(3);
    expect($user->comments)->toHaveCount(2);
});
