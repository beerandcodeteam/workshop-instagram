<?php

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostType;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;

function fakeManifestPath(): string
{
    $path = sys_get_temp_dir().'/demo-seeder-manifest-'.uniqid().'.json';
    file_put_contents($path, json_encode([
        'images' => [
            ['id' => 1, 'path' => 'seed/images/1.jpg', 'tags' => 'a', 'user' => 'x', 'caption' => 'img 1 caption'],
            ['id' => 2, 'path' => 'seed/images/2.jpg', 'tags' => 'b', 'user' => 'y', 'caption' => 'img 2 caption'],
            ['id' => 3, 'path' => 'seed/images/3.jpg', 'tags' => 'c', 'user' => 'z', 'caption' => 'img 3 caption'],
        ],
        'videos' => [
            ['id' => 10, 'path' => 'seed/videos/10.mp4', 'tags' => 'd', 'user' => 'w', 'caption' => 'vid 10 caption'],
            ['id' => 11, 'path' => 'seed/videos/11.mp4', 'tags' => 'e', 'user' => 'v', 'caption' => 'vid 11 caption'],
        ],
    ]));

    return $path;
}

function fakeTextPoolPath(): string
{
    $path = sys_get_temp_dir().'/demo-seeder-text-pool-'.uniqid().'.json';
    file_put_contents($path, json_encode(['post de texto fake 1', 'post de texto fake 2']));

    return $path;
}

function makeSeeder(): DemoUserSeeder
{
    $seeder = app(DemoUserSeeder::class);
    $seeder->manifestPath = fakeManifestPath();
    $seeder->textPoolPath = fakeTextPoolPath();

    return $seeder;
}

test('seeder creates the configured number of users and one post per content item', function () {
    $seeder = makeSeeder();
    $seeder->userCount = 4;
    $seeder->userChunk = 2;
    $seeder->postChunk = 3;
    $seeder->run();

    expect(User::count())->toBe(4);
    expect(Post::count())->toBe(7);
});

test('seeder creates exactly one post per content item across all pools', function () {
    $seeder = makeSeeder();
    $seeder->userCount = 3;
    $seeder->run();

    $imageTypeId = PostType::where('slug', 'image')->value('id');
    $videoTypeId = PostType::where('slug', 'video')->value('id');
    $textTypeId = PostType::where('slug', 'text')->value('id');

    expect(Post::where('post_type_id', $imageTypeId)->count())->toBe(3);
    expect(Post::where('post_type_id', $videoTypeId)->count())->toBe(2);
    expect(Post::where('post_type_id', $textTypeId)->count())->toBe(2);
});

test('seeder distributes posts across users without repeating content', function () {
    $seeder = makeSeeder();
    $seeder->userCount = 3;
    $seeder->run();

    $mediaPaths = PostMedia::pluck('file_path')->all();
    expect($mediaPaths)->toHaveCount(5);
    expect(array_unique($mediaPaths))->toHaveCount(5);

    $textTypeId = PostType::where('slug', 'text')->value('id');
    $textBodies = Post::where('post_type_id', $textTypeId)->pluck('body')->all();
    expect($textBodies)->toHaveCount(2);
    expect(array_unique($textBodies))->toHaveCount(2);

    expect(User::whereDoesntHave('posts')->count())->toBe(0);
});

test('seeder attaches one media row to each image and video post', function () {
    $seeder = makeSeeder();
    $seeder->userCount = 3;
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
    $seeder = makeSeeder();
    $seeder->userCount = 3;
    $seeder->run();

    $allowed = [
        'seed/images/1.jpg', 'seed/images/2.jpg', 'seed/images/3.jpg',
        'seed/videos/10.mp4', 'seed/videos/11.mp4',
    ];

    PostMedia::all()->each(function (PostMedia $media) use ($allowed) {
        expect($allowed)->toContain($media->file_path);
    });
});

test('seeder aborts when the manifest is missing', function () {
    $seeder = makeSeeder();
    $seeder->manifestPath = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json';
    $seeder->userCount = 10;
    $seeder->run();

    expect(User::count())->toBe(0);
    expect(Post::count())->toBe(0);
});

test('seeder aborts when the text pool is missing', function () {
    $seeder = makeSeeder();
    $seeder->textPoolPath = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json';
    $seeder->userCount = 10;
    $seeder->run();

    expect(User::count())->toBe(0);
    expect(Post::count())->toBe(0);
});

test('text posts use bodies from the text pool and media posts use manifest captions', function () {
    $seeder = makeSeeder();
    $seeder->userCount = 3;
    $seeder->run();

    $textTypeId = PostType::where('slug', 'text')->value('id');

    $textBodies = Post::where('post_type_id', $textTypeId)->pluck('body')->all();
    expect($textBodies)->not->toBeEmpty();
    foreach ($textBodies as $body) {
        expect($body)->toBeIn(['post de texto fake 1', 'post de texto fake 2']);
    }

    $mediaBodies = Post::where('post_type_id', '!=', $textTypeId)
        ->whereNotNull('body')
        ->pluck('body')
        ->all();
    $allowedCaptions = ['img 1 caption', 'img 2 caption', 'img 3 caption', 'vid 10 caption', 'vid 11 caption'];
    foreach ($mediaBodies as $body) {
        expect($body)->toBeIn($allowedCaptions);
    }
});
