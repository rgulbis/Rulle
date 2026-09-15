<?php

use App\Models\User;

test('normalizeEmailForLookup converts a Unicode domain to its ASCII form', function () {
    expect(User::normalizeEmailForLookup('admin@rullē.lv'))
        ->toBe('admin@xn--rull-eva.lv');
});

test('normalizeEmailForLookup leaves an already-ASCII email untouched', function () {
    expect(User::normalizeEmailForLookup('admin@xn--rull-eva.lv'))
        ->toBe('admin@xn--rull-eva.lv');

    expect(User::normalizeEmailForLookup('someone@gmail.com'))
        ->toBe('someone@gmail.com');
});

test('normalizeEmailForLookup leaves a malformed value without an @ untouched', function () {
    expect(User::normalizeEmailForLookup('not-an-email'))
        ->toBe('not-an-email');
});
