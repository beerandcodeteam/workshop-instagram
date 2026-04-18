<?php

use App\Livewire\Pages\Auth\Register;
use App\Models\User;
use Livewire\Livewire;

test('register page is reachable', function () {
    $this->get('/register')->assertOk();
});

test('a visitor can register and is logged in', function () {
    Livewire::test(Register::class)
        ->set('form.name', 'Maria Souza')
        ->set('form.email', 'maria@example.com')
        ->set('form.password', 'secret1234')
        ->set('form.password_confirmation', 'secret1234')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
    expect(User::where('email', 'maria@example.com')->exists())->toBeTrue();
});

test('email must be unique', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(Register::class)
        ->set('form.name', 'Novo Usuario')
        ->set('form.email', 'taken@example.com')
        ->set('form.password', 'secret1234')
        ->set('form.password_confirmation', 'secret1234')
        ->call('register')
        ->assertHasErrors(['form.email']);
});

test('password must meet minimum length', function () {
    Livewire::test(Register::class)
        ->set('form.name', 'Usuario Teste')
        ->set('form.email', 'teste@example.com')
        ->set('form.password', 'curta')
        ->set('form.password_confirmation', 'curta')
        ->call('register')
        ->assertHasErrors(['form.password']);
});

test('password confirmation must match', function () {
    Livewire::test(Register::class)
        ->set('form.name', 'Usuario Teste')
        ->set('form.email', 'teste@example.com')
        ->set('form.password', 'secret1234')
        ->set('form.password_confirmation', 'outrasenha')
        ->call('register')
        ->assertHasErrors(['form.password']);
});

test('new account is immediately usable (no email verification)', function () {
    Livewire::test(Register::class)
        ->set('form.name', 'Sem Verificar')
        ->set('form.email', 'sem@example.com')
        ->set('form.password', 'secret1234')
        ->set('form.password_confirmation', 'secret1234')
        ->call('register')
        ->assertHasNoErrors();

    $user = User::where('email', 'sem@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->email_verified_at)->toBeNull();
    expect(auth()->check())->toBeTrue();
    expect(auth()->id())->toBe($user->id);
});
