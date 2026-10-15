<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The export register: every export credit with what shipped under it, when the documents went
 * to the bank and whether the proceeds came back — the list Bangladesh Bank's EXP reconciliation
 * and the merchandiser's Monday morning both read.
 */
class ExportRegisterReport extends ReportQuery
{
    public function key(): string
    {
        return 'export-register';
    }

    public function title(): string
    {
        return 'Export register';
    }

    public function subtitle(): string
    {
        return 'Export LCs with their shipments, EXP forms, presentation and realisation. Drawn counts issued invoices; available is the credit plus tolerance less drawn.';
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'label' => 'Credit'],
            ['key' => 'lc_no', 'label' => 'Bank LC no.'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'],
            ['key' => 'drawn_amount', 'label' => 'Drawn', 'align' => 'right', 'format' => 'money'],
            ['key' => 'realised_amount', 'label' => 'Realised', 'align' => 'right', 'format' => 'money'],
            ['key' => 'available_amount', 'label' => 'Available', 'align' => 'right', 'format' => 'money'],
            ['key' => 'currency', 'label' => 'Currency'],
            ['key' => 'last_shipment_date', 'label' => 'Ship by', 'format' => 'date'],
            ['key' => 'expiry_date', 'label' => 'Expires', 'format' => 'date'],
            ['key' => 'shipments', 'label' => 'Shipments', 'align' => 'right'],
            ['key' => 'exp_forms', 'label' => 'EXP forms'],
            ['key' => 'presented_on', 'label' => 'Presented', 'format' => 'date'],
            ['key' => 'realised_on', 'label' => 'Realised on', 'format' => 'date'],
            ['key' => 'realisation_ref', 'label' => 'Realisation ref.'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/export-lcs';
    }

    public function currencyColumn(): ?string
    {
        return 'currency';
    }

    public function baseRateColumn(): ?string
    {
        return 'base_rate';
    }

    public function filterFields(): array
    {
        $statuses = ['advised', 'accepted', 'shipped', 'presented', 'discrepant', 'realised', 'closed', 'cancelled'];
        $customers = DB::table('customers')->orderBy('name')->get(['id', 'name']);

        return [
            ['key' => 'status', 'label' => 'Status', 'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], $statuses)],
            ['key' => 'customer', 'label' => 'Customer', 'options' => $customers->map(fn ($c): array => ['value' => $c->id, 'label' => $c->name])->all()],
            ['key' => 'unrealised', 'label' => 'Proceeds', 'options' => [['value' => '1', 'label' => 'Shipped, not yet realised']]],
        ];
    }

    protected function base(Request $request): Builder
    {
        $amendments = DB::table('lc_amendments')->groupBy('lc_id')->selectRaw('lc_id, SUM(amount_delta) as delta');
        $drawn = DB::table('sales_invoices')->whereNotIn('status', ['draft', 'cancelled'])->whereNotNull('lc_id')
            ->groupBy('lc_id')->selectRaw('lc_id, SUM(total) as amount');
        $realised = DB::table('receipts')->where('status', 'posted')->whereNotNull('lc_id')
            ->groupBy('lc_id')->selectRaw('lc_id, SUM(amount) as amount');
        $shipments = DB::table('export_shipments')->whereNotIn('status', ['cancelled'])->whereNotNull('lc_id')
            ->groupBy('lc_id')->selectRaw("lc_id, COUNT(*) as n, GROUP_CONCAT(DISTINCT exp_form_no ORDER BY exp_form_no SEPARATOR ', ') as exp_forms");

        $query = DB::table('letters_of_credit as lc')
            ->join('customers as c', 'c.id', '=', 'lc.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'lc.currency_id')
            ->leftJoinSub($amendments, 'am', 'am.lc_id', '=', 'lc.id')
            ->leftJoinSub($drawn, 'dr', 'dr.lc_id', '=', 'lc.id')
            ->leftJoinSub($realised, 're', 're.lc_id', '=', 'lc.id')
            ->leftJoinSub($shipments, 'sh', 'sh.lc_id', '=', 'lc.id')
            ->where('lc.direction', 'export')
            ->selectRaw('
                lc.id,
                lc.number,
                lc.lc_no,
                c.name as customer,
                lc.status,
                ROUND(lc.amount + COALESCE(am.delta, 0), 4) as amount,
                COALESCE(dr.amount, 0) as drawn_amount,
                COALESCE(re.amount, 0) as realised_amount,
                ROUND((lc.amount + COALESCE(am.delta, 0)) * (1 + lc.tolerance_pct / 100) - COALESCE(dr.amount, 0), 4) as available_amount,
                cur.code as currency,
                COALESCE(lc.exchange_rate, 1) as base_rate,
                lc.last_shipment_date,
                lc.expiry_date,
                COALESCE(sh.n, 0) as shipments,
                sh.exp_forms,
                lc.presented_on,
                lc.realised_on,
                lc.realisation_ref
            ')
            ->orderByDesc('lc.id');

        if ($request->query('unrealised') === '1') {
            $query->whereIn('lc.status', ['shipped', 'presented', 'discrepant']);
        }

        if ($request->filled('status')) {
            $query->where('lc.status', $request->query('status'));
        }

        if ($request->filled('customer')) {
            $query->where('lc.customer_id', $request->integer('customer'));
        }

        $this->applyDate($query, $request, 'lc.issued_on');

        return $this->applySearch($query, $request, 'lc.number', 'lc.lc_no', 'c.name');
    }
}
