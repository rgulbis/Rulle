<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckInEvent extends Model
{
    protected $fillable = [
        'user_id',
        'checked_in',
    ];

    protected function casts(): array
    {
        return [
            'checked_in' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
