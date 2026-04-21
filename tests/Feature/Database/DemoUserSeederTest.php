<?php

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostType;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\Storage;

function writeFakeManifest(string $path = 'pixabay-manifest.json'): void
{
    Storage::disk('local')->put($path, json_encode([
        'images' => [
            ['id' => 1, 'path' => 'seed/images/1.jpg', 'tags' => 'a', 'user' => 'x'],
            ['id' => 2, 'path' => 'seed/images/2.jpg', 'tags' => 'b', 'user' => 'y'],
        ],
        'videos' => [
            ['id' => 10, 'path' => 'seed/videos/10.mp4', 'tags' => 'c', 'user' => 'z'],
        ],
    ]));
}

beforeEach(function () {
    Storage::fake('local');
});

test('seeder creates the configured number of users and posts', function () {
    writeFakeManifest();

    $seeder = app(DemoUserSeeder::class);
    $seeder->userCount = 10;
    $seeder->postsPerUser = 2;
    $seeder->userChunk = 5;
    $seeder->postChunk = 5;
    $seeder->run();

    expect(User::count())->toBe(10);
    expect(Post::count())->toBe(20);
});

test('seeder distributes post types 40/20/40', function () {
    writeFakeManifest();

    $seeder = app(DemoUserSeeder::class);
    $seeder->userCount = 50;
    $seeder->postsPerUser = 2;
    $seeder->run();

    $imageTypeId = PostType::where('slug', 'image')->value('id');
    $videoTypeId = PostType::where('slug', 'video')->value('id');
    $textTypeId = PostType::where('slug', 'text')->value('id');

    expect(Post::where('post_type_id', $imageTypeId)->count())->toBe(40);
    expect(Post::where('post_type_id', $videoTypeId)->count())->toBe(20);
    expect(Post::where('post_type_id', $textTypeId)->count())->toBe(40);
});

test('seeder attaches one media row to each image and video post', function () {
    writeFakeManifest();

    $seeder = app(DemoUserSeeder::class);
    $seeder->userCount = 25;
    $seeder->postsPerUser = 2;
    $seeder->run();

    $imageTypeId = PostType::where('slug', 'image')->value('id');
    $videoTypeId = PostType::where('slug', 'video')->value('id');

    $mediaPostIds = PostMedia::pluck('post_id')->unique()->values();
    $expectedPostIds = Post::whereIn('post_type_id', [$imageTypeId, $videoTypeId])
        ->pluck('id')
        ->sort()
        ->values();

    expect($mediaPostIds->sort()->values()->all())->toBe($expectedPostIds->all());
    expect(PostMedia::count())->toBe($expectedPostIds->count());
});

test('media file_path references one of the pool paths', function () {
    writeFakeManifest();

    $seeder = app(DemoUserSeeder::class);
    $seeder->userCount = 20;
    $seeder->postsPerUser = 2;
    $seeder->run();

    $allowed = ['seed/images/1.jpg', 'seed/images/2.jpg', 'seed/videos/10.mp4'];

    PostMedia::all()->each(function (PostMedia $media) use ($allowed) {
        expect($allowed)->toContain($media->file_path);
    });
});

test('seeder aborts when the manifest is missing', function () {
    $seeder = app(DemoUserSeeder::class);
    $seeder->userCount = 10;
    $seeder->run();

    expect(User::count())->toBe(0);
    expect(Post::count())->toBe(0);
});
