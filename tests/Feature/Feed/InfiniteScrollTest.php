<?php

use App\Livewire\Pages\Feed\Index;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

beforeEach(function () {
    Redis::flushdb();
    $this->actingAs(User::factory()->create());
});

test('initial render shows at most the page size of posts', function () {
    Post::factory()->text()->count(15)->create();

    $component = Livewire::test(Index::class);

    expect($component->viewData('posts')->count())->toBe(10);
    $component->assertViewHas('hasMorePages', true);
});

test('calling loadMore appends the next page', function () {
    Post::factory()->text()->count(15)->create();

    $component = Livewire::test(Index::class)
        ->call('loadMore');

    expect($component->viewData('posts')->count())->toBe(15);
});

test('hasMorePages becomes false after the last page is loaded', function () {
    Post::factory()->text()->count(15)->create();

    $component = Livewire::test(Index::class)
        ->call('loadMore');

    $component->assertViewHas('hasMorePages', false);
});
