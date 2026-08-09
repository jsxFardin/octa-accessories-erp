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
        $lists = DB::table('price_lists as pl')
            ->leftJoin('customers as c', 'c.id', '=', 'pl.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pl.currency_id')
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(fn ($sub) => $sub->where('pl.code', 'like', $term)->orWhere('pl.name', 'like', $term));
            })
            ->orderByDesc('pl.id')
            ->select([
                'pl.id', 'pl.code', 'pl.name', 'pl.valid_from', 'pl.valid_to', 'pl.is_active',
                'c.name as customer', 'cur.code as currency',
                DB::raw('(SELECT COUNT(*) FROM price_list_lines WHERE price_list_id = pl.id) as lines_count'),
            ])
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Sales/PriceLists/Index', [
            'lists' => $lists,
            'filters' => $request->only(['q', 'sort']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/PriceLists/Form', ['list' => null, ...$this->options()]);
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

        return Inertia::render('Sales/PriceLists/Show', [
            'list' => $list,
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

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('price_lists', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:120'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after:valid_from'],
            'is_active' => ['boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.min_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.rate_per_m' => ['required', 'numeric', 'min:0'],
        ]);
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
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'code', 'is_base']),
            'products' => DB::table('products')->where('status', '!=', 'obsolete')
                ->orderBy('code')->get(['id', 'code', 'name', 'customer_id']),
        ];
    }
}
