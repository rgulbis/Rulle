<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A short denylist of words plus one ASCII-art pattern, applied to chat
 * messages and display names. That is all it is: it refuses the most obvious
 * abuse and is easy to get around (different spelling, spacing, another
 * language, anything not on the list), and it also has no idea of context. It
 * is not content moderation or content safety, and nothing should be built on
 * the assumption that text which passes it is acceptable — that is what the
 * mute/delete tools and the admin review of requested names are for.
 */
class NoInappropriateContent implements ValidationRule
{
    private const BANNED_WORDS = [
        // English
        'fuck', 'fucking', 'shit', 'bitch', 'asshole', 'cunt', 'dick', 'whore', 'slut',
        'nigger', 'nigga', 'faggot', 'retard',
        // Latvian
        'kurva', 'pizda', 'chuja', 'huj', 'suka', 'ebat', 'jobana',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if ($this->containsBannedWord($value) || $this->containsAsciiArt($value)) {
            $fail(__('That contains language that isn\'t allowed here.'));
        }
    }

    private function containsBannedWord(string $value): bool
    {
        $words = array_map(fn (string $word) => preg_quote($word, '/'), self::BANNED_WORDS);
        $pattern = '/\b('.implode('|', $words).')\b/iu';

        return (bool) preg_match($pattern, $value);
    }

    /**
     * Classic ASCII-art genitalia, e.g. "8====D" or "3===>".
     */
    private function containsAsciiArt(string $value): bool
    {
        return (bool) preg_match('/[368]={3,}[Dd>)]/', $value);
    }
}
