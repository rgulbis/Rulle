<?php

namespace App\Support\CheckIn;

enum QrTokenProblem
{
    /** Not one of ours, tampered with, or belongs to a closed account. */
    case Invalid;

    /** Genuine, but older than its lifetime. */
    case Expired;
}
