<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Console\Command;

/**
 * An issued invoice past its due date is overdue (05-workflows §11, FN-6).
 *
 * The state machine has always allowed `issued → overdue` and `partially_paid → overdue`, and
 * the move is what tells accounts and the MD (`Notifier::notifyInvoiceOverdue`) — but nothing
 * made it except a person remembering to. Runs nightly; a system transition, because the
 * calendar decided this, not a user.
 */
class MarkOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:mark-overdue {--dry-run : List what would become overdue without changing anything}';

    protected $description = 'Move issued and partly paid invoices past their due date to overdue';

    public function handle(SalesInvoiceStateMachine $states): int
    {
        $due = SalesInvoice::query()
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->orderBy('id')
            ->get();

        if ($this->option('dry-run')) {
            foreach ($due as $invoice) {
                $this->line(sprintf('%s due %s', $invoice->number, $invoice->due_date?->toDateString()));
            }

            $this->info($due->count().' invoice(s) would become overdue.');

            return self::SUCCESS;
        }

        $moved = 0;

        foreach ($due as $invoice) {
            try {
                StateMachine::asSystem(fn () => $states->transition($invoice, 'overdue', ['because' => 'due_date passed']));
                $moved++;
            } catch (TransitionDenied $denied) {
                $this->warn(sprintf('%s left as is: %s', $invoice->number, $denied->getMessage()));
            }
        }

        $this->info($moved.' invoice(s) marked overdue.');

        return self::SUCCESS;
    }
}
