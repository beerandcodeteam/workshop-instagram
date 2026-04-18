<?php

use App\Livewire\Post\CreateModal;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    Storage::fake(config('filesystems.default'));
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('a user can publish a single-image post', function () {
    $file = UploadedFile::fake()->image('foto.jpg');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'image')
        ->set('imageForm.images', [$file])
        ->call('submitImages')
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('post.created');

    expect(Post::count())->toBe(1);
    expect(PostMedia::count())->toBe(1);

    $post = Post::first();
    expect($post->user_id)->toBe($this->user->id);
    expect($post->type->slug)->toBe('image');

    Storage::disk(config('filesystems.default'))->assertExists($post->media->first()->file_path);
});

test('a user can publish a carousel of up to 10 images', function () {
    $files = collect(range(1, 10))
        ->map(fn ($i) => UploadedFile::fake()->image("foto-{$i}.jpg"))
        ->all();

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'image')
        ->set('imageForm.images', $files)
        ->call('submitImages')
        ->assertHasNoErrors();

    expect(Post::count())->toBe(1);
    expect(PostMedia::count())->toBe(10);
});

test('more than 10 images is rejected', function () {
    $files = collect(range(1, 11))
        ->map(fn ($i) => UploadedFile::fake()->image("foto-{$i}.jpg"))
        ->all();

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'image')
        ->set('imageForm.images', $files)
        ->call('submitImages')
        ->assertHasErrors(['imageForm.images' => 'max']);

    expect(Post::count())->toBe(0);
    expect(PostMedia::count())->toBe(0);
});

test('zero images is rejected', function () {
    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'image')
        ->set('imageForm.images', [])
        ->call('submitImages')
        ->assertHasErrors(['imageForm.images']);

    expect(Post::count())->toBe(0);
});

test('non-image files are rejected', function () {
    $file = UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'image')
        ->set('imageForm.images', [$file])
        ->call('submitImages')
        ->assertHasErrors(['imageForm.images.0']);

    expect(Post::count())->toBe(0);
});

test('sort_order matches upload order', function () {
    $files = [
        UploadedFile::fake()->image('a.jpg'),
        UploadedFile::fake()->image('b.jpg'),
        UploadedFile::fake()->image('c.jpg'),
    ];

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'image')
        ->set('imageForm.images', $files)
        ->call('submitImages')
        ->assertHasNoErrors();

    $post = Post::first();
    $media = $post->media()->orderBy('sort_order')->get();

    expect($media)->toHaveCount(3);
    expect($media->pluck('sort_order')->all())->toBe([0, 1, 2]);

    foreach ($media as $index => $row) {
        expect($row->file_path)->toContain("posts/{$post->id}/images/{$index}-");
    }
});

test('caption max length is 2200', function () {
    $file = UploadedFile::fake()->image('foto.jpg');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'image')
        ->set('imageForm.images', [$file])
        ->set('imageForm.caption', str_repeat('a', 2201))
        ->call('submitImages')
        ->assertHasErrors(['imageForm.caption' => 'max']);

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'image')
        ->set('imageForm.images', [UploadedFile::fake()->image('foto.jpg')])
        ->set('imageForm.caption', str_repeat('a', 2200))
        ->call('submitImages')
        ->assertHasNoErrors();

    expect(Post::count())->toBe(1);
});
