<?php

test('guests are redirected from root to login', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
