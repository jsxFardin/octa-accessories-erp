<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Factory and office spend, with an approval on it.
 *
 * Small document, one job: make the money that leaves outside the purchase-order path
 * visible. Generator fuel, courier, port charges, a lunch for an audit team — none of it goes
 * through a PO, all of it is real, and a system that only knows about purchase orders reports
 * a factory that costs less to run than it does.
 *
 * `approve` is a permission of its own, and the person who raised the expense cannot use it on
 * their own document. That single rule is most of what an expense module is for.
 */
class ExpenseController extends Controller
{
    use ListsResources;

    public function __construct(private readonly NumberAllocator $numbers) {}

    public function index(Request $request): Response
    {
        $query = Expense::query()->with(['category:id,code,name', 'supplier:id,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'payee', 'reference_no', 'description'],
            filters: ['status' => 'status', 'category' => 'expense_category_id', 'method' => 'method'],
            sortable: ['number', 'expense_date', 'total', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Finance/Expenses/Index', [
            'expenses' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Expense $expense): array => [
                    ...$expense->only(['id', 'number', 'expense_date', 'payee', 'description',
                        'amount', 'tax_amount', 'total', 'method', 'status', 'paid_on']),
                    'category' => $expense->category?->name,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'category', 'method']),
            'categories' => ExpenseCategory::query()->active()->orderBy('name')->get(['id', 'name']),
            'methods' => Expense::METHODS,
            'statuses' => Expense::STATUSES,
            // The number people actually want off this screen: what has been spent, on what,
            // under the filters currently applied.
            'totals' => $this->totals($request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Finance/Expenses/Form', ['expense' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $expense = DB::transaction(function () use ($data, $request): Expense {
            return Expense::query()->create([
                ...$data,
                'number' => $this->numbers->next('expense'),
                'total' => round((float) $data['amount'] + (float) ($data['tax_amount'] ?? 0), 4),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('expenses.index')
            ->with('success', "Expense {$expense->number} saved as a draft.");
    }

    public function edit(Expense $expense): Response
    {
        return Inertia::render('Finance/Expenses/Form', [
            'expense' => $expense,
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        // An approved expense is a decision somebody signed; changing the amount underneath it
        // would make the approval meaningless. Cancel and re-raise instead.
        abort_unless(in_array($expense->status, ['draft', 'pending_approval'], true), 422,
            'An approved or paid expense cannot be edited. Cancel it and raise a new one.');

        $data = $this->validated($request);

        $expense->update([
            ...$data,
            'total' => round((float) $data['amount'] + (float) ($data['tax_amount'] ?? 0), 4),
        ]);

        return redirect()->route('expenses.index')->with('success', "Expense {$expense->number} updated.");
    }

    /** Submit, approve, pay or cancel. */
    public function transition(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Expense::STATUSES)],
            'paid_on' => ['nullable', 'date'],
            'reference_no' => ['nullable', 'string', 'max:80'],
        ]);

        $allowed = match ($expense->status) {
            'draft' => ['pending_approval', 'cancelled'],
            'pending_approval' => ['approved', 'draft', 'cancelled'],
            'approved' => ['paid', 'cancelled'],
            default => [],
        };

        abort_unless(in_array($data['status'], $allowed, true), 422,
            "An expense cannot go from {$expense->status} to {$data['status']}.");

        if ($data['status'] === 'approved') {
            abort_unless($request->user()->hasPermission('expense.approve'), 403);

            // Nobody approves their own spend. The rule is worth more than the rest of the
            // module and costs one line.
            abort_if($expense->created_by === $request->user()->id, 422,
                'An expense has to be approved by somebody other than the person who raised it.');
        }

        if ($data['status'] === 'paid') {
            abort_unless($request->user()->hasPermission('expense.pay'), 403);
        }

        $expense->forceFill(array_filter([
            'status' => $data['status'],
            'approved_by' => $data['status'] === 'approved' ? $request->user()->id : null,
            'approved_at' => $data['status'] === 'approved' ? now() : null,
            'paid_on' => $data['status'] === 'paid' ? ($data['paid_on'] ?? now()->toDateString()) : null,
            'reference_no' => $data['reference_no'] ?? null,
        ], fn ($value): bool => $value !== null))->save();

        return back()->with('success', "Expense {$expense->number} is now {$data['status']}.");
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        abort_unless($expense->status === 'draft', 422, 'Only a draft expense can be deleted.');

        $expense->delete();

        return redirect()->route('expenses.index')->with('success', 'Draft expense deleted.');
    }

    /**
     * Spend under the current filters, in base currency and split by category.
     *
     * @return array<string, mixed>
     */
    private function totals(Request $request): array
    {
        $base = Expense::query()->whereNotIn('status', ['cancelled', 'draft']);

        foreach (['status' => 'status', 'category' => 'expense_category_id', 'method' => 'method'] as $key => $column) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $base->where($column, $value);
            }
        }

        return [
            'committed' => round((float) (clone $base)->sum(DB::raw('total * exchange_rate')), 2),
            'paid' => round((float) (clone $base)->where('status', 'paid')->sum(DB::raw('total * exchange_rate')), 2),
            'byCategory' => (clone $base)
                ->join('expense_categories as c', 'c.id', '=', 'expenses.expense_category_id')
                ->groupBy('c.name')
                ->orderByDesc(DB::raw('SUM(total * exchange_rate)'))
                ->limit(6)
                ->get([DB::raw('c.name as category'), DB::raw('ROUND(SUM(total * exchange_rate), 2) as amount')]),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'factory_unit_id' => ['nullable', 'integer', 'exists:factory_units,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'import_shipment_id' => ['nullable', 'integer', 'exists:import_shipments,id'],
            'payee' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:500'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_amount' => ['numeric', 'min:0'],
            'method' => ['required', Rule::in(Expense::METHODS)],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'reference_no' => ['nullable', 'string', 'max:80'],
        ]);
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'categories' => ExpenseCategory::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'kind']),
            'factoryUnits' => DB::table('factory_units')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => DB::table('departments')->orderBy('name')->get(['id', 'code', 'name', 'factory_unit_id']),
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'bankAccounts' => DB::table('bank_accounts')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'shipments' => DB::table('import_shipments')->whereNotIn('status', ['closed', 'cancelled'])
                ->orderByDesc('id')->limit(50)->get(['id', 'number', 'invoice_no']),
            'methods' => Expense::METHODS,
        ];
    }
}
