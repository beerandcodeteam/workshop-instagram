<?php

test('guest is redirected from / to /login', function () {
    $this->get('/')->assertRedirect('/login');
});

test('guest is redirected from any post-action endpoint to /login', function () {
    $this->get('/posts/create')->assertRedirect('/login');
});
