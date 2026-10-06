<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contract pricing: an agreed rate per product, by quantity break, for one customer.
 *
 * This is the one lookup that could not be a generic reference list — it is a header with
 * lines, and the lines are the point. A quotation for a customer with a current price list
 * should read the agreed rate rather than recomputing a cost sheet and hoping the margin
 * lands in the same place.
 *
 * Quantity breaks are stored as a `min_qty` floor per line: the applicable rate is the line
 * with the highest `min_qty` at or below the ordered quantity.
 */
class PriceListController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        // A list's standing today, from its dates and flag: what a merchandiser needs to know
        // before quoting, and what the list never said. The CASE is reused by the counts.
        $standing = "CASE
            WHEN pl.is_active = 0 THEN 'inactive'
            WHEN pl.valid_from > '{$today}' THEN 'upcoming'
            WHEN pl.valid_to IS NOT NULL AND pl.valid_to < '{$today}' THEN 'lapsed'
            WHEN pl.valid_to IS NOT NULL AND pl.valid_to <= '{$soon}' THEN 'ending'
            ELSE 'current' END";

        $base = fn () => DB::table('price_lists as pl')
            ->leftJoin('customers as c', 'c.id', '=', 'pl.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pl.currency_id');

        $term = $request->string('q')->toString();
        $sort = (string) $request->query('sort', '-id');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ['code' => 'pl.code', 'name' => 'pl.name', 'customer' => 'c.name', 'valid_from' => 'pl.valid_from', 'valid_to' => 'pl.valid_to', 'id' => 'pl.id'][ltrim($sort, '-')] ?? 'pl.id';

        $lists = $base()
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.$term.'%';
                $query->where(fn ($sub) => $sub
                    ->where('pl.code', 'like', $like)
                    ->orWhere('pl.name', 'like', $like)
                    ->orWhere('c.name', 'like', $like)
                    ->orWhere('c.code', 'like', $like));
            })
            ->when($request->filled('customer'), fn ($query) => $query->where('pl.customer_id', (int) $request->query('customer')))
            ->when($request->filled('standing'), fn ($query) => $query->whereRaw("({$standing}) = ?", [(string) $request->query('standing')]))
            ->orderBy($column, $direction)
            ->orderByDesc('pl.id')
            ->select([
                'pl.id', 'pl.code', 'pl.name', 'pl.valid_from', 'pl.valid_to', 'pl.is_active', 'pl.customer_id',
                'c.name as customer', 'cur.code as currency',
                DB::raw('(SELECT COUNT(*) FROM price_list_lines WHERE price_list_id = pl.id) as lines_count'),
                DB::raw('(SELECT COUNT(DISTINCT product_id) FROM price_list_lines WHERE price_list_id = pl.id) as products_count'),
                DB::raw("({$standing}) as standing"),
            ])
            ->paginate((int) $request->query('per_page', 25))
            ->withQueryString();

        return Inertia::render('Sales/PriceLists/Index', [
            'lists' => $lists,
            'filters' => $request->only(['q', 'sort', 'customer', 'standing']),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'code', 'name']),
            'counts' => DB::table('price_lists as pl')
                ->selectRaw("({$standing}) AS standing, COUNT(*) AS n")
                ->groupBy('standing')
                ->pluck('n', 'standing'),
        ]);
    }

    public function create(Request $request): Response
    {
        // Started from a customer's page: that customer is already chosen.
        $requested = (int) $request->query('customer');

        return Inertia::render('Sales/PriceLists/Form', [
            'list' => null,
            'preselectCustomerId' => $requested > 0 && Customer::query()->active()->whereKey($requested)->exists() ? $requested : null,
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $id = DB::transaction(function () use ($data): int {
            $id = (int) DB::table('price_lists')->insertGetId(collect($data)->except('lines')->all());

            $this->syncLines($id, $data['lines']);

            return $id;
        });

        $this->audit->recordTable('price_lists', $id, 'created', null, collect($data)->except('lines')->all());

        return redirect()->route('price-lists.show', $id)->with('success', 'Price list created.');
    }

    public function show(int $priceList): Response
    {
        $list = DB::table('price_lists as pl')
            ->leftJoin('customers as c', 'c.id', '=', 'pl.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pl.currency_id')
            ->where('pl.id', $priceList)
            ->select(['pl.*', 'c.name as customer', 'cur.code as currency'])
            ->first() ?? abort(404);

        $today = now()->toDateString();
        $standing = match (true) {
            ! $list->is_active => 'inactive',
            $list->valid_from > $today => 'upcoming',
            $list->valid_to !== null && $list->valid_to < $today => 'lapsed',
            $list->valid_to !== null && $list->valid_to <= now()->addDays(30)->toDateString() => 'ending',
            default => 'current',
        };

        return Inertia::render('Sales/PriceLists/Show', [
            'list' => [...(array) $list, 'standing' => $standing],
            // Another list for the same customer that is live on the same days: a quotation
            // raised on such a day could read either one.
            'overlapping' => DB::table('price_lists')
                ->where('customer_id', $list->customer_id)
                ->where('id', '!=', $list->id)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $list->valid_from))
                ->when($list->valid_to !== null, fn ($q) => $q->where('valid_from', '<=', $list->valid_to))
                ->get(['id', 'code', 'name', 'valid_from', 'valid_to']),
            'lines' => DB::table('price_list_lines as l')
                ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
                ->where('l.price_list_id', $priceList)
                ->orderBy('p.code')
                ->orderBy('l.min_qty')
                ->get(['l.id', 'l.min_qty', 'l.rate_per_m', 'l.description', 'p.code as product_code', 'p.name as product_name']),
        ]);
    }

    public function edit(int $priceList): Response
    {
        $list = DB::table('price_lists')->where('id', $priceList)->first() ?? abort(404);

        return Inertia::render('Sales/PriceLists/Form', [
            'list' => [
                ...(array) $list,
                'lines' => DB::table('price_list_lines')
                    ->where('price_list_id', $priceList)
                    ->orderBy('min_qty')
                    ->get(['id', 'product_id', 'description', 'min_qty', 'rate_per_m'])
                    ->all(),
            ],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, int $priceList): RedirectResponse
    {
        $existing = DB::table('price_lists')->where('id', $priceList)->first() ?? abort(404);
        $data = $this->validated($request, $priceList);

        DB::transaction(function () use ($priceList, $data): void {
            DB::table('price_lists')->where('id', $priceList)->update(collect($data)->except('lines')->all());
            $this->syncLines($priceList, $data['lines']);
        });

        $this->audit->recordTable('price_lists', $priceList, 'updated', (array) $existing, collect($data)->except('lines')->all());

        return redirect()->route('price-lists.show', $priceList)->with('success', 'Price list updated.');
    }

    public function destroy(int $priceList): RedirectResponse
    {
        // Deactivated, not deleted: a quotation raised last month was priced from this list
        // and its rate has to stay explicable.
        DB::table('price_lists')->where('id', $priceList)->update(['is_active' => false]);

        $this->audit->recordTable('price_lists', $priceList, 'updated', null, ['is_active' => false]);

        return redirect()->route('price-lists.index')->with('success', 'Price list deactivated.');
    }

    /**
     * Bring a deactivated list back.
     *
     * "Deactivate" had no way back: the form kept `is_active` but offered no control for it,
     * so a list switched off by mistake had to be typed in again from the first rate.
     */
    public function reactivate(int $priceList): RedirectResponse
    {
        $existing = DB::table('price_lists')->where('id', $priceList)->first() ?? abort(404);

        if ($existing->is_active) {
            return back()->with('error', "Price list {$existing->code} is already active.");
        }

        DB::table('price_lists')->where('id', $priceList)->update(['is_active' => true]);

        $this->audit->recordTable('price_lists', $priceList, 'updated', ['is_active' => false], ['is_active' => true]);

        return back()->with('success', "Price list {$existing->code} is active again. New quotations will read its rates.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('price_lists', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:120'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'valid_from' => ['required', 'date'],
            // A one-day list is a list; the check constraint allows equal dates and so does this.
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.min_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.rate_per_m' => ['required', 'numeric', 'min:0'],
        ], [
            'lines.required' => 'Add at least one rate.',
            'lines.min' => 'Add at least one rate.',
        ]);

        // Two active lists live on the same day for one customer would make the quoted rate a
        // matter of which row the query met first. Refused with the other list named.
        if (($data['is_active'] ?? true) !== false) {
            $overlap = DB::table('price_lists')
                ->where('customer_id', $data['customer_id'])
                ->where('is_active', true)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $data['valid_from']))
                ->when(! empty($data['valid_to']), fn ($q) => $q->where('valid_from', '<=', $data['valid_to']))
                ->first(['code', 'valid_from', 'valid_to']);

            if ($overlap !== null) {
                $until = $overlap->valid_to === null ? 'open-ended' : 'until '.$overlap->valid_to;

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'valid_from' => "These dates overlap {$overlap->code}, which is active from {$overlap->valid_from} ({$until}) for the same customer. End one list before the other starts, or deactivate it.",
                ]);
            }
        }

        return $data;
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(int $priceListId, array $lines): void
    {
        DB::table('price_list_lines')->where('price_list_id', $priceListId)->delete();

        foreach ($lines as $line) {
            DB::table('price_list_lines')->insert([
                'price_list_id' => $priceListId,
                'product_id' => $line['product_id'],
                'description' => $line['description'] ?? null,
                'min_qty' => $line['min_qty'],
                'rate_per_m' => $line['rate_per_m'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            // The customer's trading currency rides along so the form can default to it.
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'currency_id']),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'code', 'is_base']),
            'products' => DB::table('products')->where('status', '!=', 'obsolete')
                ->orderBy('code')->get(['id', 'code', 'name', 'customer_id']),
        ];
    }
}
