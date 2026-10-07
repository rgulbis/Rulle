<?php

namespace App\Support\Payments;

/**
 * One row looked at by LegacyReconciliation and what was (or would be) done to it.
 */
final readonly class ReconcileFinding
{
    public function __construct(
        public string $kind,
        public int $id,
        public string $session,
        public string $description,
        public ReconcileResult $result,
    ) {}
}
