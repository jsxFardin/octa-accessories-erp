<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Modules\Quality\Models\Ncr;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NCR / CAPA status. One row per NCR; corrective and preventive CAPAs are pivoted so a
 * two-row investigation does not duplicate the non-conformance. Overdue is a CAPA due date
 * in the past while that CAPA is still open and the NCR is not closed — the same rule as
 * the NCR index count.
 */
class NcrCapaReport extends ReportQuery
{
    public function key(): string
    {
        return 'ncr-capa';
    }

    public function title(): string
    {
        return 'NCR / CAPA';
    }

    public function subtitle(): string
    {
        return 'Non-conformances, owners, CAPA due dates and verification';
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'label' => 'NCR'],
            ['key' => 'source', 'label' => 'Source'],
            ['key' => 'severity', 'label' => 'Severity'],
            ['key' => 'owner', 'label' => 'Owner'],
            ['key' => 'status', 'label' => 'NCR status', 'format' => 'status'],
            ['key' => 'raised_on', 'label' => 'Raised', 'format' => 'date'],
            ['key' => 'capa_due_date', 'label' => 'CAPA due', 'format' => 'date'],
            ['key' => 'overdue', 'label' => 'Overdue'],
            ['key' => 'root_cause', 'label' => 'Root cause'],
            ['key' => 'corrective_action', 'label' => 'Corrective'],
            ['key' => 'preventive_action', 'label' => 'Preventive'],
            ['key' => 'effectiveness', 'label' => 'Verification'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/ncrs';
    }

    public function filterFields(): array
    {
        $statuses = [Ncr::OPEN, Ncr::INVESTIGATING, Ncr::ACTION_TAKEN, Ncr::VERIFIED, Ncr::CLOSED];
        $severities = ['critical', 'major', 'minor'];
        $owners = DB::table('users')->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return [
            ['key' => 'status', 'label' => 'Status', 'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => str_replace('_', ' ', ucfirst($s))], $statuses)],
            ['key' => 'severity', 'label' => 'Severity', 'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], $severities)],
            ['key' => 'owner', 'label' => 'Owner', 'options' => $owners->map(fn ($u): array => ['value' => $u->id, 'label' => $u->name])->all()],
        ];
    }

    protected function base(Request $request): Builder
    {
        $capas = DB::table('capas')
            ->groupBy('ncr_id')
            ->selectRaw("
                ncr_id,
                MAX(CASE WHEN kind = 'corrective' THEN action END) as corrective_action,
                MAX(CASE WHEN kind = 'preventive' THEN action END) as preventive_action,
                MAX(CASE WHEN kind = 'corrective' THEN root_cause END) as root_cause,
                MIN(due_date) as capa_due_date,
                MAX(CASE WHEN kind = 'corrective' THEN effectiveness END) as effectiveness,
                MAX(CASE WHEN due_date IS NOT NULL
                          AND due_date < CURDATE()
                          AND status NOT IN ('completed', 'verified')
                     THEN 1 ELSE 0 END) as capa_overdue
            ");

        $query = DB::table('ncrs as n')
            ->leftJoin('users as u', 'u.id', '=', 'n.owner_id')
            ->leftJoinSub($capas, 'ca', 'ca.ncr_id', '=', 'n.id')
            ->selectRaw("
                n.id,
                n.number,
                n.source,
                n.severity,
                u.name as owner,
                n.status,
                n.raised_on,
                ca.capa_due_date,
                CASE
                    WHEN COALESCE(ca.capa_overdue, 0) = 1 AND n.status != 'closed' THEN 'yes'
                    ELSE 'no'
                END as overdue,
                ca.root_cause,
                ca.corrective_action,
                ca.preventive_action,
                COALESCE(ca.effectiveness, n.status) as effectiveness
            ")
            ->orderByDesc('n.id');

        $this->applySearch($query, $request, 'n.number', 'u.name');
        $this->applyDate($query, $request, 'n.raised_on');

        if ($request->query('status')) {
            $query->where('n.status', $request->query('status'));
        }

        if ($request->query('severity')) {
            $query->where('n.severity', $request->query('severity'));
        }

        if ($request->query('owner')) {
            $query->where('n.owner_id', $request->query('owner'));
        }

        return $query;
    }
}
