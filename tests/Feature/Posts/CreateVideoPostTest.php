<?php

use App\Livewire\Post\CreateModal;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\VideoProbeService;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    Storage::fake(config('filesystems.default'));
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->stubDuration = function (?float $duration): void {
        $this->app->instance(
            VideoProbeService::class,
            new class($duration) extends VideoProbeService
            {
                public function __construct(public ?float $duration) {}

                public function getDurationSeconds(string $absolutePath): ?float
                {
                    return $this->duration;
                }
            },
        );
    };
});

test('a user can publish a video post under 100 MB and 60 s', function () {
    ($this->stubDuration)(30.0);

    $file = UploadedFile::fake()->create('video.mp4', 5 * 1024, 'video/mp4');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'video')
        ->set('videoForm.video', $file)
        ->call('submitVideo')
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('post.created');

    expect(Post::count())->toBe(1);
    expect(PostMedia::count())->toBe(1);

    $post = Post::first();
    expect($post->type->slug)->toBe('video');

    Storage::disk(config('filesystems.default'))->assertExists($post->media->first()->file_path);
});

test('video over 100 MB is rejected', function () {
    ($this->stubDuration)(30.0);

    $file = UploadedFile::fake()->create('video.mp4', 102401, 'video/mp4');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'video')
        ->set('videoForm.video', $file)
        ->call('submitVideo')
        ->assertHasErrors(['videoForm.video' => 'max']);

    expect(Post::count())->toBe(0);
});

test('video over 60 seconds is rejected', function () {
    ($this->stubDuration)(61.0);

    $file = UploadedFile::fake()->create('video.mp4', 2 * 1024, 'video/mp4');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'video')
        ->set('videoForm.video', $file)
        ->call('submitVideo')
        ->assertHasErrors(['videoForm.video']);

    expect(Post::count())->toBe(0);
    expect(PostMedia::count())->toBe(0);
});

test('non-video files are rejected', function () {
    ($this->stubDuration)(30.0);

    $file = UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'video')
        ->set('videoForm.video', $file)
        ->call('submitVideo')
        ->assertHasErrors(['videoForm.video']);

    expect(Post::count())->toBe(0);
});

test('caption max length is 2200', function () {
    ($this->stubDuration)(30.0);

    $file = UploadedFile::fake()->create('video.mp4', 2 * 1024, 'video/mp4');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'video')
        ->set('videoForm.video', $file)
        ->set('videoForm.caption', str_repeat('a', 2201))
        ->call('submitVideo')
        ->assertHasErrors(['videoForm.caption' => 'max']);

    expect(Post::count())->toBe(0);

    $file2 = UploadedFile::fake()->create('video2.mp4', 2 * 1024, 'video/mp4');

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'video')
        ->set('videoForm.video', $file2)
        ->set('videoForm.caption', str_repeat('a', 2200))
        ->call('submitVideo')
        ->assertHasNoErrors();

    expect(Post::count())->toBe(1);
});
