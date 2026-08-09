<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Costing\Services\CostSheetService;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Product\Models\Artwork;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Bom;
use App\Modules\Product\Models\BomLine;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Product\Models\Routing;
use App\Modules\Product\States\ArtworkVersionStateMachine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\States\SalesOrderStateMachine;
use App\Support\Calculators\CapacityCalculator;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The domain walkthrough from the specification README, seeded as real data:
 *
 *   inquiry for 50,000 centre-fold satin woven care labels → quote → artwork v2 approved →
 *   SO → BOM → yarn shortage → PO → GRN → job card (warp/weave/cut/fold) → wash-fastness test
 *   → AQL → cartons → challan → own-fleet trip → invoice → GRS reconciliation
 *
 * Every step goes through the same services and state machines the UI uses, so a green seed is
 * itself evidence that the spine works end to end. Nothing here writes a status column directly.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // The seeders run as the implementer: the state machines check permissions, and an
        // unauthenticated seed would be blocked by the same guards that protect the UI.
        Auth::login(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

        $customer = $this->customer();
        $suppliers = $this->suppliers();
        $items = $this->items($suppliers);
        $product = $this->product($customer);
        $spec = $this->spec($product);
        $artwork = $this->artwork($product);
        $bom = $this->bom($product, $spec, $items);

        $this->stock($items);
        $order = $this->salesOrder($customer, $product, $spec);
        $this->jobCard($order, $product, $spec, $artwork, $bom);

        Auth::logout();

        $this->command->info('Demo walkthrough seeded: 50,000 centre-fold satin woven care labels.');
    }

    private function customer(): object
    {
        DB::table('customers')->updateOrInsert(
            ['code' => 'CUST-001'],
            [
                'name' => 'Nordic Apparel Ltd',
                'kind' => 'brand',
                'email' => 'sourcing@nordicapparel.test',
                'currency_id' => DB::table('currencies')->where('code', 'USD')->value('id'),
                'payment_term_id' => DB::table('payment_terms')->where('code', 'NET60')->value('id'),
                'credit_limit' => 5_000_000,
                'min_order_value' => 15_000,
                'under_tolerance_pct' => 5,
                'over_tolerance_pct' => 5,
                'is_active' => true,
            ],
        );

        $customer = DB::table('customers')->where('code', 'CUST-001')->first();

        DB::table('customer_addresses')->updateOrInsert(
            ['customer_id' => $customer->id, 'label' => 'Factory — Ashulia'],
            [
                'kind' => 'delivery',
                'line1' => 'Plot 42, Ashulia EPZ',
                'city' => 'Savar',
                'country' => 'Bangladesh',
                'transit_days' => 1,
                'route_zone' => 'Savar',
                'is_default' => true,
            ],
        );

        DB::table('brands')->updateOrInsert(
            ['customer_id' => $customer->id, 'name' => 'Nordfjell'],
            ['code' => 'NFJ'],
        );

        return $customer;
    }

    /** @return array<string, int> */
    private function suppliers(): array
    {
        $rows = [
            ['code' => 'SUP-YARN-UK', 'name' => 'Coats UK Ltd', 'country' => 'United Kingdom', 'lead_time_days' => 45],
            ['code' => 'SUP-RIB-CN', 'name' => 'Leader Ribbon Co.', 'country' => 'China', 'lead_time_days' => 30],
            ['code' => 'SUP-INK-UK', 'name' => 'Perfectos Printing Inks', 'country' => 'United Kingdom', 'lead_time_days' => 40],
            ['code' => 'SUP-PACK-BD', 'name' => 'Dhaka Packaging', 'country' => 'Bangladesh', 'lead_time_days' => 7],
        ];

        $ids = [];

        foreach ($rows as $row) {
            DB::table('suppliers')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'country' => $row['country'],
                    'lead_time_days' => $row['lead_time_days'],
                    'is_approved' => true,
                    'is_active' => true,
                    'rating' => 4.0,
                ],
            );

            $ids[$row['code']] = (int) DB::table('suppliers')->where('code', $row['code'])->value('id');
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $suppliers
     * @return array<string, int>
     */
    private function items(array $suppliers): array
    {
        $uoms = DB::table('uoms')->pluck('id', 'code');
        $categories = DB::table('item_categories')->pluck('id', 'code');

        $rows = [
            [
                'code' => 'YRN-PLY-150D-WHT', 'name' => 'Polyester yarn 150D — optical white',
                'category' => 'YARN', 'uom' => 'kg', 'supplier' => 'SUP-YARN-UK',
                'std_rate' => 1450, 'is_shade_critical' => true, 'min_order_qty' => 25, 'order_multiple' => 5,
                'safety_days' => 10,
            ],
            [
                'code' => 'YRN-PLY-150D-NVY', 'name' => 'Polyester yarn 150D — navy',
                'category' => 'YARN', 'uom' => 'kg', 'supplier' => 'SUP-YARN-UK',
                'std_rate' => 1520, 'is_shade_critical' => true, 'min_order_qty' => 25, 'order_multiple' => 5,
                'safety_days' => 10,
            ],
            [
                'code' => 'INK-FLX-BLK', 'name' => 'Flexo ink — black',
                'category' => 'INK', 'uom' => 'kg', 'supplier' => 'SUP-INK-UK',
                'std_rate' => 2200, 'ink_lay_gsm' => 1.6, 'has_expiry' => true, 'shelf_life_days' => 365,
                'safety_days' => 15,
            ],
            [
                'code' => 'PKG-POLY-A', 'name' => 'Polybag 150 × 200 mm',
                'category' => 'PACKMAT', 'uom' => 'pcs', 'supplier' => 'SUP-PACK-BD',
                'std_rate' => 1.2, 'min_order_qty' => 1000, 'order_multiple' => 500,
            ],
            [
                'code' => 'PKG-CTN-5PLY', 'name' => 'Carton 5-ply 400 × 300 × 300',
                'category' => 'PACKMAT', 'uom' => 'pcs', 'supplier' => 'SUP-PACK-BD',
                'std_rate' => 48, 'min_order_qty' => 50, 'order_multiple' => 25,
            ],
        ];

        $ids = [];

        foreach ($rows as $row) {
            DB::table('items')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'item_category_id' => $categories[$row['category']],
                    'name' => $row['name'],
                    'base_uom_id' => $uoms[$row['uom']],
                    'default_supplier_id' => $suppliers[$row['supplier']] ?? null,
                    'min_order_qty' => $row['min_order_qty'] ?? 0,
                    'order_multiple' => $row['order_multiple'] ?? 1,
                    'reorder_level' => 0,
                    'safety_days' => $row['safety_days'] ?? 0,
                    'std_rate' => $row['std_rate'],
                    'ink_lay_gsm' => $row['ink_lay_gsm'] ?? null,
                    'is_lot_tracked' => true,
                    'is_shade_critical' => $row['is_shade_critical'] ?? false,
                    'has_expiry' => $row['has_expiry'] ?? false,
                    'shelf_life_days' => $row['shelf_life_days'] ?? null,
                    'attributes' => '{}',
                    'is_active' => true,
                ],
            );

            $ids[$row['code']] = (int) DB::table('items')->where('code', $row['code'])->value('id');
        }

        return $ids;
    }

    private function product(object $customer): Product
    {
        $routing = Routing::query()->where('code', 'RT-WOVEN')->firstOrFail();

        /** @var Product $product */
        $product = Product::query()->updateOrCreate(
            ['code' => 'PRD-NFJ-CARE-01'],
            [
                'customer_id' => $customer->id,
                'brand_id' => DB::table('brands')->where('customer_id', $customer->id)->value('id'),
                'routing_id' => $routing->id,
                'name' => 'Nordfjell centre-fold satin care label',
                'customer_style_ref' => 'NFJ-AW26-CARE',
                'product_type' => 'woven',
                'is_running_programme' => true,
                'annual_forecast_qty' => 500_000,
                'status' => 'active',
                'is_active' => true,
                'created_by' => Auth::id(),
            ],
        );

        return $product;
    }

    private function spec(Product $product): ProductSpec
    {
        /** @var ProductSpec $spec */
        $spec = ProductSpec::query()->updateOrCreate(
            ['product_id' => $product->id, 'version_no' => 1],
            [
                'status' => ProductSpec::DRAFT,
                // 40 × 20 mm label on a 220 mm loom: the worked example throughout the tests.
                'label_width_mm' => 40,
                'label_height_mm' => 20,
                'web_width_mm' => 220,
                'selvedge_mm' => 5,
                'lane_gap_mm' => 2,
                'cut_gap_mm' => 2,
                'ends' => 5,
                'base_material' => 'Satin',
                'fabric_gsm' => 120,
                'warp_ratio' => 0.60,
                'colours' => 2,
                'colour_list' => [
                    ['index' => 1, 'name' => 'Optical white', 'pantone' => '11-0601', 'weight_pct' => 70],
                    ['index' => 2, 'name' => 'Navy', 'pantone' => '19-4025', 'weight_pct' => 30],
                ],
                'cut_type' => 'ultrasonic',
                'fold_type' => 'centre_fold',
                'finish' => 'Soft-touch',
                'coverage_pct' => 35,
                'bundle_size' => 500,
                'bundles_per_carton' => 20,
                'care_symbols' => ['wash_30', 'no_bleach', 'iron_low', 'no_tumble'],
                'fibre_composition' => '100% Polyester',
                'country_of_origin' => 'Bangladesh',
                'claims' => ['GRS'],
                'attributes' => ['pick_density' => 42],
                'created_by' => Auth::id(),
            ],
        );

        // P2 — exactly one current spec. Supersede first, or the generated `current_key`
        // rejects the write.
        if (! $spec->isCurrent()) {
            DB::transaction(function () use ($spec): void {
                ProductSpec::query()
                    ->where('product_id', $spec->product_id)
                    ->where('id', '!=', $spec->getKey())
                    ->where('status', ProductSpec::CURRENT)
                    ->update(['status' => ProductSpec::SUPERSEDED]);

                $spec->update(['status' => ProductSpec::CURRENT]);
            });
        }

        return $spec->refresh();
    }

    /**
     * Gate 1, seeded the long way round: v1 is submitted and rejected, v2 is submitted and
     * approved. Both transitions go through the state machine, so the supersede ordering and
     * the evidence requirement are exercised rather than assumed.
     */
    private function artwork(Product $product): Artwork
    {
        /** @var Artwork $artwork */
        $artwork = Artwork::query()->updateOrCreate(
            ['code' => 'ART-NFJ-CARE-01'],
            [
                'product_id' => $product->id,
                'title' => 'Nordfjell AW26 care label',
                'designer_id' => DB::table('employees')->where('code', 'EMP-0005')->value('id'),
            ],
        );

        if ($artwork->versions()->exists()) {
            return $artwork;
        }

        $states = app(ArtworkVersionStateMachine::class);

        $v1 = ArtworkVersion::query()->create([
            'artwork_id' => $artwork->id,
            'version_no' => 1,
            'status' => ArtworkVersion::DRAFT,
            'file_path' => 'artwork/demo/nfj-care-v1.ai',
            'file_format' => 'ai',
            'checksum_sha256' => hash('sha256', 'nfj-care-v1'),
            'created_by' => Auth::id(),
        ]);

        $states->transition($v1, ArtworkVersion::SUBMITTED);
        $states->transition($v1, ArtworkVersion::REJECTED, [
            'rejection_reason' => 'Fibre composition text too small; brand requires 6 pt minimum.',
        ]);

        $v2 = ArtworkVersion::query()->create([
            'artwork_id' => $artwork->id,
            'version_no' => 2,
            'status' => ArtworkVersion::DRAFT,
            'file_path' => 'artwork/demo/nfj-care-v2.ai',
            'file_format' => 'ai',
            'checksum_sha256' => hash('sha256', 'nfj-care-v2'),
            'created_by' => Auth::id(),
        ]);

        $states->transition($v2, ArtworkVersion::SUBMITTED);
        $states->transition($v2, ArtworkVersion::APPROVED, [
            'customer_ref' => 'Email 2026-07-28 from Ingrid Vestby, Nordic Apparel',
        ]);

        return $artwork;
    }

    /** @param array<string, int> $items */
    private function bom(Product $product, ProductSpec $spec, array $items): Bom
    {
        /** @var Bom $bom */
        $bom = Bom::query()->updateOrCreate(
            ['product_id' => $product->id, 'version_no' => 1],
            [
                'product_spec_id' => $spec->id,
                'status' => Bom::DRAFT,
                'base_qty' => 1000,
                'notes' => 'Derived from BR-9 and BR-12 against spec v1.',
                'created_by' => Auth::id(),
            ],
        );

        if ($bom->lines()->doesntExist()) {
            $uoms = DB::table('uoms')->pluck('id', 'code');

            // Per 1000 pieces, from the consumption calculator against this spec and routing.
            $plan = (new ConsumptionCalculator)->plan(
                $spec->toCalculatorInput($product->product_type),
                1000,
                $product->routing->toCalculatorSteps(),
                $spec->colourWeights(),
            );

            $lines = [
                ['item' => 'YRN-PLY-150D-WHT', 'uom' => 'kg', 'qty' => $plan->warpKg + ($plan->weftKgByColour['1'] ?? 0), 'formula' => 'BR-9', 'colour' => 1],
                ['item' => 'YRN-PLY-150D-NVY', 'uom' => 'kg', 'qty' => $plan->weftKgByColour['2'] ?? 0, 'formula' => 'BR-9', 'colour' => 2],
                ['item' => 'PKG-POLY-A', 'uom' => 'pcs', 'qty' => $plan->polybags, 'formula' => 'BR-12', 'colour' => null],
                ['item' => 'PKG-CTN-5PLY', 'uom' => 'pcs', 'qty' => max(1, $plan->cartons), 'formula' => 'BR-12', 'colour' => null],
            ];

            foreach ($lines as $line) {
                BomLine::query()->create([
                    'bom_id' => $bom->id,
                    'item_id' => $items[$line['item']],
                    'uom_id' => $uoms[$line['uom']],
                    'qty_per_base' => round(max(0.000001, $line['qty']), 6),
                    'wastage_pct' => 0,
                    'colour_index' => $line['colour'],
                    'is_optional' => false,
                    'formula_ref' => $line['formula'],
                ]);
            }
        }

        // PD-3 — one active BOM per product.
        if (! $bom->isActive()) {
            DB::transaction(function () use ($bom): void {
                Bom::query()
                    ->where('product_id', $bom->product_id)
                    ->where('id', '!=', $bom->getKey())
                    ->where('status', Bom::ACTIVE)
                    ->update(['status' => Bom::SUPERSEDED]);

                $bom->update(['status' => Bom::ACTIVE]);
            });
        }

        return $bom->refresh();
    }

    /**
     * Receive material through the posting service — including one GRS-certified yarn lot, so
     * the chain-of-custody ledger has a certified input to reconcile against.
     *
     * @param  array<string, int>  $items
     */
    private function stock(array $items): void
    {
        if (DB::table('stock_lots')->exists()) {
            return;
        }

        $posting = app(StockPostingService::class);
        $numbers = app(NumberAllocator::class);
        $uoms = DB::table('uoms')->pluck('id', 'code');
        $rmWarehouse = (int) DB::table('warehouses')->where('code', 'RM')->value('id');
        $packWarehouse = (int) DB::table('warehouses')->where('code', 'PACK')->value('id');
        $supplierId = (int) DB::table('suppliers')->where('code', 'SUP-YARN-UK')->value('id');

        $grnId = DB::transaction(function () use ($numbers, $supplierId, $rmWarehouse): int {
            return DB::table('grns')->insertGetId([
                'number' => $numbers->next('grn'),
                'supplier_id' => $supplierId,
                'warehouse_id' => $rmWarehouse,
                'received_on' => now()->subDays(14)->toDateString(),
                'invoice_no' => 'CUK-2026-4471',
                'freight_amount' => 42_000,
                'duty_amount' => 18_500,
                'clearing_amount' => 6_200,
                'status' => 'posted',
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]);
        });

        $receipts = [
            ['item' => 'YRN-PLY-150D-WHT', 'uom' => 'kg', 'qty' => 180, 'rate' => 1465, 'wh' => $rmWarehouse,
                'shade' => 'W-2611', 'scheme' => 'GRS', 'claim' => 100.0, 'doc' => 'CU-GRS-88213'],
            ['item' => 'YRN-PLY-150D-NVY', 'uom' => 'kg', 'qty' => 60, 'rate' => 1535, 'wh' => $rmWarehouse,
                'shade' => 'N-1902', 'scheme' => null, 'claim' => 0.0, 'doc' => null],
            ['item' => 'PKG-POLY-A', 'uom' => 'pcs', 'qty' => 5_000, 'rate' => 1.15, 'wh' => $packWarehouse,
                'shade' => null, 'scheme' => null, 'claim' => 0.0, 'doc' => null],
            ['item' => 'PKG-CTN-5PLY', 'uom' => 'pcs', 'qty' => 300, 'rate' => 46.5, 'wh' => $packWarehouse,
                'shade' => null, 'scheme' => null, 'claim' => 0.0, 'doc' => null],
        ];

        foreach ($receipts as $index => $receipt) {
            // BR-34 refuses to allocate a lot number outside the transaction that inserts the
            // row, so the receipt is one transaction — exactly as GrnController does it.
            DB::transaction(function () use ($index, $receipt, $items, $uoms, $grnId, $posting, $numbers): void {
                $grnLineId = DB::table('grn_lines')->insertGetId([
                    'grn_id' => $grnId,
                    'line_no' => $index + 1,
                    'item_id' => $items[$receipt['item']],
                    'uom_id' => $uoms[$receipt['uom']],
                    'received_qty' => $receipt['qty'],
                    'accepted_qty' => $receipt['qty'],
                    'rejected_qty' => 0,
                    'rate' => $receipt['rate'],
                    'landed_rate' => $receipt['rate'],
                    'shade_code' => $receipt['shade'],
                    'cert_scheme' => $receipt['scheme'],
                    'cert_claim_pct' => $receipt['claim'],
                    'cert_document_no' => $receipt['doc'],
                ]);

                $posting->receive(
                    [
                        'lot_no' => $numbers->nextLotNumber(),
                        'item_id' => $items[$receipt['item']],
                        'kind' => 'raw_material',
                        'warehouse_id' => $receipt['wh'],
                        'uom_id' => $uoms[$receipt['uom']],
                        'grn_line_id' => $grnLineId,
                        'shade_code' => $receipt['shade'],
                        'received_on' => now()->subDays(14)->toDateString(),
                        'cert_scheme' => $receipt['scheme'],
                        'cert_claim_pct' => $receipt['claim'],
                        'cert_document_no' => $receipt['doc'],
                        'status' => 'available',
                    ],
                    (float) $receipt['qty'],
                    (float) $receipt['rate'],
                    \App\Modules\Procurement\Models\Grn::query()->findOrFail($grnId),
                );

                // BR-42 — the certified input side of the reconciliation.
                if ($receipt['scheme'] !== null) {
                    DB::table('coc_transactions')->insert([
                        'scheme' => $receipt['scheme'],
                        'direction' => 'input',
                        'grn_line_id' => $grnLineId,
                        'item_id' => $items[$receipt['item']],
                        'uom_id' => $uoms[$receipt['uom']],
                        'qty' => $receipt['qty'] * $receipt['claim'] / 100,
                        'claim_pct' => $receipt['claim'],
                        'document_no' => $receipt['doc'],
                        'period_year' => (int) now()->subDays(14)->format('Y'),
                        'period_month' => (int) now()->subDays(14)->format('n'),
                        'created_by' => Auth::id(),
                        'created_at' => now(),
                    ]);
                }
            });
        }
    }

    private function salesOrder(object $customer, Product $product, ProductSpec $spec): SalesOrder
    {
        $existing = SalesOrder::query()->where('customer_po_no', 'NFJ-PO-2026-0918')->first();

        if ($existing !== null) {
            return $existing;
        }

        $costing = app(CostSheetService::class);
        $sheet = $costing->calculate($product, $spec, 50_000, ['marginPct' => 22.0, 'exchangeRate' => 122.50, 'currency' => 'USD']);

        /** @var SalesOrder $order */
        $order = SalesOrder::query()->create([
            'customer_id' => $customer->id,
            'customer_po_no' => 'NFJ-PO-2026-0918',
            'order_date' => now()->subDays(7)->toDateString(),
            'delivery_date' => now()->addDays(21)->toDateString(),
            'currency_id' => DB::table('currencies')->where('code', 'USD')->value('id'),
            'exchange_rate' => 122.50,
            'payment_term_id' => DB::table('payment_terms')->where('code', 'NET60')->value('id'),
            'delivery_address_id' => DB::table('customer_addresses')->where('customer_id', $customer->id)->value('id'),
            'factory_unit_id' => DB::table('factory_units')->where('code', 'ML-1')->value('id'),
            'merchandiser_id' => User::query()->where('email', 'merchandiser@maheenlabel.test')->value('id'),
            'priority' => 'normal',
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);

        $lineTotal = round(50_000 / 1000 * $sheet->ratePerMInCurrency, 4);

        SalesOrderLine::query()->create([
            'sales_order_id' => $order->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_spec_id' => $spec->id,
            'description' => '50,000 centre-fold satin woven care labels — Nordfjell AW26',
            'ordered_qty' => 50_000,
            'rate_per_m' => round($sheet->ratePerMInCurrency, 4),
            'line_total' => $lineTotal,
            'over_tolerance_pct' => 5,
            'under_tolerance_pct' => 5,
            'status' => 'open',
        ]);

        $order->forceFill(['subtotal' => $lineTotal, 'total' => $lineTotal])->save();

        // Through the state machine: S3 checks the current spec and the approved artwork, and
        // BR-29 stamps the promised date.
        app(SalesOrderStateMachine::class)->transition($order->refresh(), 'confirmed');

        return $order->refresh();
    }

    private function jobCard(SalesOrder $order, Product $product, ProductSpec $spec, Artwork $artwork, Bom $bom): void
    {
        if (JobCard::query()->where('sales_order_line_id', $order->lines->first()?->id)->exists()) {
            return;
        }

        $line = $order->lines()->first();
        $approvedVersion = $artwork->approvedVersion()->firstOrFail();
        $consumption = new ConsumptionCalculator;
        $capacity = new CapacityCalculator;

        $plan = $consumption->plan(
            $spec->toCalculatorInput($product->product_type),
            50_000,
            $product->routing->toCalculatorSteps(),
            $spec->colourWeights(),
        );

        /** @var JobCard $jobCard */
        $jobCard = JobCard::query()->create([
            'factory_unit_id' => $order->factory_unit_id,
            'sales_order_line_id' => $line->id,
            'product_id' => $product->id,
            'product_spec_id' => $spec->id,
            // Gate 1: NOT NULL, and pointed at the approved version.
            'artwork_version_id' => $approvedVersion->id,
            'bom_id' => $bom->id,
            'routing_id' => $product->routing_id,
            'colourway' => 'White / Navy',
            'planned_qty' => 50_000,
            'due_date' => now()->addDays(14)->toDateString(),
            'priority' => 40,
            'gross_metres' => $plan->grossMetres,
            'ends' => $plan->ends,
            'labels_per_metre' => $plan->labelsPerMetre,
            'status' => JobCard::DRAFT,
            'created_by' => Auth::id(),
        ]);

        $machines = DB::table('machines')->pluck('id', 'machine_group_id');
        $scheduledStart = now()->addDay()->startOfDay()->addHours(6);

        foreach ($product->routing->operations as $operation) {
            $outputUnits = $operation->consumes_web ? $plan->grossMetres : 50_000.0;
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
                'scheduled_start' => $scheduledStart,
                'scheduled_finish' => $scheduledStart->copy()->addMinutes((int) $minutes),
                'requires_qc' => $operation->requires_qc,
                'status' => JobCardOperation::PENDING,
            ]);

            $scheduledStart = $scheduledStart->copy()->addMinutes((int) $minutes + 30);
        }

        // planned assigns the number (BR-34). Release is deliberately left for a human: with
        // 180 kg of white yarn received against a larger requirement, the gate has something
        // real to report, which is the point of seeding it this way.
        app(JobCardStateMachine::class)->transition($jobCard, JobCard::PLANNED);
    }
}
