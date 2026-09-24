<?php

test('responses set X-Frame-Options to block the site being framed by another origin', function () {
    $this->get('/livestream')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('the admin panel also sets X-Frame-Options', function () {
    $this->get('/admin/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});
