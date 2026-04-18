<?php

use App\Services\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->disk = config('filesystems.default');
    Storage::fake($this->disk);
    $this->service = new MediaUploadService;
});

test('storeImage writes to the configured disk and returns a path', function () {
    $file = UploadedFile::fake()->image('photo.jpg');

    $path = $this->service->storeImage($file, postId: 10, sortOrder: 2);

    expect($path)->toStartWith('posts/10/images/');
    Storage::disk($this->disk)->assertExists($path);
});

test('storeVideo writes to the configured disk and returns a path', function () {
    $file = UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4');

    $path = $this->service->storeVideo($file, postId: 42);

    expect($path)->toStartWith('posts/42/videos/');
    Storage::disk($this->disk)->assertExists($path);
});

test('delete removes the file from the disk', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $path = $this->service->storeImage($file, postId: 7, sortOrder: 0);

    Storage::disk($this->disk)->assertExists($path);

    $this->service->delete($path);

    Storage::disk($this->disk)->assertMissing($path);
});
