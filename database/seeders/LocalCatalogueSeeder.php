<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\PriceListLine;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Models\SupplierBillLine;
use App\Modules\Procurement\Models\SupplierQuotation;
use App\Modules\Procurement\Models\SupplierQuotationLine;
use App\Modules\Procurement\Models\SupplierRfq;
use App\Modules\Product\Models\Artwork;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Bom;
use App\Modules\Product\Models\BomLine;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Product\Models\Routing;
use App\Modules\Product\Models\Tool;
use App\Modules\Product\States\ArtworkVersionStateMachine;
use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Ncr;
use App\Modules\Quality\Models\TestReport;
use App\Modules\Quality\Models\TestReportLine;
use App\Modules\Quality\States\TestReportStateMachine;
use App\Modules\Trade\Models\ImportCost;
use App\Modules\Trade\Models\ImportShipment;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Local-only catalogue around the walkthrough order: every product type, machines, import,
 * buying, fleet, lab, NCRs, expenses. Idempotent per area so a second run fills what the first
 * 100-inquiry seeder skipped.
 */
class LocalCatalogueSeeder extends Seeder
{
    private int $userId;

    private int $usdId;

    private int $bdtId;

    private int $termId;

    private int $unitId;

    /** @var array<string, int> */
    private array $items = [];

    /** @var array<string, int> */
    private array $uoms = [];

    public function run(): void
    {
        if (Auth::guest()) {
            Auth::login(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
        }

        $this->userId = (int) Auth::id();
        $this->usdId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
        $this->bdtId = (int) DB::table('currencies')->where('code', 'BDT')->value('id');
        $this->termId = (int) DB::table('payment_terms')->where('code', 'NET60')->value('id');
        $this->unitId = (int) DB::table('factory_units')->where('code', 'ML-1')->value('id');
        $this->uoms = DB::table('uoms')->pluck('id', 'code')->all();
        $this->items = DB::table('items')->pluck('id', 'code')->all();

        $this->fleetAndBanks();
        $this->machines();
        $this->products();
        $this->toolsAndPriceLists();
        $this->buying();
        $this->import();
        $this->qualityAndExpenses();

        $this->command?->info('Local catalogue seeded (products, machines, import, buying, lab, fleet).');
    }

    private function fleetAndBanks(): void
    {
        if (! DB::table('vehicles')->where('registration_no', 'DHAKA-METRO-11-0001')->exists()) {
            DB::table('vehicles')->insert([
                ['registration_no' => 'DHAKA-METRO-11-0001', 'kind' => 'covered_van', 'capacity_kg' => 1800, 'is_owned' => true, 'is_active' => true],
                ['registration_no' => 'DHAKA-METRO-11-0002', 'kind' => 'pickup', 'capacity_kg' => 900, 'is_owned' => true, 'is_active' => true],
            ]);
        }

        $driverEmployee = (int) DB::table('employees')->where('code', 'EMP-0019')->value('id');

        if ($driverEmployee > 0 && ! DB::table('drivers')->where('employee_id', $driverEmployee)->exists()) {
            DB::table('drivers')->insert([
                'employee_id' => $driverEmployee,
                'name' => 'Sohel Mia',
                'licence_no' => 'DL-DHK-88421',
                'licence_expiry' => now()->addYear()->toDateString(),
                'phone' => '01711000019',
                'is_active' => true,
            ]);
        }

        if (! DB::table('bank_accounts')->where('code', 'BA-USD-LC')->exists()) {
            DB::table('bank_accounts')->insert([
                [
                    'code' => 'BA-USD-LC',
                    'name' => 'HSBC LC margin — USD',
                    'bank_name' => 'HSBC Bangladesh',
                    'branch' => 'Gulshan',
                    'account_no' => '001-482219-001',
                    'swift_code' => 'HSBCBDDH',
                    'currency_id' => $this->usdId,
                    'kind' => 'lc',
                    'is_active' => true,
                ],
                [
                    'code' => 'BA-BDT-CUR',
                    'name' => 'Dutch-Bangla current — BDT',
                    'bank_name' => 'Dutch-Bangla Bank',
                    'branch' => 'Motijheel',
                    'account_no' => '105-120-99821',
                    'swift_code' => 'DBBLBDDH',
                    'currency_id' => $this->bdtId ?: $this->usdId,
                    'kind' => 'current',
                    'is_active' => true,
                ],
            ]);
        }
    }

    /**
     * Reference data only seeds machine *groups*. The shop itself was never filled, so
     * /machines stayed empty and job-card operations had nothing to assign.
     */
    private function machines(): void
    {
        $groups = DB::table('machine_groups')->pluck('id', 'code');
        $depts = DB::table('departments')->where('factory_unit_id', $this->unitId)->pluck('id', 'code');

        $rows = [
            ['DESIGN', 'MCH-CAD-01', 'CAD station 1', 'NedGraphics', 'LabelStudio', 'STUDIO', 220, 8, 20, 90, 0.4, 92, 'available'],
            ['WARP', 'MCH-WARP-01', 'Warper 1', 'Jakob Müller', 'NH2 53', 'WEAV', 220, null, 400, 140, 3.5, 88, 'available'],
            ['WARP', 'MCH-WARP-02', 'Warper 2', 'Jakob Müller', 'NH2 53', 'WEAV', 220, null, 380, 140, 3.5, 84, 'maintenance'],
            ['LOOM', 'MCH-LOOM-01', 'Needle loom 1', 'Jakob Müller', 'NF53 6/42', 'WEAV', 220, 8, 120, 180, 2.2, 86, 'running'],
            ['LOOM', 'MCH-LOOM-02', 'Needle loom 2', 'Jakob Müller', 'NF53 6/42', 'WEAV', 220, 8, 120, 180, 2.2, 85, 'available'],
            ['LOOM', 'MCH-LOOM-03', 'Needle loom 3', 'Jakob Müller', 'NF53 8/27', 'WEAV', 180, 6, 140, 175, 2.0, 87, 'available'],
            ['LOOM', 'MCH-LOOM-04', 'Needle loom 4', 'Jakob Müller', 'NF53 8/27', 'WEAV', 180, 6, 140, 175, 2.0, 82, 'available'],
            ['LOOM', 'MCH-LOOM-05', 'Needle loom 5', 'Mageba', 'MCL 8/42', 'WEAV', 220, 8, 110, 165, 2.1, 80, 'breakdown'],
            ['LOOM', 'MCH-LOOM-06', 'Needle loom 6', 'Jakob Müller', 'NFJM2 6/42', 'WEAV', 220, 8, 125, 190, 2.4, 90, 'available'],
            ['FLEXO', 'MCH-FLX-01', 'Flexo press 1', 'Nilpeter', 'FA-4', 'PRINT', 250, 6, 900, 420, 8.5, 88, 'running'],
            ['FLEXO', 'MCH-FLX-02', 'Flexo press 2', 'Mark Andy', 'P5', 'PRINT', 330, 8, 850, 390, 7.8, 85, 'available'],
            ['SCREEN', 'MCH-SCR-01', 'Screen table 1', 'S-Tex', 'ST-1200', 'PRINT', 200, 4, 250, 160, 1.2, 80, 'available'],
            ['SCREEN', 'MCH-SCR-02', 'Screen table 2', 'S-Tex', 'ST-1200', 'PRINT', 200, 4, 250, 160, 1.2, 78, 'available'],
            ['HTRANS', 'MCH-HT-01', 'Heat-transfer press 1', 'Sakai', 'HT-400', 'PRINT', 180, 4, 400, 210, 4.5, 84, 'available'],
            ['OFFSET', 'MCH-OFF-01', 'Offset press 1', 'Heidelberg', 'GTO 52', 'PRINT', null, 5, 4000, 480, 11.0, 82, 'available'],
            ['THERMAL', 'MCH-THM-01', 'Thermal printer 1', 'Zebra', 'ZT411', 'PRINT', 110, 1, 6000, 55, 0.3, 95, 'available'],
            ['THERMAL', 'MCH-THM-02', 'Thermal printer 2', 'TSC', 'MH241', 'PRINT', 110, 1, 5800, 50, 0.3, 93, 'available'],
            ['SLIT', 'MCH-SLIT-01', 'Slitter 1', 'Kampf', 'Unislit', 'FINISH', 330, null, 1200, 95, 2.8, 90, 'available'],
            ['CUT', 'MCH-CUT-01', 'Ultrasonic cutter 1', 'Sonobond', 'LaceMaster', 'FINISH', 220, null, 300, 120, 1.8, 88, 'available'],
            ['CUT', 'MCH-CUT-02', 'Die cutter 1', 'Bobst', 'SPeria 106', 'FINISH', 320, null, 500, 260, 6.5, 85, 'available'],
            ['FOLD', 'MCH-FOLD-01', 'Folder 1', 'Mageba', 'FoldStar', 'FINISH', 220, null, 500, 70, 0.8, 90, 'available'],
            ['PACK', 'MCH-PACK-01', 'Packing table 1', null, null, 'FINISH', null, null, 5000, 40, 0.2, 95, 'available'],
            ['PACK', 'MCH-PACK-02', 'Packing table 2', null, null, 'FINISH', null, null, 5000, 40, 0.2, 95, 'available'],
        ];

        foreach ($rows as $index => [$group, $code, $name, $make, $model, $dept, $web, $colours, $rate, $hourly, $kw, $eff, $status]) {
            if (! isset($groups[$group])) {
                continue;
            }

            DB::table('machines')->updateOrInsert(
                ['code' => $code],
                [
                    'factory_unit_id' => $this->unitId,
                    'machine_group_id' => $groups[$group],
                    'department_id' => $depts[$dept] ?? null,
                    'name' => $name,
                    'make' => $make,
                    'model' => $model,
                    'serial_no' => 'SN-26-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'commissioned_on' => now()->subYears(4)->addMonths($index)->toDateString(),
                    'web_width_mm' => $web,
                    'max_colours' => $colours,
                    'std_rate_per_hour' => $rate,
                    'hourly_rate' => $hourly,
                    'kw_rating' => $kw,
                    'efficiency_pct' => $eff,
                    'status' => $status,
                    'is_active' => $status !== 'retired',
                ],
            );
        }
    }

    private function products(): void
    {
        $nordicId = (int) DB::table('customers')->where('code', 'CUST-001')->value('id');

        if ($nordicId > 0) {
            $this->seedProduct($nordicId, 'NFJ', 'PRD-NFJ-FLEX-01', 'Nordfjell flexo size sticker', 'flexo', 'RT-FLEXO', 'SIZE');
            $this->seedProduct($nordicId, 'NFJ', 'PRD-NFJ-TAG-01', 'Nordfjell offset hang tag', 'offset_tag', 'RT-OFFSET', 'TAG');
        }

        $defs = $this->typeCycle();

        foreach (range(1, 10) as $index) {
            $code = 'CUST-L-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $customer = DB::table('customers')->where('code', $code)->first();

            if ($customer === null) {
                continue;
            }

            $def = $defs[($index - 1) % count($defs)];
            $brandCode = 'L'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);

            DB::table('brands')->updateOrInsert(
                ['customer_id' => $customer->id, 'code' => $brandCode],
                ['name' => $customer->name],
            );

            $this->seedProduct(
                (int) $customer->id,
                $brandCode,
                'PRD-L-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                $customer->name.' '.$def['title'],
                $def['type'],
                $def['routing'],
                $def['suffix'],
            );
        }
    }

    /**
     * @return list<array{type: string, routing: string, suffix: string, title: string, material: string, w: int, h: int, web: int, ends: int}>
     */
    private function typeCycle(): array
    {
        return [
            ['type' => 'woven', 'routing' => 'RT-WOVEN', 'suffix' => 'CARE', 'title' => 'satin care label', 'material' => 'Satin', 'w' => 40, 'h' => 20, 'web' => 220, 'ends' => 5],
            ['type' => 'flexo', 'routing' => 'RT-FLEXO', 'suffix' => 'SIZE', 'title' => 'flexo size sticker', 'material' => 'Coated paper', 'w' => 25, 'h' => 15, 'web' => 160, 'ends' => 6],
            ['type' => 'screen', 'routing' => 'RT-SCREEN', 'suffix' => 'SCRN', 'title' => 'screen-print care', 'material' => 'Cotton tape', 'w' => 50, 'h' => 20, 'web' => 200, 'ends' => 4],
            ['type' => 'heat_transfer', 'routing' => 'RT-HTRANS', 'suffix' => 'HT', 'title' => 'heat-transfer logo', 'material' => 'PET film', 'w' => 40, 'h' => 40, 'web' => 180, 'ends' => 4],
            ['type' => 'offset_tag', 'routing' => 'RT-OFFSET', 'suffix' => 'TAG', 'title' => 'offset hang tag', 'material' => 'Art card 300gsm', 'w' => 80, 'h' => 50, 'web' => 320, 'ends' => 4],
            ['type' => 'thermal', 'routing' => 'RT-THERMAL', 'suffix' => 'THRM', 'title' => 'thermal barcode label', 'material' => 'Thermal paper', 'w' => 50, 'h' => 30, 'web' => 110, 'ends' => 2],
        ];
    }

    private function typeDef(string $type): array
    {
        foreach ($this->typeCycle() as $def) {
            if ($def['type'] === $type) {
                return $def;
            }
        }

        return $this->typeCycle()[0];
    }

    private function seedProduct(
        int $customerId,
        string $brandCode,
        string $code,
        string $name,
        string $type,
        string $routingCode,
        string $style,
    ): void {
        $routing = Routing::query()->where('code', $routingCode)->firstOrFail();
        $brandId = DB::table('brands')->where('customer_id', $customerId)->where('code', $brandCode)->value('id');
        $def = $this->typeDef($type);

        /** @var Product $product */
        $product = Product::query()->updateOrCreate(
            ['code' => $code],
            [
                'customer_id' => $customerId,
                'brand_id' => $brandId,
                'routing_id' => $routing->id,
                'name' => $name,
                'customer_style_ref' => $brandCode.'-26-'.$style,
                'product_type' => $type,
                'is_running_programme' => true,
                'annual_forecast_qty' => 200_000,
                'status' => 'active',
                'is_active' => true,
                'created_by' => $this->userId,
            ],
        );

        $product->load('routing.operations');
        $spec = $this->spec($product, $def);
        $this->artwork($product, $code, $name);
        $this->bom($product, $spec);
    }

    /**
     * @param  array{material: string, w: int, h: int, web: int, ends: int}  $def
     */
    private function spec(Product $product, array $def): ProductSpec
    {
        /** @var ProductSpec $spec */
        $spec = ProductSpec::query()->updateOrCreate(
            ['product_id' => $product->id, 'version_no' => 1],
            [
                'status' => ProductSpec::DRAFT,
                'label_width_mm' => $def['w'],
                'label_height_mm' => $def['h'],
                'web_width_mm' => $def['web'],
                'selvedge_mm' => 5,
                'lane_gap_mm' => 2,
                'cut_gap_mm' => 2,
                'ends' => $def['ends'],
                'base_material' => $def['material'],
                'fabric_gsm' => $product->product_type === 'woven' ? 120 : 80,
                'warp_ratio' => 0.60,
                'colours' => 2,
                'colour_list' => [
                    ['index' => 1, 'name' => 'Optical white', 'pantone' => '11-0601', 'weight_pct' => 70],
                    ['index' => 2, 'name' => 'Navy', 'pantone' => '19-4025', 'weight_pct' => 30],
                ],
                'cut_type' => $product->product_type === 'woven' ? 'ultrasonic' : 'die_cut',
                'fold_type' => $product->product_type === 'woven' ? 'centre_fold' : 'flat',
                'finish' => 'Standard',
                'coverage_pct' => $product->product_type === 'woven' ? 35 : 55,
                'bundle_size' => 500,
                'bundles_per_carton' => 20,
                'care_symbols' => ['wash_30', 'no_bleach', 'iron_low'],
                'fibre_composition' => $product->product_type === 'woven' ? '100% Polyester' : null,
                'country_of_origin' => 'Bangladesh',
                'claims' => $product->product_type === 'woven' ? ['GRS'] : [],
                'attributes' => $product->product_type === 'offset_tag'
                    ? ['sheet_length_mm' => 700, 'sheet_width_mm' => 500]
                    : [],
                'created_by' => $this->userId,
            ],
        );

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

    private function artwork(Product $product, string $productCode, string $title): void
    {
        $code = str_replace('PRD-', 'ART-', $productCode);

        /** @var Artwork $artwork */
        $artwork = Artwork::query()->updateOrCreate(
            ['code' => $code],
            [
                'product_id' => $product->id,
                'title' => $title,
                'designer_id' => DB::table('employees')->where('code', 'EMP-0005')->value('id'),
            ],
        );

        if ($artwork->versions()->exists()) {
            return;
        }

        $states = app(ArtworkVersionStateMachine::class);
        $v1 = ArtworkVersion::query()->create([
            'artwork_id' => $artwork->id,
            'version_no' => 1,
            'status' => ArtworkVersion::DRAFT,
            'file_path' => 'artwork/local/'.strtolower($code).'-v1.ai',
            'file_format' => 'ai',
            'checksum_sha256' => hash('sha256', $code.'-v1'),
            'created_by' => $this->userId,
        ]);

        $states->transition($v1, ArtworkVersion::SUBMITTED);
        $states->transition($v1, ArtworkVersion::APPROVED, [
            'customer_ref' => 'Local seed approval for '.$code,
        ]);
    }

    private function bom(Product $product, ProductSpec $spec): void
    {
        /** @var Bom $bom */
        $bom = Bom::query()->updateOrCreate(
            ['product_id' => $product->id, 'version_no' => 1],
            [
                'product_spec_id' => $spec->id,
                'status' => Bom::DRAFT,
                'base_qty' => 1000,
                'notes' => 'local-catalogue',
                'created_by' => $this->userId,
            ],
        );

        if ($bom->lines()->doesntExist()) {
            $plan = (new ConsumptionCalculator)->plan(
                $spec->toCalculatorInput($product->product_type),
                1000,
                $product->routing->toCalculatorSteps(),
                $spec->colourWeights(),
            );

            $lines = $product->product_type === 'woven'
                ? [
                    ['item' => 'YRN-PLY-150D-WHT', 'uom' => 'kg', 'qty' => $plan->warpKg + ($plan->weftKgByColour['1'] ?? 0)],
                    ['item' => 'YRN-PLY-150D-NVY', 'uom' => 'kg', 'qty' => $plan->weftKgByColour['2'] ?? 0],
                    ['item' => 'PKG-POLY-A', 'uom' => 'pcs', 'qty' => $plan->polybags],
                    ['item' => 'PKG-CTN-5PLY', 'uom' => 'pcs', 'qty' => max(1, $plan->cartons)],
                ]
                : [
                    ['item' => 'INK-FLX-BLK', 'uom' => 'kg', 'qty' => max(0.001, $plan->inkKg)],
                    ['item' => 'PKG-POLY-A', 'uom' => 'pcs', 'qty' => $plan->polybags],
                    ['item' => 'PKG-CTN-5PLY', 'uom' => 'pcs', 'qty' => max(1, $plan->cartons)],
                ];

            foreach ($lines as $line) {
                if (! isset($this->items[$line['item']])) {
                    continue;
                }

                BomLine::query()->create([
                    'bom_id' => $bom->id,
                    'item_id' => $this->items[$line['item']],
                    'uom_id' => $this->uoms[$line['uom']],
                    'qty_per_base' => round(max(0.000001, $line['qty']), 6),
                    'wastage_pct' => 0,
                    'is_optional' => false,
                    'formula_ref' => 'local-catalogue',
                ]);
            }
        }

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
    }

    private function toolsAndPriceLists(): void
    {
        $flexo = Product::query()->where('code', 'PRD-NFJ-FLEX-01')->first();
        $screen = Product::query()->where('product_type', 'screen')->first();

        if ($flexo !== null && ! Tool::query()->where('code', 'TOOL-L-FLX-01')->exists()) {
            Tool::query()->create([
                'product_spec_id' => $flexo->currentSpec()->value('id'),
                'kind' => 'flexo_plate',
                'code' => 'TOOL-L-FLX-01',
                'colour_index' => 1,
                'location' => 'Plate room',
                'made_on' => now()->subMonths(2)->toDateString(),
                'cost' => 18500,
                'life_impressions' => 200_000,
                'used_impressions' => 12_000,
                'status' => 'available',
            ]);
        }

        if ($screen !== null && ! Tool::query()->where('code', 'TOOL-L-SCR-01')->exists()) {
            Tool::query()->create([
                'product_spec_id' => $screen->currentSpec()->value('id'),
                'kind' => 'screen',
                'code' => 'TOOL-L-SCR-01',
                'location' => 'Screen store',
                'made_on' => now()->subMonth()->toDateString(),
                'cost' => 4200,
                'life_impressions' => 50_000,
                'used_impressions' => 800,
                'status' => 'available',
            ]);
        }

        if (! Tool::query()->where('code', 'TOOL-L-DIE-01')->exists()) {
            Tool::query()->create([
                'kind' => 'cutting_die',
                'code' => 'TOOL-L-DIE-01',
                'location' => 'Die rack A',
                'made_on' => now()->subMonths(4)->toDateString(),
                'cost' => 9600,
                'life_impressions' => 80_000,
                'used_impressions' => 21_000,
                'status' => 'in_use',
            ]);
        }

        $nordicId = (int) DB::table('customers')->where('code', 'CUST-001')->value('id');
        $haMeemId = (int) DB::table('customers')->where('code', 'CUST-L-01')->value('id');
        $care = Product::query()->where('code', 'PRD-NFJ-CARE-01')->first();

        if ($nordicId > 0 && ! PriceList::query()->where('code', 'PL-L-NFJ')->exists()) {
            $list = PriceList::query()->create([
                'customer_id' => $nordicId,
                'code' => 'PL-L-NFJ',
                'name' => 'Nordfjell 2026 running programme',
                'currency_id' => $this->usdId,
                'valid_from' => now()->startOfYear()->toDateString(),
                'is_active' => true,
            ]);

            PriceListLine::query()->create([
                'price_list_id' => $list->id,
                'product_id' => $care?->id,
                'description' => 'Satin care label — running rate',
                'min_qty' => 10000,
                'rate_per_m' => 11.80,
            ]);
        }

        if ($haMeemId > 0 && ! PriceList::query()->where('code', 'PL-L-HM')->exists()) {
            $list = PriceList::query()->create([
                'customer_id' => $haMeemId,
                'code' => 'PL-L-HM',
                'name' => 'Ha-Meem 2026 woven',
                'currency_id' => $this->usdId,
                'valid_from' => now()->startOfYear()->toDateString(),
                'is_active' => true,
            ]);

            $localWoven = Product::query()->where('code', 'PRD-L-01')->first();

            PriceListLine::query()->create([
                'price_list_id' => $list->id,
                'product_id' => $localWoven?->id,
                'description' => 'Woven care — Ha-Meem',
                'min_qty' => 5000,
                'rate_per_m' => 12.40,
            ]);
        }
    }

    private function buying(): void
    {
        if (PurchaseRequisition::query()->where('remarks', 'like', 'local-catalogue:%')->exists()) {
            return;
        }

        $deptId = DB::table('departments')->where('code', 'STORE')->value('id');
        $yarn = $this->items['YRN-PLY-150D-WHT'] ?? null;
        $carton = $this->items['PKG-CTN-5PLY'] ?? null;
        $kg = $this->uoms['kg'] ?? null;
        $pcs = $this->uoms['pcs'] ?? null;
        $coats = (int) DB::table('suppliers')->where('code', 'SUP-YARN-UK')->value('id');
        $inks = (int) DB::table('suppliers')->where('code', 'SUP-INK-UK')->value('id');
        $pack = (int) DB::table('suppliers')->where('code', 'SUP-PACK-BD')->value('id');
        $numbers = app(NumberAllocator::class);

        $draft = PurchaseRequisition::query()->create([
            'factory_unit_id' => $this->unitId,
            'department_id' => $deptId,
            'requested_on' => now()->subDays(12)->toDateString(),
            'required_by' => now()->addDays(20)->toDateString(),
            'origin' => 'manual',
            'status' => 'draft',
            'remarks' => 'local-catalogue:pr-draft',
            'created_by' => $this->userId,
        ]);
        $draft->lines()->create([
            'line_no' => 1,
            'item_id' => $carton,
            'uom_id' => $pcs,
            'qty' => 200,
        ]);

        $approved = DB::transaction(function () use ($numbers, $deptId, $yarn, $kg): PurchaseRequisition {
            $pr = PurchaseRequisition::query()->create([
                'factory_unit_id' => $this->unitId,
                'department_id' => $deptId,
                'requested_on' => now()->subDays(18)->toDateString(),
                'required_by' => now()->addDays(10)->toDateString(),
                'origin' => 'reorder_level',
                'status' => 'approved',
                'approved_by' => $this->userId,
                'approved_at' => now()->subDays(16),
                'remarks' => 'local-catalogue:pr-approved',
                'created_by' => $this->userId,
            ]);
            $pr->forceFill(['number' => $numbers->next('purchase_requisition')])->save();
            $pr->lines()->create([
                'line_no' => 1,
                'item_id' => $yarn,
                'uom_id' => $kg,
                'qty' => 250,
            ]);

            return $pr;
        });

        $rfqDraft = SupplierRfq::query()->create([
            'pr_id' => $approved->id,
            'issued_on' => now()->subDays(15)->toDateString(),
            'respond_by' => now()->addDays(5)->toDateString(),
        ]);
        $rfqDraft->forceFill(['status' => SupplierRfq::DRAFT, 'created_by' => $this->userId])->save();
        $rfqDraft->lines()->create([
            'line_no' => 1,
            'item_id' => $yarn,
            'qty' => 250,
            'uom_id' => $kg,
        ]);

        $rfqIssued = DB::transaction(function () use ($numbers, $approved, $yarn, $kg, $coats, $inks): SupplierRfq {
            $rfq = SupplierRfq::query()->create([
                'pr_id' => $approved->id,
                'issued_on' => now()->subDays(14)->toDateString(),
                'respond_by' => now()->addDays(3)->toDateString(),
            ]);
            $rfq->forceFill([
                'number' => $numbers->next('rfq'),
                'status' => SupplierRfq::ISSUED,
                'created_by' => $this->userId,
            ])->save();
            $rfq->lines()->create([
                'line_no' => 1,
                'item_id' => $yarn,
                'qty' => 250,
                'uom_id' => $kg,
            ]);

            foreach ([[$coats, 1460.0], [$inks, 1510.0]] as $index => [$supplierId, $rate]) {
                if ($supplierId < 1) {
                    continue;
                }

                $quote = SupplierQuotation::query()->create([
                    'rfq_id' => $rfq->id,
                    'supplier_id' => $supplierId,
                    'quoted_on' => now()->subDays(12 - $index)->toDateString(),
                    'valid_until' => now()->addDays(20)->toDateString(),
                    'currency_id' => $this->usdId,
                    'lead_time_days' => 40 + ($index * 5),
                ]);
                $amount = round(250 * $rate, 4);
                $quote->forceFill([
                    'total' => $amount,
                    'is_selected' => $index === 0,
                ])->save();

                $line = new SupplierQuotationLine;
                $line->forceFill([
                    'supplier_quotation_id' => $quote->id,
                    'line_no' => 1,
                    'item_id' => $yarn,
                    'qty' => 250,
                    'uom_id' => $kg,
                    'rate' => $rate,
                    'amount' => $amount,
                ])->save();
            }

            return $rfq;
        });

        DB::transaction(function () use ($numbers, $coats, $yarn, $kg): PurchaseOrder {
            $po = PurchaseOrder::query()->create([
                'supplier_id' => $coats,
                'factory_unit_id' => $this->unitId,
                'order_date' => now()->subDays(10)->toDateString(),
                'expected_date' => now()->addDays(35)->toDateString(),
                'currency_id' => $this->usdId,
                'exchange_rate' => 122.50,
                'payment_term_id' => $this->termId,
                'incoterm' => 'CIF',
                'subtotal' => 365000,
                'tax_amount' => 0,
                'freight_amount' => 0,
                'total' => 365000,
                'status' => 'sent',
                'approved_by' => $this->userId,
                'approved_at' => now()->subDays(9),
                'remarks' => 'local-catalogue:po-yarn',
                'created_by' => $this->userId,
            ]);
            $po->forceFill(['number' => $numbers->next('purchase_order')])->save();
            PurchaseOrderLine::query()->create([
                'po_id' => $po->id,
                'line_no' => 1,
                'item_id' => $yarn,
                'qty' => 250,
                'uom_id' => $kg,
                'rate' => 1460,
                'amount' => 365000,
                'expected_date' => now()->addDays(35)->toDateString(),
            ]);

            return $po;
        });

        DB::transaction(function () use ($numbers, $pack, $carton, $pcs): void {
            $po = PurchaseOrder::query()->create([
                'supplier_id' => $pack,
                'factory_unit_id' => $this->unitId,
                'order_date' => now()->subDays(3)->toDateString(),
                'expected_date' => now()->addDays(7)->toDateString(),
                'currency_id' => $this->bdtId ?: $this->usdId,
                'exchange_rate' => 1,
                'subtotal' => 9600,
                'total' => 9600,
                'status' => 'draft',
                'remarks' => 'local-catalogue:po-pack',
                'created_by' => $this->userId,
            ]);
            PurchaseOrderLine::query()->create([
                'po_id' => $po->id,
                'line_no' => 1,
                'item_id' => $carton,
                'qty' => 200,
                'uom_id' => $pcs,
                'rate' => 48,
                'amount' => 9600,
            ]);
        });

        unset($rfqIssued, $draft);
    }

    private function import(): void
    {
        if (LetterOfCredit::query()->where('remarks', 'like', 'local-catalogue:%')->exists()) {
            return;
        }

        $coats = (int) DB::table('suppliers')->where('code', 'SUP-YARN-UK')->value('id');
        $inks = (int) DB::table('suppliers')->where('code', 'SUP-INK-UK')->value('id');
        $bankId = DB::table('bank_accounts')->where('code', 'BA-USD-LC')->value('id');
        $numbers = app(NumberAllocator::class);
        $yarnPo = PurchaseOrder::query()->where('remarks', 'local-catalogue:po-yarn')->first();

        $draft = DB::transaction(function () use ($numbers, $coats, $bankId): LetterOfCredit {
            return LetterOfCredit::query()->create([
                'number' => $numbers->next('letter_of_credit'),
                'kind' => 'sight',
                'supplier_id' => $coats,
                'bank_account_id' => $bankId,
                'currency_id' => $this->usdId,
                'exchange_rate' => 122.50,
                'amount' => 80000,
                'tolerance_pct' => 5,
                'margin_pct' => 10,
                'tenor_days' => 0,
                'status' => 'draft',
                'remarks' => 'local-catalogue:lc-draft',
                'created_by' => $this->userId,
            ]);
        });

        $applied = DB::transaction(function () use ($numbers, $coats, $bankId): LetterOfCredit {
            return LetterOfCredit::query()->create([
                'number' => $numbers->next('letter_of_credit'),
                'kind' => 'usance',
                'supplier_id' => $coats,
                'bank_account_id' => $bankId,
                'currency_id' => $this->usdId,
                'exchange_rate' => 122.50,
                'amount' => 120000,
                'tolerance_pct' => 5,
                'margin_pct' => 15,
                'tenor_days' => 90,
                'applied_on' => now()->subDays(12)->toDateString(),
                'expiry_date' => now()->addMonths(4)->toDateString(),
                'last_shipment_date' => now()->addMonths(3)->toDateString(),
                'incoterm' => 'CIF',
                'port_of_loading' => 'Felixstowe',
                'port_of_discharge' => 'Chattogram',
                'status' => 'applied',
                'remarks' => 'local-catalogue:lc-applied',
                'created_by' => $this->userId,
            ]);
        });

        $opened = DB::transaction(function () use ($numbers, $coats, $bankId, $yarnPo): LetterOfCredit {
            $lc = LetterOfCredit::query()->create([
                'number' => $numbers->next('letter_of_credit'),
                'lc_no' => 'HSBC-DLC-26-4418',
                'kind' => 'sight',
                'supplier_id' => $coats,
                'bank_account_id' => $bankId,
                'currency_id' => $this->usdId,
                'exchange_rate' => 122.50,
                'amount' => 365000,
                'tolerance_pct' => 5,
                'margin_pct' => 10,
                'applied_on' => now()->subDays(40)->toDateString(),
                'issued_on' => now()->subDays(28)->toDateString(),
                'expiry_date' => now()->addMonths(3)->toDateString(),
                'last_shipment_date' => now()->addMonths(2)->toDateString(),
                'incoterm' => 'CIF',
                'port_of_loading' => 'Felixstowe',
                'port_of_discharge' => 'Chattogram',
                'status' => 'opened',
                'remarks' => 'local-catalogue:lc-opened',
                'created_by' => $this->userId,
            ]);

            if ($yarnPo !== null) {
                DB::table('lc_purchase_orders')->insert([
                    'lc_id' => $lc->id,
                    'po_id' => $yarnPo->id,
                    'covered_amount' => $yarnPo->total,
                ]);
            }

            return $lc;
        });

        $shipped = DB::transaction(function () use ($numbers, $inks, $bankId): LetterOfCredit {
            return LetterOfCredit::query()->create([
                'number' => $numbers->next('letter_of_credit'),
                'lc_no' => 'HSBC-DLC-26-3901',
                'kind' => 'tt',
                'supplier_id' => $inks,
                'bank_account_id' => $bankId,
                'currency_id' => $this->usdId,
                'exchange_rate' => 122.50,
                'amount' => 22000,
                'applied_on' => now()->subDays(50)->toDateString(),
                'issued_on' => now()->subDays(44)->toDateString(),
                'expiry_date' => now()->addMonth()->toDateString(),
                'last_shipment_date' => now()->subDays(8)->toDateString(),
                'incoterm' => 'CFR',
                'port_of_loading' => 'Southampton',
                'port_of_discharge' => 'Chattogram',
                'status' => 'shipped',
                'remarks' => 'local-catalogue:lc-shipped',
                'created_by' => $this->userId,
            ]);
        });

        DB::transaction(function () use ($numbers, $inks): void {
            ImportShipment::query()->create([
                'number' => $numbers->next('import_shipment'),
                'supplier_id' => $inks,
                'currency_id' => $this->usdId,
                'mode' => 'air',
                'exchange_rate' => 122.50,
                'goods_value' => 8800,
                'status' => 'draft',
                'remarks' => 'local-catalogue:imp-draft',
                'created_by' => $this->userId,
            ]);
        });

        DB::transaction(function () use ($numbers, $opened, $coats): void {
            ImportShipment::query()->create([
                'number' => $numbers->next('import_shipment'),
                'lc_id' => $opened->id,
                'supplier_id' => $coats,
                'invoice_no' => 'CUK-2026-5120',
                'invoice_date' => now()->subDays(6)->toDateString(),
                'transport_doc_no' => 'MAEU-8821451',
                'mode' => 'sea',
                'carrier' => 'Maersk',
                'etd' => now()->subDays(5)->toDateString(),
                'eta' => now()->addDays(22)->toDateString(),
                'port_of_loading' => 'Felixstowe',
                'port_of_discharge' => 'Chattogram',
                'incoterm' => 'CIF',
                'currency_id' => $this->usdId,
                'exchange_rate' => 122.50,
                'goods_value' => 365000,
                'status' => 'in_transit',
                'remarks' => 'local-catalogue:imp-sea',
                'created_by' => $this->userId,
            ]);
        });

        $cleared = DB::transaction(function () use ($numbers, $shipped, $inks): ImportShipment {
            return ImportShipment::query()->create([
                'number' => $numbers->next('import_shipment'),
                'lc_id' => $shipped->id,
                'supplier_id' => $inks,
                'invoice_no' => 'PPI-26-771',
                'invoice_date' => now()->subDays(20)->toDateString(),
                'transport_doc_no' => 'EK-449821',
                'mode' => 'air',
                'carrier' => 'Emirates SkyCargo',
                'etd' => now()->subDays(18)->toDateString(),
                'eta' => now()->subDays(16)->toDateString(),
                'arrived_on' => now()->subDays(16)->toDateString(),
                'cleared_on' => now()->subDays(12)->toDateString(),
                'bill_of_entry' => 'C-26-88921',
                'be_date' => now()->subDays(12)->toDateString(),
                'port_of_loading' => 'Southampton',
                'port_of_discharge' => 'Dhaka',
                'incoterm' => 'CFR',
                'currency_id' => $this->usdId,
                'exchange_rate' => 122.50,
                'goods_value' => 22000,
                'cost_total' => 185000,
                'status' => 'cleared',
                'remarks' => 'local-catalogue:imp-cleared',
                'created_by' => $this->userId,
            ]);
        });

        ImportCost::query()->create([
            'shipment_id' => $cleared->id,
            'cost_type' => 'freight',
            'description' => 'Air freight PPI-26-771',
            'incurred_on' => now()->subDays(16)->toDateString(),
            'currency_id' => $this->usdId,
            'exchange_rate' => 122.50,
            'amount' => 920,
            'base_amount' => 112700,
            'is_allocable' => true,
            'created_by' => $this->userId,
        ]);
        ImportCost::query()->create([
            'shipment_id' => $cleared->id,
            'cost_type' => 'duty',
            'description' => 'Customs duty',
            'reference_no' => 'C-26-88921',
            'incurred_on' => now()->subDays(12)->toDateString(),
            'currency_id' => $this->bdtId ?: $this->usdId,
            'exchange_rate' => 1,
            'amount' => 48000,
            'base_amount' => 48000,
            'is_allocable' => true,
            'created_by' => $this->userId,
        ]);
        ImportCost::query()->create([
            'shipment_id' => $cleared->id,
            'cost_type' => 'bank_charge',
            'description' => 'LC commission',
            'incurred_on' => now()->subDays(12)->toDateString(),
            'currency_id' => $this->bdtId ?: $this->usdId,
            'exchange_rate' => 1,
            'amount' => 12500,
            'base_amount' => 12500,
            'is_allocable' => false,
            'created_by' => $this->userId,
        ]);

        $this->receiveInkAgainst($cleared);
        $this->billAgainstDemoGrn();

        unset($draft, $applied);
    }

    private function receiveInkAgainst(ImportShipment $shipment): void
    {
        $inkId = $this->items['INK-FLX-BLK'] ?? null;
        $kg = $this->uoms['kg'] ?? null;
        $warehouse = (int) DB::table('warehouses')->where('code', 'RM')->value('id');

        if ($inkId === null || $kg === null || $warehouse < 1) {
            return;
        }

        if (DB::table('grns')->where('import_shipment_id', $shipment->id)->exists()) {
            return;
        }

        $posting = app(StockPostingService::class);
        $numbers = app(NumberAllocator::class);

        DB::transaction(function () use ($shipment, $inkId, $kg, $warehouse, $posting, $numbers): void {
            $grn = new Grn;
            $grn->forceFill([
                'number' => $numbers->next('grn'),
                'supplier_id' => $shipment->supplier_id,
                'import_shipment_id' => $shipment->id,
                'warehouse_id' => $warehouse,
                'received_on' => now()->subDays(11)->toDateString(),
                'invoice_no' => $shipment->invoice_no,
                'status' => 'posted',
                'remarks' => 'local-catalogue:grn-ink',
                'created_by' => $this->userId,
                'created_at' => now(),
            ])->save();

            $grnLineId = DB::table('grn_lines')->insertGetId([
                'grn_id' => $grn->id,
                'line_no' => 1,
                'item_id' => $inkId,
                'uom_id' => $kg,
                'received_qty' => 40,
                'accepted_qty' => 40,
                'rejected_qty' => 0,
                'rate' => 2200,
                'landed_rate' => 2200,
            ]);

            $posting->receive(
                [
                    'lot_no' => $numbers->nextLotNumber(),
                    'item_id' => $inkId,
                    'kind' => 'raw_material',
                    'warehouse_id' => $warehouse,
                    'uom_id' => $kg,
                    'grn_line_id' => $grnLineId,
                    'received_on' => now()->subDays(11)->toDateString(),
                    'status' => 'available',
                ],
                40.0,
                2200.0,
                $grn,
            );
        });
    }

    private function billAgainstDemoGrn(): void
    {
        if (SupplierBill::query()->where('bill_no', 'like', 'LOCAL-%')->exists()) {
            return;
        }

        $grn = Grn::query()->where('status', 'posted')->orderBy('id')->first();
        $pack = (int) DB::table('suppliers')->where('code', 'SUP-PACK-BD')->value('id');
        $carton = $this->items['PKG-CTN-5PLY'] ?? null;

        if ($grn === null) {
            return;
        }

        DB::transaction(function () use ($grn): void {
            $bill = new SupplierBill;
            $bill->forceFill([
                'number' => app(NumberAllocator::class)->next('supplier_bill'),
                'supplier_id' => $grn->supplier_id,
                'grn_id' => $grn->id,
                'bill_no' => 'LOCAL-CUK-4471',
                'bill_date' => now()->subDays(8)->toDateString(),
                'due_date' => now()->addDays(22)->toDateString(),
                'currency_id' => $this->usdId,
                'exchange_rate' => 122.50,
                'subtotal' => 350000,
                'tax_amount' => 0,
                'total' => 350000,
                'paid_amount' => 0,
                'status' => SupplierBill::DRAFT,
                'created_by' => $this->userId,
            ])->save();

            SupplierBillLine::query()->create([
                'supplier_bill_id' => $bill->id,
                'line_no' => 1,
                'description' => 'Yarn per GRN '.$grn->number,
                'qty' => 180,
                'rate' => 1465,
                'amount' => 263700,
            ]);
        });

        if ($pack > 0 && $carton !== null) {
            $bill = new SupplierBill;
            $bill->forceFill([
                'supplier_id' => $pack,
                'bill_no' => 'LOCAL-DP-882',
                'bill_date' => now()->subDays(4)->toDateString(),
                'due_date' => now()->addDays(10)->toDateString(),
                'currency_id' => $this->bdtId ?: $this->usdId,
                'exchange_rate' => 1,
                'subtotal' => 14400,
                'total' => 14400,
                'status' => SupplierBill::DRAFT,
                'created_by' => $this->userId,
            ])->save();
            SupplierBillLine::query()->create([
                'supplier_bill_id' => $bill->id,
                'line_no' => 1,
                'item_id' => $carton,
                'description' => 'Cartons — open PO',
                'qty' => 300,
                'rate' => 48,
                'amount' => 14400,
            ]);
        }
    }

    private function qualityAndExpenses(): void
    {
        $this->labReports();
        $this->ncrs();
        $this->expenses();
    }

    private function labReports(): void
    {
        if (TestReport::query()->where('remarks', 'like', 'local-catalogue:%')->exists()) {
            return;
        }

        $product = Product::query()->where('code', 'PRD-NFJ-CARE-01')->first();
        $techId = (int) DB::table('employees')->where('code', 'EMP-0014')->value('id');
        $tests = DB::table('lab_tests')->where('is_active', true)->orderBy('id')->limit(4)->get();

        $draft = TestReport::query()->create([
            'product_id' => $product?->id,
            'customer_id' => $product?->customer_id,
            'tested_on' => now()->toDateString(),
            'technician_id' => $techId ?: null,
            'overall_result' => 'pending',
            'status' => TestReport::DRAFT,
            'remarks' => 'local-catalogue:lab-draft',
            'created_by' => $this->userId,
        ]);
        $draft->forceFill(['created_by' => $this->userId])->save();

        $issued = TestReport::query()->create([
            'product_id' => $product?->id,
            'customer_id' => $product?->customer_id,
            'tested_on' => now()->subDays(3)->toDateString(),
            'technician_id' => $techId ?: null,
            'overall_result' => 'pass',
            'status' => TestReport::DRAFT,
            'remarks' => 'local-catalogue:lab-issued',
            'created_by' => $this->userId,
        ]);
        $issued->forceFill(['created_by' => $this->userId])->save();

        foreach ($tests as $index => $test) {
            TestReportLine::query()->create([
                'test_report_id' => $issued->id,
                'lab_test_id' => $test->id,
                'result_value' => $index === 3 ? '4.5' : '4.0',
                'pass_value' => $test->default_pass_value,
                'result' => 'pass',
            ]);
        }

        DB::transaction(fn () => app(TestReportStateMachine::class)->transition($issued, TestReport::ISSUED));
    }

    private function ncrs(): void
    {
        if (Ncr::query()->where('description', 'like', 'local-catalogue:%')->exists()) {
            return;
        }

        $qualityId = (int) User::query()->where('email', 'quality@maheenlabel.test')->value('id');
        $supplierId = (int) DB::table('suppliers')->where('code', 'SUP-YARN-UK')->value('id');
        $customerId = (int) DB::table('customers')->where('code', 'CUST-001')->value('id');
        $numbers = app(NumberAllocator::class);

        DB::transaction(function () use ($numbers, $qualityId, $supplierId, $customerId): void {
            $open = Ncr::query()->create([
                'number' => $numbers->next('ncr'),
                'source' => 'incoming',
                'supplier_id' => $supplierId ?: null,
                'raised_on' => now()->subDays(2)->toDateString(),
                'description' => 'local-catalogue: shade variation on incoming navy yarn lot.',
                'severity' => 'major',
                'status' => Ncr::OPEN,
                'raised_by' => $this->userId,
            ]);

            $investigating = Ncr::query()->create([
                'number' => $numbers->next('ncr'),
                'source' => 'lab',
                'customer_id' => $customerId ?: null,
                'raised_on' => now()->subDays(9)->toDateString(),
                'description' => 'local-catalogue: wash-fastness borderline on a flexo size sticker.',
                'severity' => 'minor',
                'status' => Ncr::INVESTIGATING,
                'raised_by' => $this->userId,
                'owner_id' => $qualityId ?: null,
            ]);

            Capa::query()->create([
                'ncr_id' => $investigating->id,
                'kind' => Capa::KIND_CORRECTIVE,
                'root_cause' => 'Ink lay-down below the house GSM on the last plate.',
                'action' => 'Remake the plate and retest the next lot.',
                'responsible_id' => $qualityId ?: null,
                'due_date' => now()->addDays(10)->toDateString(),
                'status' => Capa::IN_PROGRESS,
            ]);

            Ncr::query()->create([
                'number' => $numbers->next('ncr'),
                'source' => 'customer_complaint',
                'customer_id' => $customerId ?: null,
                'raised_on' => now()->subDays(21)->toDateString(),
                'closed_on' => now()->subDays(4)->toDateString(),
                'description' => 'local-catalogue: mixed sizes in one carton — closed after pack-line check.',
                'severity' => 'critical',
                'status' => Ncr::CLOSED,
                'raised_by' => $this->userId,
                'owner_id' => $qualityId ?: null,
            ]);

            unset($open);
        });
    }

    private function expenses(): void
    {
        if (DB::table('expenses')->where('description', 'like', 'local-catalogue:%')->exists()) {
            return;
        }

        $fuel = (int) DB::table('expense_categories')->where('code', 'FUEL')->value('id');
        $cnf = (int) DB::table('expense_categories')->where('code', 'CNF')->value('id');
        $office = (int) DB::table('expense_categories')->where('code', 'OFFICE')->value('id');
        $bank = DB::table('bank_accounts')->where('code', 'BA-BDT-CUR')->value('id');
        $numbers = app(NumberAllocator::class);
        $currency = $this->bdtId ?: $this->usdId;

        $rows = [
            [
                'expense_date' => now()->subDays(6)->toDateString(),
                'expense_category_id' => $fuel,
                'factory_unit_id' => $this->unitId,
                'payee' => 'Padma Oil depot',
                'description' => 'local-catalogue: generator diesel',
                'currency_id' => $currency,
                'amount' => 48000,
                'total' => 48000,
                'method' => 'cash',
                'status' => 'paid',
                'approved_by' => $this->userId,
                'approved_at' => now()->subDays(5),
                'paid_on' => now()->subDays(5)->toDateString(),
                'allocate' => true,
            ],
            [
                'expense_date' => now()->subDays(2)->toDateString(),
                'expense_category_id' => $cnf,
                'factory_unit_id' => $this->unitId,
                'payee' => 'Chittagong C&F',
                'description' => 'local-catalogue: C&F on ink air shipment',
                'currency_id' => $currency,
                'amount' => 18500,
                'total' => 18500,
                'method' => 'bank_transfer',
                'bank_account_id' => $bank,
                'status' => 'approved',
                'approved_by' => $this->userId,
                'approved_at' => now()->subDay(),
                'allocate' => true,
            ],
            [
                'expense_date' => now()->toDateString(),
                'expense_category_id' => $office,
                'payee' => 'Staples BD',
                'description' => 'local-catalogue: stationery',
                'currency_id' => $currency,
                'amount' => 2400,
                'total' => 2400,
                'method' => 'cash',
                'status' => 'draft',
                'allocate' => false,
            ],
        ];

        foreach ($rows as $row) {
            $allocate = $row['allocate'];
            unset($row['allocate']);

            DB::transaction(function () use ($numbers, $row, $allocate): void {
                $expense = \App\Modules\Finance\Models\Expense::query()->create([
                    ...$row,
                    'exchange_rate' => 1,
                    'tax_amount' => 0,
                    'created_by' => $this->userId,
                ]);

                if ($allocate) {
                    $expense->forceFill(['number' => $numbers->next('expense')])->save();
                }
            });
        }
    }
}
