<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\States\QuotationStateMachine;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Console\Command;

/**
 * A sent quotation past its valid-until date is no longer an offer. The state machine has
 * always allowed `sent → expired`; nothing ever made the move, so every quotation that went
 * unanswered stayed "Sent" for ever and the list could not tell a live offer from a dead one.
 *
 * Runs nightly. A system transition: the guard still runs, the permission check does not,
 * because no person chose this — the calendar did. An expired quotation can still be revised.
 */
class ExpireQuotationsCommand extends Command
{
    protected $signature = 'quotations:expire {--dry-run : List what would expire without changing anything}';

    protected $description = 'Move sent quotations past their valid-until date to expired';

    public function handle(QuotationStateMachine $states): int
    {
        $due = Quotation::query()
            ->where('status', 'sent')
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->orderBy('id')
            ->get();

        if ($this->option('dry-run')) {
            foreach ($due as $quotation) {
                $this->line(sprintf('%s valid until %s', $quotation->reference(), $quotation->valid_until));
            }

            $this->info($due->count().' quotation(s) would expire.');

            return self::SUCCESS;
        }

        $expired = 0;

        foreach ($due as $quotation) {
            try {
                StateMachine::asSystem(fn () => $states->transition($quotation, 'expired', ['because' => 'valid_until passed']));
                $expired++;
            } catch (TransitionDenied $denied) {
                $this->warn(sprintf('%s not expired: %s', $quotation->reference(), $denied->getMessage()));
            }
        }

        $this->info($expired.' quotation(s) expired.');

        return self::SUCCESS;
    }
}
