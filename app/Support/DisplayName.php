<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Normalizer;
use Spoofchecker;

/**
 * What counts as an acceptable display name, and when two names are "the
 * same person" for the purpose of uniqueness.
 *
 * Names show up next to chat messages and in reservation groups, so two
 * rules matter beyond "not already in the table": no invisible or direction-
 * changing characters (they let a name hide or reorder), and no near-copies
 * of someone else's name - `EMPLOYEE` for `Employee`, or a Greek capital
 * Epsilon in place of a Latin E - which would let a rider pass as staff.
 */
final class DisplayName
{
    /**
     * Control, format (zero-width, direction overrides), unassigned,
     * private-use and surrogate code points; line/paragraph separators;
     * every space except a plain U+0020; and angle brackets. Invalid UTF-8
     * makes the match fail, which is treated as forbidden too.
     */
    private const FORBIDDEN = '/[\p{C}\p{Zl}\p{Zp}<>]|[^\S ]|(?! )\p{Zs}/u';

    public static function hasForbiddenCharacters(string $name): bool
    {
        return preg_match(self::FORBIDDEN, $name) !== 0;
    }

    /**
     * Unicode-compatibility-normalised, case-folded, whitespace-collapsed.
     * Two names with the same key are the same name as far as uniqueness
     * goes.
     */
    public static function key(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);
        $folded = mb_convert_case($normalized === false ? $name : $normalized, MB_CASE_FOLD, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $folded));
    }

    /**
     * True when the two names are the same after folding, or when ICU
     * considers them visually confusable *and* a non-Latin script is
     * involved (a Cyrillic "а" for a Latin "a"). Plain-Latin pairs are not
     * run through the confusable check: it also pairs innocent names such as
     * "rn" and "m", which would turn away real riders.
     */
    public static function conflicts(string $a, string $b): bool
    {
        $keyA = self::key($a);
        $keyB = self::key($b);

        if ($keyA === $keyB) {
            return true;
        }

        if (! preg_match('/[^\p{Latin}\p{Common}\p{Inherited}]/u', $keyA.$keyB)) {
            return false;
        }

        // ICU compares case-sensitively and some lookalikes only exist in
        // one case (Greek capital Ε for E, but small ε does not resemble e),
        // so check the names as written and in both lowercase and uppercase.
        $checker = new Spoofchecker;

        return $checker->areConfusable($a, $b)
            || $checker->areConfusable($keyA, $keyB)
            || $checker->areConfusable(mb_strtoupper($keyA, 'UTF-8'), mb_strtoupper($keyB, 'UTF-8'));
    }

    /**
     * Whether any other account - closed ones included, as the unique index
     * is - already has, or has asked for, a name that conflicts with this
     * one. A user's own current and pending names never count against them.
     *
     * Reads every name rather than a precomputed key column: it only runs
     * when someone registers or asks for a name change, and there are far
     * too few accounts for that to matter.
     */
    public static function isTaken(string $name, ?int $exceptUserId = null): bool
    {
        $rows = DB::table('users')
            ->when($exceptUserId !== null, fn ($query) => $query->where('id', '!=', $exceptUserId))
            ->select('name', 'pending_name')
            ->cursor();

        foreach ($rows as $row) {
            foreach ([$row->name, $row->pending_name] as $other) {
                if (is_string($other) && $other !== '' && self::conflicts($name, $other)) {
                    return true;
                }
            }
        }

        return false;
    }
}
