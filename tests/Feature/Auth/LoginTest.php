<?php

use App\Livewire\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('login page is reachable', function () {
    $this->get('/login')->assertOk();
});

test('a registered user can log in', function () {
    $user = User::factory()->create([
        'email' => 'joao@example.com',
        'password' => Hash::make('secret1234'),
    ]);

    Livewire::test(Login::class)
        ->set('form.email', 'joao@example.com')
        ->set('form.password', 'secret1234')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
    expect(auth()->id())->toBe($user->id);
});

test('invalid credentials show a generic error', function () {
    User::factory()->create([
        'email' => 'joao@example.com',
        'password' => Hash::make('secret1234'),
    ]);

    $component = Livewire::test(Login::class)
        ->set('form.email', 'joao@example.com')
        ->set('form.password', 'senha-errada')
        ->call('login')
        ->assertHasErrors(['form.email']);

    $error = $component->errors()->first('form.email');

    expect($error)->toBe('Credenciais inválidas.');
    expect(auth()->check())->toBeFalse();
});

test('remember me persists the session cookie', function () {
    User::factory()->create([
        'email' => 'remember@example.com',
        'password' => Hash::make('secret1234'),
    ]);

    Livewire::test(Login::class)
        ->set('form.email', 'remember@example.com')
        ->set('form.password', 'secret1234')
        ->set('form.remember', true)
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(auth()->user()->getRememberToken())->not->toBeNull();
});

test('authenticated user visiting /login is redirected to /', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/login')->assertRedirect('/');
});
