<?php

use App\Models\User;
use App\Rules\AvailableDisplayName;

function failsAvailableDisplayName(string $value, ?int $ignoreUserId = null): bool
{
    $failed = false;

    (new AvailableDisplayName($ignoreUserId))->validate('name', $value, function () use (&$failed) {
        $failed = true;
    });

    return $failed;
}

test('ordinary names, including ones with diacritics and spaces, are accepted', function () {
    expect(failsAvailableDisplayName('Jānis Bērziņš'))->toBeFalse();
    expect(failsAvailableDisplayName('Mary-Jane O\'Neil'))->toBeFalse();
    expect(failsAvailableDisplayName('Сергей'))->toBeFalse();
});

test('invisible, direction-changing, control and markup characters are refused', function (string $name) {
    expect(failsAvailableDisplayName($name))->toBeTrue();
})->with([
    'zero-width space' => "a\u{200B}b",
    'right-to-left override' => "\u{202E}evil",
    'bell control character' => "a\u{0007}b",
    'null byte' => "a\0b",
    'tab' => "a\tb",
    'newline' => "a\nb",
    'non-breaking space' => "a\u{00A0}b",
    'ideographic space' => "a\u{3000}b",
    'html tag' => '<img src=x onerror=alert(1)>',
    'angle bracket' => 'a<b',
    'invalid utf-8' => "a\xC3\x28b",
]);

test('a name that differs only by case from an existing one is taken', function () {
    User::factory()->create(['name' => 'Employee']);

    expect(failsAvailableDisplayName('EMPLOYEE'))->toBeTrue();
    expect(failsAvailableDisplayName('employee'))->toBeTrue();
});

test('a name that differs only by a lookalike letter from another script is taken', function () {
    User::factory()->create(['name' => 'Employee']);
    User::factory()->create(['name' => 'Admin']);

    // Greek capital Epsilon, Cyrillic small a.
    expect(failsAvailableDisplayName("\u{0395}mployee"))->toBeTrue();
    expect(failsAvailableDisplayName("\u{0430}dmin"))->toBeTrue();
});

test('a name that someone else has asked for counts as taken', function () {
    User::factory()->create(['name' => 'Someone', 'pending_name' => 'Wanted Name']);

    expect(failsAvailableDisplayName('wanted name'))->toBeTrue();
});

test('closed accounts keep their name reserved', function () {
    $user = User::factory()->create(['name' => 'Gone Rider']);
    $user->delete();

    expect(failsAvailableDisplayName('Gone Rider'))->toBeTrue();
});

test('your own current and pending names never count against you', function () {
    $user = User::factory()->create(['name' => 'Roberts', 'pending_name' => 'Rob']);

    expect(failsAvailableDisplayName('ROBERTS', $user->id))->toBeFalse();
    expect(failsAvailableDisplayName('rob', $user->id))->toBeFalse();
});

test('innocent Latin names that merely look alike are not blocked', function () {
    // "rn" and "m" are ICU-confusable, but both names are plain Latin.
    User::factory()->create(['name' => 'Amis']);

    expect(failsAvailableDisplayName('Arnis'))->toBeFalse();
});

test('non-string input is left to the string rule', function () {
    $failed = false;

    (new AvailableDisplayName)->validate('name', ['x'], function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});
