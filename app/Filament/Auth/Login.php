<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    // One login form for everyone, admins included — this page never
    // actually renders, it just bounces straight to the regular one
    // (which already does the same Unicode-email normalization this page
    // used to do itself — see User::normalizeEmailForLookup's other callers).
    public function mount(): void
    {
        $this->redirect('/login');
    }
}
