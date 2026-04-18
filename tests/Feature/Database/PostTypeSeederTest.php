<?php

use App\Models\PostType;
use Database\Seeders\PostTypeSeeder;

test('post_types table is seeded with text, image and video', function () {
    $this->seed(PostTypeSeeder::class);

    expect(PostType::whereIn('slug', ['text', 'image', 'video'])->count())->toBe(3);
    expect(PostType::where('slug', 'text')->exists())->toBeTrue();
    expect(PostType::where('slug', 'image')->exists())->toBeTrue();
    expect(PostType::where('slug', 'video')->exists())->toBeTrue();
});

test('post_type slugs are unique', function () {
    $this->seed(PostTypeSeeder::class);

    $slugs = PostType::pluck('slug');

    expect($slugs->count())->toBe($slugs->unique()->count());
});
