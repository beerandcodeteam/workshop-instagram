<?php

use App\Livewire\Post\Card;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('text post renders its body', function () {
    $post = Post::factory()->text()->create(['body' => 'Olá pessoal, este é um post de texto!']);

    Livewire::test(Card::class, ['post' => $post])
        ->assertSee('Olá pessoal, este é um post de texto!');
});

test('single-image post renders one <img>', function () {
    $post = Post::factory()->image(1)->create();

    $html = Livewire::test(Card::class, ['post' => $post])->html();

    expect(substr_count($html, '<img'))->toBe(1);
});

test('carousel post renders all images in sort_order and no duplicates', function () {
    $post = Post::factory()->image(3)->create();

    $expected = $post->media->sortBy('sort_order')->pluck('file_path')->all();

    $html = Livewire::test(Card::class, ['post' => $post])->html();

    $offset = 0;
    foreach ($expected as $path) {
        $position = strpos($html, $path, $offset);

        expect($position)->not->toBeFalse();

        expect(substr_count($html, $path))->toBe(1);

        $offset = $position + strlen($path);
    }
});

test('video post renders a <video> tag with the stored source', function () {
    $post = Post::factory()->video()->create();

    $media = $post->media->first();

    $html = Livewire::test(Card::class, ['post' => $post])->html();

    expect($html)->toContain('<video');
    expect($html)->toContain($media->file_path);
});

test('caption is rendered for image and video posts when present', function () {
    $imagePost = Post::factory()->image(1)->create(['body' => 'Legenda da imagem']);
    $videoPost = Post::factory()->video()->create(['body' => 'Legenda do vídeo']);

    Livewire::test(Card::class, ['post' => $imagePost])
        ->assertSee('Legenda da imagem');

    Livewire::test(Card::class, ['post' => $videoPost])
        ->assertSee('Legenda do vídeo');
});
