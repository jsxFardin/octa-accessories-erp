<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\ListsResources;
use App\Support\Settings\SettingCatalogue;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The costing clerk's own screens (spec §4): every sheet across the three stages in one
 * list, and the rates every sheet draws on in one place. The sheets themselves are still
 * made where the work happens — the quotation, the order's confirmation, the job's close.
 */
class CostingController extends Controller
{
    use ListsResources;

    public function __construct(private readonly Settings $settings) {}

    public function sheets(Request $request): Response
    {
        $term = trim((string) $request->string('q'));
        $sort = (string) $request->query('sort', '-id');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = [
            'number' => 'cs.number', 'stage' => 'cs.stage', 'status' => 'cs.status', 'product' => 'pi.code',
            'customer' => 'c.name', 'basis_qty' => 'cs.basis_qty', 'unit_cost' => 'cs.unit_cost',
            'revenue_per_unit' => 'cs.revenue_per_unit', 'created_at' => 'cs.created_at', 'id' => 'cs.id',
        ][ltrim($sort, '-')] ?? 'cs.id';

        $floor = $this->settings->decimal('margin_floor_pct', 12);

        $sheets = DB::table('cost_sheets as cs')
            ->leftJoin('products as p', 'p.id', '=', 'cs.product_id')
            ->leftJoin('items as pi', 'pi.id', '=', 'p.item_id')
            ->leftJoin('quotation_lines as ql', 'ql.id', '=', 'cs.quotation_line_id')
            ->leftJoin('quotations as q', 'q.id', '=', 'ql.quotation_id')
            ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'cs.sales_order_line_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->leftJoin('job_cards as jc', 'jc.id', '=', 'cs.job_card_id')
            // The buyer: the quotation's, the order's, or the product's own.
            ->leftJoin('customers as c', 'c.id', '=', DB::raw('COALESCE(q.customer_id, so.customer_id, p.customer_id)'))
            ->leftJoin('currencies as cur', 'cur.id', '=', 'cs.currency_id')
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.$term.'%';
                $query->where(fn ($sub) => $sub
                    ->where('cs.number', 'like', $like)
                    ->orWhere('q.number', 'like', $like)
                    ->orWhere('so.number', 'like', $like)
                    ->orWhere('jc.number', 'like', $like)
                    ->orWhere('pi.code', 'like', $like)
                    ->orWhere('pi.name', 'like', $like)
                    ->orWhere('c.name', 'like', $like));
            })
            ->when($request->filled('stage'), fn ($query) => $query->where('cs.stage', (string) $request->query('stage')))
            ->when($request->filled('status'), fn ($query) => $query->where('cs.status', (string) $request->query('status')))
            ->when($request->filled('customer'), fn ($query) => $query->where('c.id', (int) $request->query('customer')))
            ->when($request->filled('product'), fn ($query) => $query->where('cs.product_id', (int) $request->query('product')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('cs.created_at', '>=', (string) $request->query('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('cs.created_at', '<=', (string) $request->query('to')))
            // "Below the floor": a sheet whose price leaves less margin than the floor allows.
            ->when($request->query('below_floor') === '1', fn ($query) => $query
                ->whereNotNull('cs.revenue_per_unit')->where('cs.revenue_per_unit', '>', 0)
                ->whereRaw('(cs.revenue_per_unit - cs.unit_cost) / cs.revenue_per_unit * 100 < ?', [$floor]))
            ->orderBy($column, $direction)
            ->orderByDesc('cs.id')
            ->select([
                'cs.id', 'cs.number', 'cs.revision_no', 'cs.stage', 'cs.status', 'cs.basis_qty', 'cs.costing_per',
                'cs.unit_cost', 'cs.revenue_per_unit', 'cs.margin_pct', 'cs.is_locked', 'cs.created_at', 'cs.variance_reason',
                'cs.quotation_line_id', 'cs.sales_order_line_id', 'cs.job_card_id',
                'p.id as product_id', 'pi.code as product_code', 'pi.name as product_name',
                'q.id as quotation_id', 'q.number as quotation_number',
                'so.id as sales_order_id', 'so.number as sales_order_number',
                'jc.id as job_card_id_ref', 'jc.number as job_card_number',
                'c.id as customer_id', 'c.name as customer_name', 'cur.code as currency',
            ])
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn ($row): array => [
                ...(array) $row,
                'margin' => $row->revenue_per_unit > 0 ? round(((float) $row->revenue_per_unit - (float) $row->unit_cost) / (float) $row->revenue_per_unit * 100, 2) : null,
                'document' => match (true) {
                    $row->job_card_id !== null => ['label' => $row->job_card_number ?? 'Job card', 'href' => "/job-cards/{$row->job_card_id}"],
                    $row->sales_order_line_id !== null => ['label' => $row->sales_order_number ?? 'Sales order', 'href' => "/sales-orders/{$row->sales_order_id}"],
                    $row->quotation_line_id !== null => ['label' => $row->quotation_number ?? 'Quotation', 'href' => "/quotations/{$row->quotation_id}"],
                    default => null,
                },
            ]);

        return Inertia::render('Costing/Sheets/Index', [
            'sheets' => $sheets,
            'filters' => $request->only(['q', 'sort', 'stage', 'status', 'customer', 'product', 'from', 'to', 'below_floor']),
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'code', 'name']),
            'marginFloorPct' => $floor,
            'counts' => DB::table('cost_sheets')->selectRaw('stage, COUNT(*) AS n')->groupBy('stage')->pluck('n', 'stage'),
        ]);
    }

    /**
     * Every rate a sheet draws on, in one place, each linking to where it is edited: the
     * factory's rates from Settings, the machines' hourly rates, the materials' standard and
     * weighted-average rates with the last price paid.
     */
    public function rates(Request $request): Response
    {
        $term = trim((string) $request->string('q'));

        $lastPaid = DB::table('grn_lines as gl')
            ->join('grns as g', 'g.id', '=', 'gl.grn_id')
            ->where('g.status', 'posted')
            ->selectRaw('gl.item_id, MAX(g.id) AS grn_id')
            ->groupBy('gl.item_id');

        $materials = DB::table('items as i')
            ->leftJoin('uoms as u', 'u.id', '=', 'i.base_uom_id')
            ->leftJoinSub($lastPaid, 'lp', 'lp.item_id', '=', 'i.id')
            ->leftJoin('grn_lines as gl', fn ($join) => $join->on('gl.item_id', '=', 'i.id')->on('gl.grn_id', '=', 'lp.grn_id'))
            ->leftJoin('grns as g', 'g.id', '=', 'lp.grn_id')
            ->where('i.make_or_buy', 'buy')
            ->where('i.status', '!=', 'discontinued')
            ->when($term !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('i.code', 'like', "%{$term}%")->orWhere('i.name', 'like', "%{$term}%")))
            ->orderBy('i.code')
            ->select([
                'i.id', 'i.code', 'i.name', 'i.std_rate', 'i.avg_rate', 'i.standard_wastage_pct', 'u.code as uom',
                DB::raw('MAX(gl.landed_rate) as last_rate'), DB::raw('MAX(g.received_on) as last_received_on'),
            ])
            ->groupBy('i.id', 'i.code', 'i.name', 'i.std_rate', 'i.avg_rate', 'i.standard_wastage_pct', 'u.code')
            ->paginate($this->perPage($request, 50))
            ->withQueryString();

        return Inertia::render('Costing/Rates', [
            'factory' => collect(SettingCatalogue::ENTRIES)
                ->filter(fn (array $entry): bool => $entry[0] === 'costing')
                ->map(fn (array $entry, string $key): array => [
                    'key' => $key,
                    'label' => $entry[1],
                    'unit' => $entry[2],
                    'hint' => $entry[3],
                    'value' => $this->settings->get($key),
                ])
                ->values(),
            'machines' => DB::table('machines as m')
                ->join('machine_groups as g', 'g.id', '=', 'm.machine_group_id')
                ->where('m.is_active', true)
                ->orderBy('g.code')->orderBy('m.code')
                ->get(['m.id', 'm.code', 'm.name', 'g.name as group_name', 'm.std_rate_per_hour', 'm.hourly_rate', 'm.kw_rating', 'm.efficiency_pct', 'm.status']),
            'materials' => $materials,
            'filters' => $request->only(['q']),
        ]);
    }

    /** The settings a sheet starts from, for a page that is not the settings page. */
    public static function factoryRateKeys(): array
    {
        return ['overhead_pct', 'admin_pct', 'default_margin_pct', 'margin_floor_pct', 'labour_rate_per_hour', 'tariff_per_kwh'];
    }
}
