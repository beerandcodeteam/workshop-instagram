<?php

use App\Services\PixabayService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new PixabayService('test-key');
});

test('searchImages returns normalized hits', function () {
    Http::fake([
        'pixabay.com/api/*' => Http::response([
            'total' => 2,
            'totalHits' => 2,
            'hits' => [
                [
                    'id' => 111,
                    'largeImageURL' => 'https://cdn.pixabay.com/photo/a.jpg',
                    'tags' => 'cat, pet',
                    'user' => 'alice',
                ],
                [
                    'id' => 222,
                    'largeImageURL' => 'https://cdn.pixabay.com/photo/b.png',
                    'tags' => 'tree',
                    'user' => 'bob',
                ],
            ],
        ], 200),
    ]);

    $hits = $this->service->searchImages('nature');

    expect($hits)->toHaveCount(2);
    expect($hits[0])->toMatchArray([
        'id' => 111,
        'url' => 'https://cdn.pixabay.com/photo/a.jpg',
        'tags' => 'cat, pet',
        'user' => 'alice',
        'extension' => 'jpg',
    ]);
    expect($hits[1]['extension'])->toBe('png');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'key=test-key')
        && str_contains($request->url(), 'q=nature')
        && str_contains($request->url(), 'image_type=photo')
    );
});

test('searchVideos picks medium URL and normalizes extension', function () {
    Http::fake([
        'pixabay.com/api/videos/*' => Http::response([
            'total' => 1,
            'totalHits' => 1,
            'hits' => [
                [
                    'id' => 999,
                    'videos' => [
                        'large' => ['url' => 'https://cdn/large.mp4'],
                        'medium' => ['url' => 'https://cdn/medium.mp4'],
                        'small' => ['url' => 'https://cdn/small.mp4'],
                        'tiny' => ['url' => 'https://cdn/tiny.mp4'],
                    ],
                    'tags' => 'ocean',
                    'user' => 'carol',
                ],
            ],
        ], 200),
    ]);

    $hits = $this->service->searchVideos('ocean');

    expect($hits)->toHaveCount(1);
    expect($hits[0])->toMatchArray([
        'id' => 999,
        'url' => 'https://cdn/medium.mp4',
        'extension' => 'mp4',
        'user' => 'carol',
    ]);
});

test('searchVideos falls back to smaller variants when medium is missing', function () {
    Http::fake([
        'pixabay.com/api/videos/*' => Http::response([
            'hits' => [
                [
                    'id' => 1,
                    'videos' => [
                        'small' => ['url' => 'https://cdn/only-small.mp4'],
                        'tiny' => ['url' => 'https://cdn/tiny.mp4'],
                    ],
                    'tags' => 't',
                    'user' => 'u',
                ],
            ],
        ], 200),
    ]);

    $hits = $this->service->searchVideos('anything');

    expect($hits[0]['url'])->toBe('https://cdn/only-small.mp4');
});

test('download returns the raw response body', function () {
    Http::fake([
        'cdn.pixabay.com/*' => Http::response('BINARY-BYTES', 200),
    ]);

    $body = $this->service->download('https://cdn.pixabay.com/photo/a.jpg');

    expect($body)->toBe('BINARY-BYTES');
});

test('searchImages throws when the Pixabay API returns a failure', function () {
    Http::fake([
        'pixabay.com/api/*' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $this->service->searchImages('nature');
})->throws(RequestException::class);
