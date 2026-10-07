<?php

namespace App\Console\Commands;

use App\Support\Payments\LegacyReconciliation;
use App\Support\Payments\ReconcileResult;
use Illuminate\Console\Command;

class ReconcileLegacyPayments extends Command
{
    protected $signature = 'payments:reconcile-legacy
        {--apply : Write the changes. Without it this only reports what would change}
        {--refund-owed : With --apply, also refund cancelled reservations that were owed a refund and never got it}';

    protected $description = 'One-off: ask Stripe about old unpaid/cancelled reservations and unfinished passes, and settle their payment state';

    public function handle(LegacyReconciliation $reconciliation): int
    {
        $apply = (bool) $this->option('apply');

        if ($this->option('refund-owed') && ! $apply) {
            $this->error('--refund-owed only makes sense together with --apply.');

            return self::FAILURE;
        }

        $this->components->info($apply
            ? 'Applying changes. Back up first if you have not: php artisan db:backup'
            : 'Dry run: nothing is modified. Re-run with --apply to write the changes.');

        $findings = $reconciliation->run($apply, (bool) $this->option('refund-owed'));

        if ($findings->isEmpty()) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->table(
            ['Type', 'ID', 'Stripe session', 'Finding', 'Result'],
            $findings->map(fn ($f) => [$f->kind, $f->id, $f->session, $f->description, $f->result->value])->all(),
        );

        $count = fn (ReconcileResult $result) => $findings->where('result', $result)->count();

        $this->line(sprintf(
            'Changed: %d · would change: %d · needs a decision: %d · unchanged: %d · failed: %d',
            $count(ReconcileResult::Changed),
            $count(ReconcileResult::WouldChange),
            $count(ReconcileResult::NeedsDecision),
            $count(ReconcileResult::Unchanged),
            $count(ReconcileResult::Failed),
        ));

        return $count(ReconcileResult::Failed) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
