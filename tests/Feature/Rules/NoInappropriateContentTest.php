<?php

use App\Rules\NoInappropriateContent;

function failsNoInappropriateContent(string $value): bool
{
    $failed = false;

    (new NoInappropriateContent)->validate('field', $value, function () use (&$failed) {
        $failed = true;
    });

    return $failed;
}

test('rejects common English profanity', function () {
    expect(failsNoInappropriateContent('this park is shit'))->toBeTrue();
});

test('rejects common Latvian profanity', function () {
    expect(failsNoInappropriateContent('kurva laba diena'))->toBeTrue();
});

test('rejects crude ASCII art', function () {
    expect(failsNoInappropriateContent('hey look 8====D nice'))->toBeTrue();
});

test('does not flag ordinary text', function () {
    expect(failsNoInappropriateContent('See you at the park at 5pm!'))->toBeFalse();
    expect(failsNoInappropriateContent('Vai parks ir vaļā šodien?'))->toBeFalse();
});

test('does not flag innocent substrings that merely contain a banned word', function () {
    // "Dickens" contains "dick" — word-boundary matching should let this through.
    expect(failsNoInappropriateContent('Have you read any Dickens novels?'))->toBeFalse();
});
