<?php

use App\Modules\Sales\Services\InquiryProgression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs inquiry statuses left behind by a lifecycle that was drawn and never wired up.
 *
 * 05-workflows §1 has the inquiry following its quotations — `open` → `quoted` when one is
 * raised, `quoted` → `won` when one is accepted, `lost` when the last one is rejected — but
 * nothing in the application performed those transitions. Inquiries answered by an accepted
 * quotation, one of them already carrying a sales order, still read `open`, while others read
 * `won` only because the demo seeder set the column by hand. The funnel report and the
 * merchandiser's follow-up list both read that column.
 *
 * The status is derivable from the quotations themselves, so it is recomputed rather than
 * guessed. Only inquiries whose stored status disagrees with their own quotations are touched,
 * and only where the quotations decide the answer — an inquiry that was cancelled, or that was
 * marked lost with no bid, keeps what a person put there. Each repair writes the same
 * `status_changed` audit row the application now writes.
 */
return new class extends Migration
{
    /** Statuses a person, not the quotation chain, decided. */
    private const DECIDED_BY_HAND = ['cancelled', 'lost'];

    public function up(): void
    {
        $progression = app(InquiryProgression::class);

        DB::transaction(function () use ($progression): void {
            $inquiries = DB::table('inquiries')
                ->whereNotIn('status', self::DECIDED_BY_HAND)
                ->get(['id', 'status']);

            foreach ($inquiries as $inquiry) {
                $derived = $progression->derivedStatusFor((int) $inquiry->id);

                if ($derived === null || $derived === $inquiry->status) {
                    continue;
                }

                DB::table('inquiries')->where('id', $inquiry->id)->update(['status' => $derived]);

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'auditable_type' => 'App\\Modules\\Sales\\Models\\Inquiry',
                    'auditable_id' => $inquiry->id,
                    'event' => 'status_changed',
                    'old_values' => json_encode(['status' => $inquiry->status], JSON_THROW_ON_ERROR),
                    'new_values' => json_encode([
                        'status' => $derived,
                        'because' => 'backfill',
                        'detail' => 'Recomputed from this inquiry\'s quotations (05-workflows §1); '
                            .'the lifecycle was documented but never applied.',
                    ], JSON_THROW_ON_ERROR),
                    'ip_address' => null,
                    'user_agent' => 'migration/backfill-inquiry-status',
                    'created_at' => now(),
                ]);
            }
        });
    }

    /**
     * Irreversible by design: the statuses this replaces were wrong, and the rows it wrote say
     * what each one was. Rolling forward from the audit trail is the recovery path.
     */
    public function down(): void {}
};
