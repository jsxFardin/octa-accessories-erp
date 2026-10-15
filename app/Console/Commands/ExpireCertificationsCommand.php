<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Audit\AuditLogger;
use App\Support\Notifications\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CP-1 AC2/AC3 — the certificate registry keeps itself honest overnight.
 *
 * A certificate past `expires_on` becomes `expired`, so BR-43 stops a shipment claiming it
 * the morning after rather than whenever someone next opens the registry. One inside its
 * `reminder_days` window raises a notification to compliance and the MD, once per certificate
 * per expiry date, because re-certification takes about as long as the window.
 *
 * Both halves were in the specification and in no code: the dashboard tile showed the expiries
 * to whoever happened to look, and the status column never moved on its own.
 */
class ExpireCertificationsCommand extends Command
{
    protected $signature = 'certifications:expire {--dry-run : Report without changing anything or notifying anyone}';

    protected $description = 'Expire certificates past their date and remind compliance of the ones about to';

    public function handle(AuditLogger $audit, Notifier $notifier): int
    {
        $today = now()->toDateString();

        $lapsed = DB::table('certifications')
            ->where('status', 'active')
            ->whereDate('expires_on', '<', $today)
            ->orderBy('id')
            ->get(['id', 'scheme', 'certificate_no', 'expires_on']);

        $expiring = DB::table('certifications')
            ->where('status', 'active')
            ->whereDate('expires_on', '>=', $today)
            ->whereRaw('expires_on <= DATE_ADD(CURDATE(), INTERVAL reminder_days DAY)')
            ->orderBy('expires_on')
            ->get(['id', 'scheme', 'certificate_no', 'expires_on', 'reminder_days']);

        if ($this->option('dry-run')) {
            foreach ($lapsed as $row) {
                $this->line(sprintf('%s %s expired %s', $row->scheme, $row->certificate_no, $row->expires_on));
            }
            foreach ($expiring as $row) {
                $this->line(sprintf('%s %s expires %s', $row->scheme, $row->certificate_no, $row->expires_on));
            }

            $this->info(sprintf('%d would expire, %d reminder(s) would go out.', $lapsed->count(), $expiring->count()));

            return self::SUCCESS;
        }

        foreach ($lapsed as $row) {
            DB::transaction(function () use ($row, $audit): void {
                DB::table('certifications')->where('id', $row->id)->update(['status' => 'expired']);

                $audit->recordTable('certifications', (int) $row->id, 'status_changed', ['status' => 'active'], [
                    'status' => 'expired',
                    'because' => 'expires_on passed',
                ]);
            });
        }

        foreach ($expiring as $row) {
            $notifier->notifyCertificateExpiring($row);
        }

        $this->info(sprintf('%d certificate(s) expired, %d reminder(s) sent.', $lapsed->count(), $expiring->count()));

        return self::SUCCESS;
    }
}
