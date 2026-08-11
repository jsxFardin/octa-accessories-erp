<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Sales\Models\Inquiry;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use App\Support\Reference\Vocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The front of the funnel (05-workflows §1). An inquiry is numbered when it is submitted,
 * not when the form opens (BR-34).
 */
class InquiryController extends Controller
{
    use ListsResources;

    public function __construct(private readonly NumberAllocator $numbers) {}

    public function index(Request $request): Response
    {
        $query = Inquiry::query()->with('customer:id,code,name')->withCount('lines');

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'notes'],
            filters: ['status' => 'status', 'customer' => 'customer_id'],
            sortable: ['number', 'inquiry_date', 'required_by', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Sales/Inquiries/Index', [
            'inquiries' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Inquiry $inquiry): array => [
                    ...$inquiry->only(['id', 'number', 'inquiry_date', 'required_by', 'status', 'source', 'lost_reason']),
                    'customer' => $inquiry->customer?->name,
                    'lines_count' => $inquiry->lines_count,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer']),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Inquiries/Form', [
            'inquiry' => null,
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'productTypes' => Vocabulary::options('product_type'),
            'sources' => Vocabulary::options('inquiry_source'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $inquiry = DB::transaction(function () use ($data, $request): Inquiry {
            $inquiry = Inquiry::query()->create([
                ...collect($data)->except('lines')->all(),
                'status' => 'draft',
                'merchandiser_id' => $request->user()->id,
                'created_by' => $request->user()->id,
            ]);

            $this->syncLines($inquiry, $data['lines']);

            return $inquiry;
        });

        return redirect()->route('inquiries.show', $inquiry)->with('success', 'Inquiry saved as a draft.');
    }

    public function show(Inquiry $inquiry): Response
    {
        $inquiry->load(['customer', 'lines.product:id,code,name']);

        return Inertia::render('Sales/Inquiries/Show', [
            'inquiry' => [
                ...$inquiry->only(['id', 'number', 'inquiry_date', 'required_by', 'source', 'status', 'lost_reason', 'notes']),
                'customer' => $inquiry->customer?->only(['id', 'code', 'name']),
            ],
            'lines' => $inquiry->lines->map(fn ($line): array => [
                ...$line->only(['id', 'line_no', 'description', 'product_type', 'qty', 'target_rate_per_m', 'notes']),
                'product' => $line->product?->only(['id', 'code', 'name']),
            ]),
            'quotations' => DB::table('quotations')->where('inquiry_id', $inquiry->id)
                ->orderByDesc('id')->get(['id', 'number', 'revision_no', 'quotation_date', 'total', 'status']),
        ]);
    }

    public function edit(Inquiry $inquiry): Response
    {
        // Once an inquiry has been quoted, its lines are what the quotation was costed from;
        // changing them behind the quote is how a customer ends up billed for a different job.
        if (! in_array($inquiry->status, ['draft', 'open'], true)) {
            abort(403, 'Only a draft or open inquiry can be edited.');
        }

        $inquiry->load('lines');

        return Inertia::render('Sales/Inquiries/Form', [
            'inquiry' => [
                ...$inquiry->only([
                    'id', 'number', 'customer_id', 'customer_contact_id', 'brand_id',
                    'inquiry_date', 'required_by', 'source', 'notes', 'status',
                ]),
                'lines' => $inquiry->lines->map(fn ($line): array => $line->only([
                    'id', 'line_no', 'product_id', 'description', 'product_type', 'qty',
                    'target_rate_per_m', 'notes',
                ]))->all(),
            ],
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'productTypes' => Vocabulary::options('product_type'),
            'sources' => Vocabulary::options('inquiry_source'),
        ]);
    }

    public function update(Request $request, Inquiry $inquiry): RedirectResponse
    {
        if (! in_array($inquiry->status, ['draft', 'open'], true)) {
            return back()->with('error', 'Only a draft or open inquiry can be edited.');
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($inquiry, $data): void {
            $inquiry->update(collect($data)->except('lines')->all());
            $this->syncLines($inquiry, $data['lines']);
        });

        return redirect()->route('inquiries.show', $inquiry)->with('success', 'Inquiry updated.');
    }

    /**
     * The transitions the inquiry state machine allows, applied inline because this document
     * has no side effects beyond numbering and a lost reason (05-workflows §1).
     */
    public function transition(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'open', 'quoted', 'won', 'lost', 'cancelled'])],
            'lost_reason' => ['nullable', 'string', 'max:255', 'required_if:status,lost'],
        ]);

        if ($data['status'] === 'open' && $inquiry->lines()->count() === 0) {
            return back()->with('error', 'An inquiry needs at least one line before it is submitted.');
        }

        DB::transaction(function () use ($inquiry, $data): void {
            if ($data['status'] !== 'draft' && $inquiry->number === null) {
                $inquiry->forceFill(['number' => $this->numbers->next('inquiry')])->save();
            }

            $inquiry->update($data);
        });

        return back()->with('success', "Inquiry moved to {$data['status']}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'customer_contact_id' => ['nullable', 'integer', 'exists:customer_contacts,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'inquiry_date' => ['required', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:inquiry_date'],
            'source' => ['nullable', Rule::in(Vocabulary::codes('inquiry_source'))],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.product_type' => ['nullable', Rule::in(Vocabulary::codes('product_type'))],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.target_rate_per_m' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(Inquiry $inquiry, array $lines): void
    {
        $inquiry->lines()->delete();

        foreach ($lines as $index => $line) {
            $inquiry->lines()->create([
                'line_no' => $index + 1,
                'product_id' => $line['product_id'] ?? null,
                'description' => $line['description'],
                'product_type' => $line['product_type'] ?? null,
                'qty' => $line['qty'],
                'target_rate_per_m' => $line['target_rate_per_m'] ?? null,
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }
}
