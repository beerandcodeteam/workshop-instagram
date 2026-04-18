<?php

use App\Livewire\Post\EditCaption;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
});

test('author can edit their own post caption', function () {
    $author = User::factory()->create();
    $post = Post::factory()->text()->create([
        'user_id' => $author->id,
        'body' => 'legenda original',
    ]);

    $this->actingAs($author);

    Livewire::test(EditCaption::class, ['post' => $post])
        ->call('openModal')
        ->set('form.body', 'legenda atualizada')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('post.updated');

    expect($post->fresh()->body)->toBe('legenda atualizada');
});

test('non-author cannot edit someone else\'s post (403)', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();

    $post = Post::factory()->text()->create([
        'user_id' => $author->id,
        'body' => 'legenda original',
    ]);

    $this->actingAs($other);

    Livewire::test(EditCaption::class, ['post' => $post])
        ->call('openModal')
        ->assertForbidden();

    Livewire::test(EditCaption::class, ['post' => $post])
        ->set('form.body', 'hack attempt')
        ->call('save')
        ->assertForbidden();

    expect($post->fresh()->body)->toBe('legenda original');
});

test('validation: new body required / max 2200', function () {
    $author = User::factory()->create();
    $post = Post::factory()->text()->create([
        'user_id' => $author->id,
        'body' => 'legenda original',
    ]);

    $this->actingAs($author);

    Livewire::test(EditCaption::class, ['post' => $post])
        ->call('openModal')
        ->set('form.body', '')
        ->call('save')
        ->assertHasErrors(['form.body' => 'required']);

    Livewire::test(EditCaption::class, ['post' => $post])
        ->call('openModal')
        ->set('form.body', str_repeat('a', 2201))
        ->call('save')
        ->assertHasErrors(['form.body' => 'max']);

    Livewire::test(EditCaption::class, ['post' => $post])
        ->call('openModal')
        ->set('form.body', str_repeat('a', 2200))
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->body)->toBe(str_repeat('a', 2200));
});

test('post_type and media are unchanged after edit', function () {
    $author = User::factory()->create();
    $post = Post::factory()->image(3)->create([
        'user_id' => $author->id,
        'body' => 'legenda original',
    ]);

    $originalTypeId = $post->post_type_id;
    $originalMedia = $post->media()->orderBy('sort_order')->get()
        ->map(fn (PostMedia $m) => ['id' => $m->id, 'file_path' => $m->file_path, 'sort_order' => $m->sort_order])
        ->all();

    $this->actingAs($author);

    Livewire::test(EditCaption::class, ['post' => $post])
        ->call('openModal')
        ->set('form.body', 'nova legenda')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $post->fresh();

    expect($fresh->body)->toBe('nova legenda');
    expect($fresh->post_type_id)->toBe($originalTypeId);

    $freshMedia = $fresh->media()->orderBy('sort_order')->get()
        ->map(fn (PostMedia $m) => ['id' => $m->id, 'file_path' => $m->file_path, 'sort_order' => $m->sort_order])
        ->all();

    expect($freshMedia)->toBe($originalMedia);
});
