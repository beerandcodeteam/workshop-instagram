<?php

use App\Models\User;

test('an authenticated user can log out', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->post('/logout');

    $response->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});

test('logout requires POST', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/logout')->assertStatus(405);
});
