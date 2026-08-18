<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Costing\Services\CostSheetService;
use App\Modules\Dispatch\Models\Carton;
use App\Modules\Dispatch\Models\CartonContent;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Dispatch\Models\Trip;
use App\Modules\Dispatch\Models\TripStop;
use App\Modules\Dispatch\States\DeliveryChallanStateMachine;
use App\Modules\Dispatch\States\PackingListStateMachine;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\FgReceiptService;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Product\Models\Artwork;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Quality\Models\QcInspection;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\States\QuotationStateMachine;
use App\Modules\Sales\States\SalesOrderStateMachine;
use App\Support\Calculators\AqlResolver;
use App\Support\Calculators\CapacityCalculator;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Calculators\CostSheetCalculator;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local-only volume: 100 inquiries parked across the commercial-to-cash path, plus a
 * catalogue of other product types, import, buying and dispatch so lists are not empty.
 * Tests never run this — they already have the single walkthrough order.
 *
 *   1–20   inquiry draft
 *   21–30  inquiry open
 *   31–40  inquiry lost
 *   41–52  quotation draft
 *   53–62  quotation sent
 *   63–67  quotation rejected
 *   68–75  sales order draft (accepted quote)
 *   76–85  sales order confirmed
 *   86–90  job card planned
 *   91–95  job card in production
 *   96–100 packed → challan issued → invoice issued
 */
class LocalProcessSeeder extends Seeder
{
    private int $userId;

    private int $merchandiserId;

    private int $qcEmployeeId;

    private int $usdId;

    private int $termId;

    private int $unitId;

    private Product $product;

    private ProductSpec $spec;

    private Artwork $artwork;

    /** @var list<array{id: int, address_id: int}> */
    private array $buyers = [];

    public function run(): void
    {
        Auth::login(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

        $this->userId = (int) Auth::id();
        $this->merchandiserId = (int) DB::table('employees')->where('code', 'EMP-0003')->value('id');
        $this->qcEmployeeId = (int) DB::table('employees')->where('code', 'EMP-0012')->value('id');
        $this->usdId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
        $this->termId = (int) DB::table('payment_terms')->where('code', 'NET60')->value('id');
        $this->unitId = (int) DB::table('factory_units')->where('code', 'ML-1')->value('id');
        $this->product = Product::query()
            ->with(['routing.operations', 'activeBom'])
            ->where('code', 'PRD-NFJ-CARE-01')
            ->firstOrFail();
        $this->spec = $this->product->currentSpec()->firstOrFail();
        $this->artwork = Artwork::query()->where('code', 'ART-NFJ-CARE-01')->firstOrFail();

        $this->discardFailedJourneys();
        $this->buyers();
        $this->call(LocalCatalogueSeeder::class);

        $done = (int) Inquiry::query()->where('notes', 'like', 'local-volume:%')->count();

        if ($done >= 100) {
            $this->command?->info('Local process volume already seeded (100 inquiries).');
        } else {
            for ($i = $done + 1; $i <= 100; $i++) {
                DB::transaction(fn () => $this->journey($i));

                if ($i % 20 === 0) {
                    $this->command?->info("Local volume: {$i}/100");
                }
            }

            $this->command?->info('100 local journeys seeded (inquiry → quote → order → job → dispatch → invoice).');
        }

        $this->trips();
        Auth::logout();
    }

    /** A failed run can leave an unnumbered draft past slot 20; drop those so we can retry. */
    private function discardFailedJourneys(): void
    {
        Inquiry::query()
            ->where('notes', 'like', 'local-volume:%')
            ->whereNull('number')
            ->where('status', 'draft')
            ->get()
            ->each(function (Inquiry $inquiry): void {
                $slot = (int) str_replace('local-volume:', '', (string) $inquiry->notes);

                if ($slot > 20) {
                    $inquiry->lines()->delete();
                    $inquiry->delete();
                }
            });
    }

    private function buyers(): void
    {
        if (DB::table('customers')->where('code', 'CUST-L-01')->exists()) {
            foreach (range(1, 10) as $index) {
                $code = 'CUST-L-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
                $id = (int) DB::table('customers')->where('code', $code)->value('id');
                $addressId = (int) DB::table('customer_addresses')->where('customer_id', $id)->where('is_default', true)->value('id');
                $this->buyers[] = ['id' => $id, 'address_id' => $addressId];
            }

            return;
        }
        $houses = [
            ['Ha-Meem Group', 'Savar'],
            ['DBL Group', 'Gazipur'],
            ['Square Fashions', 'Kaliakoir'],
            ['Ananta Apparels', 'Ashulia'],
            ['Palmal Group', 'Mirpur'],
            ['Fakir Apparels', 'Narayanganj'],
            ['Epyllion Group', 'Tongi'],
            ['Opex Garments', 'Chattogram'],
            ['Vintage Denim Studio', 'CEPZ'],
            ['Pacific Jeans', 'Chattogram'],
        ];

        foreach ($houses as $index => [$name, $city]) {
            $code = 'CUST-L-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

            DB::table('customers')->insert([
                'code' => $code,
                'name' => $name,
                'kind' => 'manufacturer',
                'email' => strtolower(str_replace(' ', '.', $code)).'@local.test',
                'currency_id' => $this->usdId,
                'payment_term_id' => $this->termId,
                'credit_limit' => 2_000_000,
                'min_order_value' => 5_000,
                'under_tolerance_pct' => 5,
                'over_tolerance_pct' => 5,
                'is_active' => true,
            ]);

            $id = (int) DB::table('customers')->where('code', $code)->value('id');

            $addressId = (int) DB::table('customer_addresses')->insertGetId([
                'customer_id' => $id,
                'label' => 'Factory',
                'kind' => 'delivery',
                'line1' => $name.' factory',
                'city' => $city,
                'country' => 'Bangladesh',
                'transit_days' => 1,
                'route_zone' => $city,
                'is_default' => true,
            ]);

            $this->buyers[] = ['id' => $id, 'address_id' => $addressId];
        }
    }

    private function journey(int $i): void
    {
        $buyer = $this->buyers[($i - 1) % count($this->buyers)];
        $offer = $this->offerFor($buyer);
        $qty = 2_000 + (($i * 250) % 18_000);
        $sources = ['email', 'phone', 'visit', 'buying_house', 'agent', 'repeat'];
        $day = now()->subDays(100 - $i);

        $inquiry = Inquiry::query()->create([
            'customer_id' => $buyer['id'],
            'inquiry_date' => $day->toDateString(),
            'required_by' => $day->copy()->addWeeks(6)->toDateString(),
            'source' => $sources[($i - 1) % count($sources)],
            'merchandiser_id' => $this->merchandiserId,
            'status' => 'draft',
            'notes' => 'local-volume:'.$i,
            'created_by' => $this->userId,
        ]);

        $inquiry->lines()->create([
            'line_no' => 1,
            'product_id' => $offer['product']->id,
            'description' => $offer['product']->name,
            'product_type' => $offer['product']->product_type,
            'qty' => $qty,
            'target_rate_per_m' => 12.5,
        ]);

        if ($i <= 20) {
            return;
        }

        $this->submitInquiry($inquiry);

        if ($i <= 30) {
            return;
        }

        if ($i <= 40) {
            $inquiry->update([
                'status' => 'lost',
                'lost_reason' => ['Price', 'Lead time', 'Capacity', 'Lost to competitor'][($i - 1) % 4],
            ]);

            return;
        }

        $quote = $this->quotation($inquiry, $buyer['id'], $qty, $day, $offer);

        if ($i <= 52) {
            $inquiry->update(['status' => 'quoted']);

            return;
        }

        app(QuotationStateMachine::class)->transition($quote, 'sent');
        $inquiry->update(['status' => 'quoted']);

        if ($i <= 62) {
            return;
        }

        if ($i <= 67) {
            app(QuotationStateMachine::class)->transition($quote->refresh(), 'rejected', [
                'reject_reason' => 'Customer placed with another mill.',
            ]);
            $inquiry->update(['status' => 'lost', 'lost_reason' => 'Quote rejected']);

            return;
        }

        app(QuotationStateMachine::class)->transition($quote->refresh(), 'accepted');
        $inquiry->update(['status' => 'won']);

        $order = $this->salesOrder($quote->refresh(), $buyer, $i, $day, $offer);

        if ($i <= 75) {
            return;
        }

        app(SalesOrderStateMachine::class)->transition($order->refresh(), 'confirmed');
        $order = $order->refresh();

        if ($i <= 85) {
            return;
        }

        $job = $this->jobCard($order, $qty, $day, $offer);

        if ($i <= 90) {
            return;
        }

        $states = app(JobCardStateMachine::class);
        $states->transition($job, JobCard::RELEASED, [
            'material_waiver_reason' => 'Local volume seed — material waived so the floor can be shown.',
        ]);
        $states->transition($job->refresh(), JobCard::IN_PRODUCTION);

        $produce = min(5_000, $qty);
        $job->operations()->update(['input_qty' => $produce, 'good_qty' => $produce]);
        DB::table('sales_order_lines')->where('id', $job->sales_order_line_id)->increment('produced_qty', $produce);

        if ($i <= 95) {
            return;
        }

        $this->dispatchAndInvoice($job->refresh(), $order->refresh(), $produce, $offer);
    }

    /**
     * @param  array{id: int, address_id: int}  $buyer
     * @return array{product: Product, spec: ProductSpec, artwork: Artwork}
     */
    private function offerFor(array $buyer): array
    {
        $product = Product::query()
            ->with(['routing.operations', 'activeBom'])
            ->where('customer_id', $buyer['id'])
            ->where('code', 'like', 'PRD-L-%')
            ->first();

        if ($product === null) {
            return [
                'product' => $this->product,
                'spec' => $this->spec,
                'artwork' => $this->artwork,
            ];
        }

        return [
            'product' => $product,
            'spec' => $product->currentSpec()->firstOrFail(),
            'artwork' => Artwork::query()->where('product_id', $product->id)->firstOrFail(),
        ];
    }

    private function submitInquiry(Inquiry $inquiry): void
    {
        $inquiry->forceFill([
            'number' => app(NumberAllocator::class)->next('inquiry'),
            'status' => 'open',
        ])->save();
    }

    /**
     * @param  array{product: Product, spec: ProductSpec, artwork: Artwork}  $offer
     */
    private function quotation(Inquiry $inquiry, int $customerId, int $qty, \Illuminate\Support\Carbon $day, array $offer): Quotation
    {
        $product = $offer['product'];
        $spec = $offer['spec'];
        $costing = app(CostSheetService::class);
        $calculator = app(CostSheetCalculator::class);
        $sheet = $costing->calculate($product, $spec, $qty, [
            'marginPct' => 22.0,
            'exchangeRate' => 122.50,
            'currency' => 'USD',
        ]);
        $rate = round($sheet->ratePerMInCurrency, 4);
        $lineTotal = $calculator->lineValue($qty, $rate);

        /** @var Quotation $quote */
        $quote = Quotation::query()->create([
            'inquiry_id' => $inquiry->id,
            'customer_id' => $customerId,
            'quotation_date' => $day->copy()->addDays(2)->toDateString(),
            'valid_until' => $day->copy()->addDays(32)->toDateString(),
            'currency_id' => $this->usdId,
            'exchange_rate' => 122.50,
            'payment_term_id' => $this->termId,
            'merchandiser_id' => $this->merchandiserId,
            'subtotal' => $lineTotal,
            'tax_amount' => 0,
            'total' => $lineTotal,
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);

        $line = $quote->lines()->create([
            'line_no' => 1,
            'product_id' => $product->id,
            'product_spec_id' => $spec->id,
            'description' => $product->name,
            'qty' => $qty,
            'rate_per_m' => $rate,
            'tooling_charge' => 0,
            'line_total' => $lineTotal,
            'lead_time_days' => 28,
        ]);

        $costing->persist($product, $spec, $qty, [
            'marginPct' => 22.0,
            'exchangeRate' => 122.50,
        ], $line->id);

        return $quote;
    }

    /**
     * @param  array{id: int, address_id: int}  $buyer
     * @param  array{product: Product, spec: ProductSpec, artwork: Artwork}  $offer
     */
    private function salesOrder(Quotation $quote, array $buyer, int $i, \Illuminate\Support\Carbon $day, array $offer): SalesOrder
    {
        $quote->load('lines');

        /** @var SalesOrder $order */
        $order = SalesOrder::query()->create([
            'quotation_id' => $quote->id,
            'customer_id' => $buyer['id'],
            'customer_po_no' => 'LPO-26-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'order_date' => $day->copy()->addDays(5)->toDateString(),
            'delivery_date' => $day->copy()->addDays(35)->toDateString(),
            'currency_id' => $quote->currency_id,
            'exchange_rate' => $quote->exchange_rate,
            'payment_term_id' => $quote->payment_term_id,
            'delivery_address_id' => $buyer['address_id'],
            'merchandiser_id' => $this->merchandiserId,
            'factory_unit_id' => $this->unitId,
            'priority' => $i >= 96 ? 'high' : 'normal',
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);

        $subtotal = 0.0;
        $calculator = app(CostSheetCalculator::class);

        foreach ($quote->lines as $index => $line) {
            $lineTotal = $calculator->lineValue((int) $line->qty, (float) $line->rate_per_m);
            $subtotal += $lineTotal;

            SalesOrderLine::query()->create([
                'sales_order_id' => $order->id,
                'line_no' => $index + 1,
                'product_id' => $line->product_id,
                'product_spec_id' => $line->product_spec_id ?? $offer['spec']->id,
                'description' => $line->description,
                'ordered_qty' => $line->qty,
                'rate_per_m' => $line->rate_per_m,
                'tooling_charge' => $line->tooling_charge,
                'line_total' => $lineTotal,
                'over_tolerance_pct' => 5,
                'under_tolerance_pct' => 5,
                'status' => 'open',
            ]);
        }

        $order->forceFill(['subtotal' => $subtotal, 'total' => $subtotal])->save();

        return $order;
    }

    /**
     * @param  array{product: Product, spec: ProductSpec, artwork: Artwork}  $offer
     */
    private function jobCard(SalesOrder $order, int $qty, \Illuminate\Support\Carbon $day, array $offer): JobCard
    {
        $product = $offer['product'];
        $spec = $offer['spec'];
        $line = $order->lines()->firstOrFail();
        $approved = $offer['artwork']->approvedVersion()->firstOrFail();
        $plan = (new ConsumptionCalculator)->plan(
            $spec->toCalculatorInput($product->product_type),
            $qty,
            $product->routing->toCalculatorSteps(),
            $spec->colourWeights(),
        );

        /** @var JobCard $jobCard */
        $jobCard = JobCard::query()->create([
            'factory_unit_id' => $this->unitId,
            'sales_order_line_id' => $line->id,
            'product_id' => $product->id,
            'product_spec_id' => $spec->id,
            'artwork_version_id' => $approved->id,
            'bom_id' => $product->activeBom?->id,
            'routing_id' => $product->routing_id,
            'colourway' => 'White / Navy',
            'planned_qty' => $qty,
            'due_date' => $day->copy()->addDays(21)->toDateString(),
            'priority' => 40,
            'gross_metres' => $plan->grossMetres,
            'ends' => $plan->ends,
            'labels_per_metre' => $plan->labelsPerMetre,
            'status' => JobCard::DRAFT,
            'created_by' => $this->userId,
        ]);

        $machines = DB::table('machines')->pluck('id', 'machine_group_id');
        $capacity = new CapacityCalculator;
        $start = $day->copy()->addDays(6)->startOfDay()->addHours(6);

        foreach ($product->routing->operations as $operation) {
            $outputUnits = $operation->consumes_web ? $plan->grossMetres : (float) $qty;
            $minutes = $capacity->loadMinutes(
                $outputUnits,
                (float) ($operation->std_rate_per_hour ?? 0),
                (float) $operation->setup_minutes,
            );

            JobCardOperation::query()->create([
                'job_card_id' => $jobCard->id,
                'routing_operation_id' => $operation->id,
                'sequence_no' => $operation->sequence_no,
                'code' => $operation->code,
                'name' => $operation->name,
                'machine_group_id' => $operation->machine_group_id,
                'machine_id' => $machines[$operation->machine_group_id] ?? null,
                'planned_qty' => $outputUnits,
                'planned_minutes' => round($minutes, 2),
                'scheduled_start' => $start,
                'scheduled_finish' => $start->copy()->addMinutes((int) $minutes),
                'requires_qc' => $operation->requires_qc,
                'status' => JobCardOperation::PENDING,
            ]);

            $start = $start->copy()->addMinutes((int) $minutes + 30);
        }

        app(JobCardStateMachine::class)->transition($jobCard, JobCard::PLANNED);

        return $jobCard->refresh();
    }

    /**
     * @param  array{product: Product, spec: ProductSpec, artwork: Artwork}  $offer
     */
    private function dispatchAndInvoice(JobCard $job, SalesOrder $order, int $produce, array $offer): void
    {
        $aql = app(AqlResolver::class)->inspect($produce, 0, 0);
        $numbers = app(NumberAllocator::class);

        QcInspection::query()->create([
            'number' => $numbers->next('qc_inspection'),
            'job_card_id' => $job->id,
            'stage' => 'final',
            'lot_size' => $produce,
            'sample_size' => $aql['sample_size'],
            'accept_number' => $aql['accept_number'],
            'reject_number' => $aql['reject_number'],
            'major_found' => 0,
            'minor_found' => 0,
            'critical_found' => 0,
            'dhu' => $aql['dhu'],
            'result' => $aql['result'],
            'inspector_id' => $this->qcEmployeeId,
            'inspected_on' => now()->toDateString(),
            'created_by' => $this->userId,
        ]);

        $fgWarehouse = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
        $receipt = app(FgReceiptService::class)->post(
            $job,
            (float) $produce,
            $fgWarehouse,
            (string) Str::uuid(),
            'A',
            null,
            $this->userId,
        );

        $line = $order->lines()->firstOrFail();
        $packQty = min(2_000, $produce);

        $list = PackingList::query()->create([
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'delivery_address_id' => $order->delivery_address_id,
            'packed_on' => now()->toDateString(),
            'total_cartons' => 1,
            'total_qty' => $packQty,
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);

        $carton = Carton::query()->create([
            'packing_list_id' => $list->id,
            'carton_no' => '1',
        ]);

        CartonContent::query()->create([
            'carton_id' => $carton->id,
            'sales_order_line_id' => $line->id,
            'product_id' => $offer['product']->id,
            'lot_id' => $receipt->lot_id,
            'qty' => $packQty,
        ]);

        app(PackingListStateMachine::class)->transition($list, 'packed');

        $challan = DB::transaction(function () use ($list): DeliveryChallan {
            /** @var DeliveryChallan $challan */
            $challan = DeliveryChallan::query()->create([
                'packing_list_id' => $list->id,
                'sales_order_id' => $list->sales_order_id,
                'customer_id' => $list->customer_id,
                'delivery_address_id' => $list->delivery_address_id,
                'challan_date' => now()->toDateString(),
                'mode' => 'own_fleet',
                'status' => 'draft',
                'created_by' => $this->userId,
            ]);

            $packed = DB::table('carton_contents as cc')
                ->join('cartons as c', 'c.id', '=', 'cc.carton_id')
                ->where('c.packing_list_id', $list->id)
                ->groupBy('cc.sales_order_line_id', 'cc.lot_id', 'cc.product_id')
                ->get([
                    'cc.sales_order_line_id', 'cc.lot_id', 'cc.product_id',
                    DB::raw('SUM(cc.qty) as qty'), DB::raw('COUNT(DISTINCT cc.carton_id) as cartons'),
                ]);

            foreach ($packed as $index => $row) {
                DB::table('delivery_challan_lines')->insert([
                    'delivery_challan_id' => $challan->id,
                    'line_no' => $index + 1,
                    'sales_order_line_id' => $row->sales_order_line_id,
                    'product_id' => $row->product_id,
                    'lot_id' => $row->lot_id,
                    'qty' => $row->qty,
                    'cartons' => $row->cartons,
                ]);
            }

            $challan->forceFill([
                'total_cartons' => 1,
                'total_qty' => (float) $packed->sum('qty'),
            ])->save();

            return $challan;
        });

        app(DeliveryChallanStateMachine::class)->transition($challan, 'issued');

        $calculator = app(CostSheetCalculator::class);
        $invoice = DB::transaction(function () use ($challan, $order, $calculator): SalesInvoice {
            $netDays = (int) (DB::table('payment_terms')->where('id', $order->payment_term_id)->value('net_days') ?? 30);

            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->create([
                'customer_id' => $challan->customer_id,
                'sales_order_id' => $challan->sales_order_id,
                'delivery_challan_id' => $challan->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays($netDays)->toDateString(),
                'currency_id' => $order->currency_id,
                'exchange_rate' => $order->exchange_rate ?? 1,
                'status' => 'draft',
                'created_by' => $this->userId,
            ]);

            $billed = DB::table('delivery_challan_lines as dcl')
                ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'dcl.sales_order_line_id')
                ->where('dcl.delivery_challan_id', $challan->id)
                ->groupBy('dcl.sales_order_line_id', 'dcl.product_id', 'sol.rate_per_m', 'sol.description')
                ->get([
                    'dcl.sales_order_line_id', 'dcl.product_id', 'sol.rate_per_m', 'sol.description',
                    DB::raw('SUM(dcl.qty) as qty'),
                ]);

            $subtotal = 0.0;

            foreach ($billed as $index => $row) {
                $amount = $calculator->lineValue((int) $row->qty, (float) ($row->rate_per_m ?? 0));
                $subtotal += $amount;

                SalesInvoiceLine::query()->create([
                    'sales_invoice_id' => $invoice->id,
                    'line_no' => $index + 1,
                    'sales_order_line_id' => $row->sales_order_line_id,
                    'product_id' => $row->product_id,
                    'description' => $row->description,
                    'qty' => $row->qty,
                    'rate_per_m' => $row->rate_per_m ?? 0,
                    'tax_amount' => 0,
                    'amount' => round($amount, 4),
                ]);
            }

            $invoice->forceFill([
                'subtotal' => round($subtotal, 4),
                'tax_amount' => 0,
                'total' => round($subtotal, 4),
            ])->save();

            return $invoice;
        });

        app(SalesInvoiceStateMachine::class)->transition($invoice, 'issued');
    }

    private function trips(): void
    {
        if (Trip::query()->where('remarks', 'like', 'local-catalogue:%')->exists()) {
            return;
        }

        $vehicleId = (int) DB::table('vehicles')->where('is_active', true)->value('id');
        $driverId = DB::table('drivers')->where('is_active', true)->value('id');
        $challans = DeliveryChallan::query()
            ->where('status', 'issued')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('trip_stops')->whereColumn('delivery_challan_id', 'delivery_challans.id'))
            ->orderBy('id')
            ->limit(4)
            ->get();

        if ($vehicleId < 1 || $challans->isEmpty()) {
            return;
        }

        $numbers = app(NumberAllocator::class);
        $chunks = $challans->chunk(2);

        foreach ($chunks->values() as $index => $stops) {
            DB::transaction(function () use ($numbers, $vehicleId, $driverId, $stops, $index): void {
                $trip = new Trip;
                $trip->forceFill([
                    'number' => $numbers->next('trip'),
                    'vehicle_id' => $vehicleId,
                    'driver_id' => $driverId,
                    'trip_date' => now()->subDays(1 - $index)->toDateString(),
                    'route_zone' => $index === 0 ? 'Savar' : 'Gazipur',
                    'fuel_cost' => 0,
                    'status' => $index === 0 ? 'in_transit' : 'planned',
                    'started_at' => $index === 0 ? now()->subHours(3) : null,
                    'remarks' => 'local-catalogue:trip-'.($index + 1),
                ])->save();

                foreach ($stops->values() as $seq => $challan) {
                    TripStop::query()->create([
                        'trip_id' => $trip->id,
                        'sequence_no' => $seq + 1,
                        'delivery_challan_id' => $challan->id,
                        'customer_id' => $challan->customer_id,
                        'address_id' => $challan->delivery_address_id,
                        'status' => $index === 0 && $seq === 0 ? 'arrived' : 'pending',
                    ]);
                }
            });
        }
    }
}
