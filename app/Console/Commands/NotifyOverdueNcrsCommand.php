<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Ncr;
use App\Support\Notifications\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P2-4 — notify NCR owners of overdue CAPAs. Does not change NCR status.
 */
class NotifyOverdueNcrsCommand extends Command
{
    protected $signature = 'ncr:notify-overdue';

    protected $description = 'Notify NCR owners when an open CAPA is past its due date';

    public function handle(Notifier $notifier): int
    {
        $rows = DB::table('capas')
            ->join('ncrs', 'ncrs.id', '=', 'capas.ncr_id')
            ->whereNotNull('capas.due_date')
            ->where('capas.due_date', '<', now()->toDateString())
            ->whereNotIn('capas.status', [Capa::COMPLETED, Capa::VERIFIED])
            ->where('ncrs.status', '!=', Ncr::CLOSED)
            ->whereNotNull('ncrs.owner_id')
            ->select('ncrs.id', 'capas.due_date')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $ncr = Ncr::query()->find($row->id);

            if ($ncr === null) {
                continue;
            }

            try {
                $notifier->notifyNcrOverdue($ncr, (string) $row->due_date);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info($rows->count().' overdue NCR CAPA(s) considered.');

        return self::SUCCESS;
    }
}
