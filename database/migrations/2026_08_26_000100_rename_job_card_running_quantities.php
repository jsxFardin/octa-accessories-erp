<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * J6 — the column names now say which quantity they are.
 *
 * A job card carries four different quantities and three of them used to be spelled the way
 * the canonical one is:
 *
 *   planned_qty            what the card was raised to make
 *   produced_qty           ┐
 *   good_qty               ├ running totals, accumulated across EVERY operation
 *   waste_qty              ┘
 *   v_job_card_output      the job's actual output — the final operation's (J6)
 *   fg_receipts.qty        what of it reached the finished-goods store
 *
 * The running columns add quantities that do not share a unit: weaving books metres, packing
 * books pieces. On a card that wove 407 m and packed 30,000 labels, `good_qty` read 60,457
 * against a plan of 30,000, and the job-card list printed it. Every screen was moved onto
 * `v_job_card_output`, but the column kept a name that invites the next developer to make the
 * same mistake — `SELECT good_qty FROM job_cards` reads exactly like "the good quantity".
 *
 * So the suffix is the fix: `good_qty_running` cannot be misread as a final figure. The
 * columns are kept, not dropped — they are the cheapest answer to "has anything at all been
 * booked against this card?", which is what the cancellation guard asks.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const RENAMES = [
        'produced_qty' => 'produced_qty_running',
        'good_qty' => 'good_qty_running',
        'waste_qty' => 'waste_qty_running',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            $this->rename($from, $to);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            $this->rename($to, $from);
        }
    }

    private function rename(string $from, string $to): void
    {
        $exists = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['job_cards', $from],
        );

        if ((int) $exists->c === 0) {
            return;
        }

        DB::statement("ALTER TABLE job_cards RENAME COLUMN `{$from}` TO `{$to}`");
    }
};
