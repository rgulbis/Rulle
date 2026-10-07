<?php

namespace App\Support\CheckIn;

use App\Models\UsedQrToken;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The entry QR code: `v1.<user id>.<expires at>.<nonce>.<signature>`.
 *
 * Signed with a key made from the app key and the user's own `qr_code`
 * secret, so nothing can be forged without the server, and rotating that
 * secret (closing an account does) kills every token already out. It is
 * short-lived (see config/checkin.php) and single-use: scanning it for real
 * records its nonce, and a second scan of the same token is refused.
 *
 * The `qr_code` column itself never leaves the server.
 */
final class QrToken
{
    private const VERSION = 'v1';

    private const FORMAT = '/^v1\.(\d{1,18})\.(\d{10})\.([a-f0-9]{24})\.([A-Za-z0-9_-]{43})$/';

    public static function ttl(): int
    {
        return max(1, (int) config('checkin.token_ttl_seconds'));
    }

    /**
     * @return array{token: string, ttl: int}
     */
    public static function issue(User $user): array
    {
        $expiresAt = now()->getTimestamp() + self::ttl();
        $unsigned = implode('.', [self::VERSION, $user->id, $expiresAt, bin2hex(random_bytes(12))]);

        return [
            'token' => $unsigned.'.'.self::sign($unsigned, $user),
            'ttl' => self::ttl(),
        ];
    }

    /**
     * Whether the token is genuine and still fresh. Reading only: it is not
     * spent until consume().
     */
    public static function verify(string $code): VerifiedQrToken|QrTokenProblem
    {
        if (! preg_match(self::FORMAT, $code, $parts)) {
            return QrTokenProblem::Invalid;
        }

        [, $userId, $expiresAt, $nonce, $signature] = $parts;

        $user = User::find((int) $userId);

        if (! $user) {
            return QrTokenProblem::Invalid;
        }

        $unsigned = implode('.', [self::VERSION, $userId, $expiresAt, $nonce]);

        if (! hash_equals(self::sign($unsigned, $user), $signature)) {
            return QrTokenProblem::Invalid;
        }

        // Only after the signature: a stranger can't learn anything from
        // being told "expired" about a token that was never ours.
        if ((int) $expiresAt < now()->getTimestamp()) {
            return QrTokenProblem::Expired;
        }

        return new VerifiedQrToken($user, $nonce, (int) $expiresAt);
    }

    /**
     * Spends the token. False if it was already spent. Atomic: the unique
     * index lets exactly one of several simultaneous scans through.
     */
    public static function consume(VerifiedQrToken $token): bool
    {
        try {
            UsedQrToken::create([
                'nonce' => $token->nonce,
                'expires_at' => now()->setTimestamp($token->expiresAt),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private static function sign(string $unsigned, User $user): string
    {
        $key = hash_hmac('sha256', 'checkin-qr|'.$user->qr_code, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $unsigned, $key, true)), '+/', '-_'), '=');
    }
}
