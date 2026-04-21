<?php

use App\Livewire\Pages\Feed\Index;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('feed route renders for an authenticated user', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire('pages::feed.index');
});

test('feed is ordered newest first', function () {
    $this->actingAs(User::factory()->create());

    $older = Post::factory()->text()->create(['created_at' => now()->subDay()]);
    $newer = Post::factory()->text()->create(['created_at' => now()]);

    $component = Livewire::test(Index::class);

    $posts = $component->viewData('posts');

    expect($posts->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

test('feed shows the empty state when there are no posts', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class)
        ->assertSee('Ainda não há publicações no feed');
});

test('feed exposes each post\'s like count and comment count', function () {
    $this->actingAs(User::factory()->create());

    $post = Post::factory()->text()->create();

    Like::factory()->count(2)->create(['post_id' => $post->id]);
    Comment::factory()->count(3)->create(['post_id' => $post->id]);

    $component = Livewire::test(Index::class);

    $loaded = $component->viewData('posts')->firstWhere('id', $post->id);

    expect($loaded->likes_count)->toBe(2);
    expect($loaded->comments_count)->toBe(3);
});

test('feed excludes posts that do not have an embedding yet', function () {
    $this->actingAs(User::factory()->create());

    $withEmbedding = Post::factory()->text()->create();
    $withoutEmbedding = Post::factory()->text()->create();

    DB::table('posts')->where('id', $withoutEmbedding->id)->update(['embedding' => null]);

    $component = Livewire::test(Index::class);

    $ids = $component->viewData('posts')->pluck('id')->all();

    expect($ids)->toContain($withEmbedding->id)
        ->and($ids)->not->toContain($withoutEmbedding->id);
});

test('feed ranks posts by cosine similarity to the viewer centroid', function () {
    $viewer = User::factory()->create();

    $dims = (int) config('services.gemini.embedding.dimensions', 1536);

    $centroid = array_fill(0, $dims, 0.0);
    $centroid[0] = 1.0;
    $viewer->update(['long_term_embedding' => $centroid]);

    $this->actingAs($viewer);

    $farPost = Post::factory()->text()->create();
    $nearPost = Post::factory()->text()->create();

    $farVector = array_fill(0, $dims, 0.0);
    $farVector[1] = 1.0;

    $nearVector = array_fill(0, $dims, 0.0);
    $nearVector[0] = 1.0;

    DB::table('posts')->where('id', $farPost->id)->update(['embedding' => '['.implode(',', $farVector).']']);
    DB::table('posts')->where('id', $nearPost->id)->update(['embedding' => '['.implode(',', $nearVector).']']);

    $component = Livewire::test(Index::class);

    $ids = $component->viewData('posts')->pluck('id')->all();

    expect($ids[0])->toBe($nearPost->id)
        ->and($ids[1])->toBe($farPost->id);
});
