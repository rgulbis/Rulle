<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nonce
 * @property Carbon $expires_at
 */
#[Fillable(['nonce', 'expires_at'])]
class UsedQrToken extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * An expired token is refused before its nonce is ever looked up, so
     * its row has nothing left to protect.
     *
     * @return Builder<UsedQrToken>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }
}
