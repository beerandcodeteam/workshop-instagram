<?php

use App\Livewire\Post\CreateModal;
use App\Models\User;
use Database\Seeders\PostTypeSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
});

test('guest cannot open the create-post flow', function () {
    $this->get('/posts/create')->assertRedirect('/login');
});

test('authenticated user sees the type picker', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CreateModal::class)
        ->call('openModal')
        ->assertSet('open', true)
        ->assertSet('step', 'type')
        ->assertSee('Texto')
        ->assertSee('Imagem')
        ->assertSee('Vídeo');
});
