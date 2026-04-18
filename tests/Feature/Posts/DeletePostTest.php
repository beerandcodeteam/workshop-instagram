<?php

use App\Livewire\Pages\Feed\Index as FeedIndex;
use App\Livewire\Post\Card;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\MediaUploadService;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    Storage::fake(config('filesystems.default'));
});

test('author can delete their own post', function () {
    $author = User::factory()->create();
    $post = Post::factory()->text()->create(['user_id' => $author->id]);

    $this->actingAs($author);

    Livewire::test(Card::class, ['post' => $post])
        ->call('deletePost')
        ->assertDispatched('post.deleted');

    expect(Post::find($post->id))->toBeNull();
});

test('non-author cannot delete someone else\'s post (403)', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $post = Post::factory()->text()->create(['user_id' => $author->id]);

    $this->actingAs($other);

    Livewire::test(Card::class, ['post' => $post])
        ->call('deletePost')
        ->assertForbidden();

    expect(Post::find($post->id))->not->toBeNull();
});

test('deleting a post removes its media files from the disk', function () {
    $author = User::factory()->create();
    $this->actingAs($author);

    $mediaService = app(MediaUploadService::class);

    $post = Post::factory()->image(0)->create(['user_id' => $author->id]);

    $paths = [];
    foreach (range(0, 2) as $index) {
        $path = $mediaService->storeImage(
            UploadedFile::fake()->image("foto-{$index}.jpg"),
            $post->id,
            $index,
        );

        PostMedia::create([
            'post_id' => $post->id,
            'file_path' => $path,
            'sort_order' => $index,
        ]);

        $paths[] = $path;
    }

    $disk = Storage::disk(config('filesystems.default'));

    foreach ($paths as $path) {
        $disk->assertExists($path);
    }

    Livewire::test(Card::class, ['post' => $post->fresh()])
        ->call('deletePost')
        ->assertDispatched('post.deleted');

    foreach ($paths as $path) {
        $disk->assertMissing($path);
    }

    expect(Post::find($post->id))->toBeNull();
    expect(PostMedia::where('post_id', $post->id)->count())->toBe(0);
});

test('deleting a post cascades to likes and comments in the database', function () {
    $author = User::factory()->create();
    $reader = User::factory()->create();
    $post = Post::factory()->text()->create(['user_id' => $author->id]);

    Like::create(['user_id' => $reader->id, 'post_id' => $post->id]);
    Comment::create([
        'user_id' => $reader->id,
        'post_id' => $post->id,
        'body' => 'Muito bom!',
    ]);

    expect(Like::where('post_id', $post->id)->count())->toBe(1);
    expect(Comment::where('post_id', $post->id)->count())->toBe(1);

    $this->actingAs($author);

    Livewire::test(Card::class, ['post' => $post])
        ->call('deletePost')
        ->assertDispatched('post.deleted');

    expect(Post::find($post->id))->toBeNull();
    expect(Like::where('post_id', $post->id)->count())->toBe(0);
    expect(Comment::where('post_id', $post->id)->count())->toBe(0);
});

test('post disappears from the feed after deletion', function () {
    $author = User::factory()->create();
    $post = Post::factory()->text()->create([
        'user_id' => $author->id,
        'body' => 'publicação que será apagada',
    ]);

    $this->actingAs($author);

    Livewire::test(FeedIndex::class)
        ->assertSee('publicação que será apagada');

    Livewire::test(Card::class, ['post' => $post])
        ->call('deletePost')
        ->assertDispatched('post.deleted');

    Livewire::test(FeedIndex::class)
        ->assertDontSee('publicação que será apagada');
});
