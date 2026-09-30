<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A deliberately simple denylist-and-pattern check for chat messages and
 * display names — meant to catch overt profanity, slurs, and crude ASCII
 * art (e.g. "8====D"), not to be a bulletproof moderation system. A
 * determined user can still get around a word list like this one (creative
 * spelling, a different language entirely); this only raises the floor for
 * casual abuse, it doesn't replace the existing human moderation tools
 * (mute/delete) for anything that slips through.
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
