<?php

namespace App\Support\Payments;

enum ReconcileResult: string
{
    /** Looked at, nothing to do. */
    case Unchanged = 'unchanged';

    /** Dry run: this is what applying would change. */
    case WouldChange = 'would change';

    /** Applied. */
    case Changed = 'changed';

    /** Not touched until someone decides (e.g. an owed refund needs --refund-owed). */
    case NeedsDecision = 'needs decision';

    /** Stripe could not be asked, or the change failed. */
    case Failed = 'failed';
}
