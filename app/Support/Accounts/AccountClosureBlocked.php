<?php

namespace App\Support\Accounts;

use RuntimeException;

/**
 * An account can't be closed right now. The message says why, in words an
 * admin can act on.
 */
class AccountClosureBlocked extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
