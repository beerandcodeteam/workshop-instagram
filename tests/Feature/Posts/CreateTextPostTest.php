<?php

use App\Livewire\Pages\Feed\Index as FeedIndex;
use App\Livewire\Post\CreateModal;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('a user can publish a text post', function () {
    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'text')
        ->set('textForm.body', 'Meu primeiro post de texto')
        ->call('submitText')
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('post.created');

    $post = Post::first();

    expect($post)->not->toBeNull();
    expect($post->body)->toBe('Meu primeiro post de texto');
    expect($post->user_id)->toBe($this->user->id);
    expect($post->type->slug)->toBe('text');
});

test('body is required', function () {
    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'text')
        ->set('textForm.body', '')
        ->call('submitText')
        ->assertHasErrors(['textForm.body' => 'required']);

    expect(Post::count())->toBe(0);
});

test('body max length is 2200', function () {
    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'text')
        ->set('textForm.body', str_repeat('a', 2201))
        ->call('submitText')
        ->assertHasErrors(['textForm.body' => 'max']);

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'text')
        ->set('textForm.body', str_repeat('a', 2200))
        ->call('submitText')
        ->assertHasNoErrors();

    expect(Post::count())->toBe(1);
});

test('the post appears at the top of the feed after creation', function () {
    Post::factory()->text()->create([
        'user_id' => $this->user->id,
        'body' => 'publicação antiga',
    ]);

    Livewire::test(CreateModal::class)
        ->call('openModal')
        ->call('selectType', 'text')
        ->set('textForm.body', 'publicação nova')
        ->call('submitText')
        ->assertHasNoErrors();

    Livewire::test(FeedIndex::class)
        ->assertSeeInOrder(['publicação nova', 'publicação antiga']);
});
