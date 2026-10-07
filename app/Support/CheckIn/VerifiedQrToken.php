<?php

namespace App\Support\CheckIn;

use App\Models\User;

final readonly class VerifiedQrToken
{
    public function __construct(
        public User $user,
        public string $nonce,
        public int $expiresAt,
    ) {}
}
