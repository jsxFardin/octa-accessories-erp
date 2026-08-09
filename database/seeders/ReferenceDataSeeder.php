<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Everything 02-database-schema §8 lists as required before first use.
 *
 * These are lookups, not sample data: the AQL table is ISO 2859-1, the lab tests are the nine
 * the factory advertises, and the wastage defaults are the seed figures from BR-8. A fresh
 * install can quote and produce correctly without anyone typing them in.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->uoms();
        $this->currencies();
        $this->taxes();
        $this->paymentTerms();
        $this->factoryUnits();
        $this->machineGroups();
        $this->warehouses();
        $this->itemCategories();
        $this->routings();
        $this->aqlPlans();
        $this->labTests();
        $this->defects();
        $this->downtimeReasons();
        $this->certifications();
        $this->numberSequences();
        $this->settings();
    }

    /** BR-2 — base UoM per item class, plus the units those classes are transacted in. */
    private function uoms(): void
    {
        $this->upsert('uoms', 'code', [
            ['code' => 'pcs', 'name' => 'Pieces', 'dimension' => 'count'],
            ['code' => 'M', 'name' => 'Thousand pieces', 'dimension' => 'count'],
            ['code' => 'm', 'name' => 'Metre', 'dimension' => 'length'],
            ['code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'mass'],
            ['code' => 'g', 'name' => 'Gram', 'dimension' => 'mass'],
            ['code' => 'l', 'name' => 'Litre', 'dimension' => 'volume'],
            ['code' => 'sheet', 'name' => 'Sheet', 'dimension' => 'count'],
            ['code' => 'm2', 'name' => 'Square metre', 'dimension' => 'area'],
            ['code' => 'roll', 'name' => 'Roll', 'dimension' => 'count'],
            ['code' => 'cone', 'name' => 'Cone', 'dimension' => 'count'],
            ['code' => 'carton', 'name' => 'Carton', 'dimension' => 'count'],
            ['code' => 'hr', 'name' => 'Hour', 'dimension' => 'time'],
        ]);

        $uoms = DB::table('uoms')->pluck('id', 'code');

        // BR-3 step 3: global conversions. Item- and lot-level rows override these.
        $this->upsertConversions([
            ['from' => 'kg', 'to' => 'g', 'factor' => 1000],
            ['from' => 'g', 'to' => 'kg', 'factor' => 0.001],
            ['from' => 'M', 'to' => 'pcs', 'factor' => 1000],
            ['from' => 'pcs', 'to' => 'M', 'factor' => 0.001],
        ], $uoms);
    }

    /**
     * @param  list<array{from: string, to: string, factor: float|int}>  $rows
     * @param  \Illuminate\Support\Collection<string, int>  $uoms
     */
    private function upsertConversions(array $rows, $uoms): void
    {
        foreach ($rows as $row) {
            DB::table('uom_conversions')->updateOrInsert(
                [
                    'item_id' => null,
                    'from_uom_id' => $uoms[$row['from']],
                    'to_uom_id' => $uoms[$row['to']],
                ],
                ['factor' => $row['factor']],
            );
        }
    }

    private function currencies(): void
    {
        // Only one row may carry is_base — `currencies_one_base_uq` over the generated
        // `base_key` column enforces it.
        $this->upsert('currencies', 'code', [
            ['code' => 'BDT', 'name' => 'Bangladeshi Taka', 'symbol' => '৳', 'is_base' => true],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'is_base' => false],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_base' => false],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£', 'is_base' => false],
        ]);

        $currencies = DB::table('currencies')->pluck('id', 'code');

        foreach (['BDT' => 1, 'USD' => 122.50, 'EUR' => 133.20, 'GBP' => 156.40] as $code => $rate) {
            DB::table('exchange_rates')->updateOrInsert(
                ['currency_id' => $currencies[$code], 'effective_on' => now()->toDateString()],
                ['rate_to_base' => $rate],
            );
        }
    }

    private function taxes(): void
    {
        $this->upsert('taxes', 'code', [
            ['code' => 'VAT15', 'name' => 'VAT 15%', 'rate_pct' => 15.0, 'kind' => 'vat'],
            ['code' => 'VAT5', 'name' => 'VAT 5%', 'rate_pct' => 5.0, 'kind' => 'vat'],
            ['code' => 'VAT0', 'name' => 'Zero rated / export', 'rate_pct' => 0.0, 'kind' => 'vat'],
            ['code' => 'AIT5', 'name' => 'Advance income tax 5%', 'rate_pct' => 5.0, 'kind' => 'ait'],
        ]);
    }

    private function paymentTerms(): void
    {
        $this->upsert('payment_terms', 'code', [
            ['code' => 'ADV', 'name' => 'Advance', 'net_days' => 0, 'is_advance' => true, 'is_lc' => false],
            ['code' => 'NET30', 'name' => 'Net 30 days', 'net_days' => 30, 'is_advance' => false, 'is_lc' => false],
            ['code' => 'NET45', 'name' => 'Net 45 days', 'net_days' => 45, 'is_advance' => false, 'is_lc' => false],
            ['code' => 'NET60', 'name' => 'Net 60 days', 'net_days' => 60, 'is_advance' => false, 'is_lc' => false],
            ['code' => 'LC90', 'name' => 'LC at 90 days sight', 'net_days' => 90, 'is_advance' => false, 'is_lc' => true],
        ]);
    }

    /** AD-4 — multi-unit works from day one without multi-tenancy. */
    private function factoryUnits(): void
    {
        $this->upsert('factory_units', 'code', [
            ['code' => 'ML-1', 'name' => 'Maheen Label — Unit 1', 'address' => 'Gazipur, Dhaka', 'timezone' => 'Asia/Dhaka'],
        ]);

        $unitId = DB::table('factory_units')->where('code', 'ML-1')->value('id');

        $this->upsertScoped('departments', ['factory_unit_id', 'code'], [
            ['factory_unit_id' => $unitId, 'code' => 'STUDIO', 'name' => 'Design studio', 'kind' => 'design'],
            ['factory_unit_id' => $unitId, 'code' => 'PLATE', 'name' => 'Plate & screen making', 'kind' => 'plate'],
            ['factory_unit_id' => $unitId, 'code' => 'WEAV', 'name' => 'Weaving', 'kind' => 'weaving'],
            ['factory_unit_id' => $unitId, 'code' => 'PRINT', 'name' => 'Printing', 'kind' => 'printing'],
            ['factory_unit_id' => $unitId, 'code' => 'FINISH', 'name' => 'Cutting & finishing', 'kind' => 'cutting'],
            ['factory_unit_id' => $unitId, 'code' => 'QC', 'name' => 'Quality control', 'kind' => 'qc'],
            ['factory_unit_id' => $unitId, 'code' => 'LAB', 'name' => 'Laboratory', 'kind' => 'lab'],
            ['factory_unit_id' => $unitId, 'code' => 'STORE', 'name' => 'Stores', 'kind' => 'store'],
            ['factory_unit_id' => $unitId, 'code' => 'DISP', 'name' => 'Dispatch', 'kind' => 'dispatch'],
        ]);

        $this->upsertScoped('shifts', ['factory_unit_id', 'code'], [
            ['factory_unit_id' => $unitId, 'code' => 'A', 'name' => 'Morning', 'starts_at' => '06:00:00', 'ends_at' => '14:00:00', 'break_minutes' => 30],
            ['factory_unit_id' => $unitId, 'code' => 'B', 'name' => 'Evening', 'starts_at' => '14:00:00', 'ends_at' => '22:00:00', 'break_minutes' => 30],
            ['factory_unit_id' => $unitId, 'code' => 'C', 'name' => 'Night', 'starts_at' => '22:00:00', 'ends_at' => '06:00:00', 'break_minutes' => 30],
        ]);
    }

    private function machineGroups(): void
    {
        $this->upsert('machine_groups', 'code', [
            ['code' => 'DESIGN', 'name' => 'Design & pre-press', 'process_type' => 'design', 'output_uom' => 'pcs'],
            ['code' => 'WARP', 'name' => 'Warping', 'process_type' => 'warping', 'output_uom' => 'metre'],
            ['code' => 'LOOM', 'name' => 'Needle looms', 'process_type' => 'weaving', 'output_uom' => 'metre'],
            ['code' => 'FLEXO', 'name' => 'Flexo presses', 'process_type' => 'flexo', 'output_uom' => 'metre'],
            ['code' => 'SCREEN', 'name' => 'Screen tables', 'process_type' => 'screen', 'output_uom' => 'metre'],
            ['code' => 'HTRANS', 'name' => 'Heat transfer presses', 'process_type' => 'heat_transfer', 'output_uom' => 'metre'],
            ['code' => 'OFFSET', 'name' => 'Offset presses', 'process_type' => 'offset', 'output_uom' => 'sheet'],
            ['code' => 'THERMAL', 'name' => 'Thermal printers', 'process_type' => 'thermal', 'output_uom' => 'pcs'],
            ['code' => 'SLIT', 'name' => 'Slitting', 'process_type' => 'slitting', 'output_uom' => 'metre'],
            ['code' => 'CUT', 'name' => 'Cutting', 'process_type' => 'cutting', 'output_uom' => 'metre'],
            ['code' => 'FOLD', 'name' => 'Folding', 'process_type' => 'folding', 'output_uom' => 'pcs'],
            ['code' => 'PACK', 'name' => 'Packing', 'process_type' => 'packing', 'output_uom' => 'pcs'],
        ]);
    }

    private function warehouses(): void
    {
        $unitId = DB::table('factory_units')->where('code', 'ML-1')->value('id');

        // `is_nettable = false` keeps scrap and transit stock out of MRP availability (BR-24).
        $this->upsert('warehouses', 'code', [
            ['factory_unit_id' => $unitId, 'code' => 'RM', 'name' => 'Raw material store', 'kind' => 'raw_material', 'is_nettable' => true],
            ['factory_unit_id' => $unitId, 'code' => 'INK', 'name' => 'Ink & chemical store', 'kind' => 'ink_chemical', 'is_nettable' => true],
            ['factory_unit_id' => $unitId, 'code' => 'TOOL', 'name' => 'Tool room', 'kind' => 'tool', 'is_nettable' => true],
            ['factory_unit_id' => $unitId, 'code' => 'WIP', 'name' => 'Work in progress', 'kind' => 'wip', 'is_nettable' => true],
            ['factory_unit_id' => $unitId, 'code' => 'FG', 'name' => 'Finished goods', 'kind' => 'finished_goods', 'is_nettable' => true],
            ['factory_unit_id' => $unitId, 'code' => 'PACK', 'name' => 'Packing material', 'kind' => 'packing', 'is_nettable' => true],
            ['factory_unit_id' => $unitId, 'code' => 'SCRAP', 'name' => 'Scrap', 'kind' => 'scrap', 'is_nettable' => false],
            ['factory_unit_id' => $unitId, 'code' => 'TRANSIT', 'name' => 'In transit', 'kind' => 'transit', 'is_nettable' => false],
        ]);
    }

    private function itemCategories(): void
    {
        $this->upsert('item_categories', 'code', [
            ['code' => 'YARN', 'name' => 'Yarn', 'item_class' => 'yarn'],
            ['code' => 'RIBBON', 'name' => 'Ribbon & satin', 'item_class' => 'ribbon'],
            ['code' => 'TAPE', 'name' => 'Twill / cotton / elastic tape', 'item_class' => 'tape'],
            ['code' => 'INK', 'name' => 'Ink', 'item_class' => 'ink'],
            ['code' => 'CHEM', 'name' => 'Chemicals & auxiliaries', 'item_class' => 'chemical'],
            ['code' => 'PAPER', 'name' => 'Art card & paper', 'item_class' => 'paper'],
            ['code' => 'FILM', 'name' => 'Heat transfer film', 'item_class' => 'film'],
            ['code' => 'ADHESIVE', 'name' => 'Adhesive & powder', 'item_class' => 'adhesive'],
            ['code' => 'TOOLSTK', 'name' => 'Plate & screen stock', 'item_class' => 'tool_stock'],
            ['code' => 'PACKMAT', 'name' => 'Polybags, cartons, string', 'item_class' => 'packing'],
            ['code' => 'SPARE', 'name' => 'Machine spares', 'item_class' => 'spare'],
        ]);
    }

    /**
     * BR-8 — one default routing per product type, carrying the seed wastage and make-ready
     * figures. `consumes_web = false` on packing and QC keeps them out of the additive
     * wastage total.
     */
    private function routings(): void
    {
        $groups = DB::table('machine_groups')->pluck('id', 'code');

        $routings = [
            ['code' => 'RT-WOVEN', 'name' => 'Woven label — standard', 'product_type' => 'woven', 'max_lot_size' => 200000, 'operations' => [
                ['WARP', 'warp', 'Warping', 1.5, 30, 400, 45, 0.5, true, false],
                ['LOOM', 'weave', 'Weaving', 3.0, 50, 120, 60, 0.25, true, true],
                ['CUT', 'cut', 'Ultrasonic cutting', 2.0, 10, 300, 20, 1.0, true, false],
                ['FOLD', 'fold', 'Folding', 0.0, 0, 500, 15, 1.0, false, false],
                ['PACK', 'pack', 'Packing', 0.0, 0, 5000, 10, 2.0, false, false],
            ]],
            ['code' => 'RT-FLEXO', 'name' => 'Flexo printed label — standard', 'product_type' => 'flexo', 'max_lot_size' => 500000, 'operations' => [
                ['DESIGN', 'plate', 'Plate making', 0.0, 0, 20, 30, 1.0, false, false],
                ['FLEXO', 'print', 'Flexo printing', 2.5, 80, 900, 90, 1.0, true, true],
                ['SLIT', 'slit', 'Slitting', 1.0, 10, 1200, 20, 1.0, true, false],
                ['CUT', 'cut', 'Cutting', 2.0, 10, 600, 20, 1.0, true, false],
                ['PACK', 'pack', 'Packing', 0.0, 0, 5000, 10, 2.0, false, false],
            ]],
            ['code' => 'RT-SCREEN', 'name' => 'Screen printed label — standard', 'product_type' => 'screen', 'max_lot_size' => 100000, 'operations' => [
                ['DESIGN', 'screen', 'Screen exposure', 0.0, 0, 10, 45, 1.0, false, false],
                ['SCREEN', 'print', 'Screen printing', 4.0, 40, 250, 60, 2.0, true, true],
                ['CUT', 'cut', 'Cutting', 2.0, 10, 400, 20, 1.0, true, false],
                ['PACK', 'pack', 'Packing', 0.0, 0, 5000, 10, 2.0, false, false],
            ]],
            ['code' => 'RT-HTRANS', 'name' => 'Heat transfer label — standard', 'product_type' => 'heat_transfer', 'max_lot_size' => 200000, 'operations' => [
                ['DESIGN', 'plate', 'Plate making', 0.0, 0, 20, 30, 1.0, false, false],
                ['HTRANS', 'print', 'Heat transfer printing', 3.0, 25, 400, 45, 1.0, true, true],
                ['CUT', 'cut', 'Die cutting', 2.0, 10, 500, 20, 1.0, true, false],
                ['PACK', 'pack', 'Packing', 0.0, 0, 5000, 10, 2.0, false, false],
            ]],
            ['code' => 'RT-OFFSET', 'name' => 'Offset tag / ticket — standard', 'product_type' => 'offset_tag', 'max_lot_size' => 300000, 'operations' => [
                ['DESIGN', 'plate', 'Offset plate making', 0.0, 0, 12, 40, 1.0, false, false],
                ['OFFSET', 'print', 'Offset printing', 3.5, 200, 4000, 120, 1.5, true, true],
                ['CUT', 'die', 'Die cutting', 2.0, 50, 2500, 30, 1.0, true, false],
                ['PACK', 'pack', 'Packing & stringing', 0.0, 0, 4000, 10, 2.0, false, false],
            ]],
            ['code' => 'RT-THERMAL', 'name' => 'Thermal printed label — standard', 'product_type' => 'thermal', 'max_lot_size' => 500000, 'operations' => [
                ['THERMAL', 'print', 'Thermal printing', 1.0, 20, 6000, 10, 0.5, true, true],
                ['PACK', 'pack', 'Packing', 0.0, 0, 5000, 10, 2.0, false, false],
            ]],
        ];

        foreach ($routings as $routing) {
            DB::table('routings')->updateOrInsert(
                ['code' => $routing['code']],
                [
                    'name' => $routing['name'],
                    'product_type' => $routing['product_type'],
                    'max_lot_size' => $routing['max_lot_size'],
                    'is_default' => true,
                    'is_active' => true,
                ],
            );

            $routingId = DB::table('routings')->where('code', $routing['code'])->value('id');

            foreach ($routing['operations'] as $seq => $op) {
                [$group, $code, $name, $wastage, $setupQty, $rate, $setupMinutes, $manning, $consumesWeb, $requiresQc] = $op;

                DB::table('routing_operations')->updateOrInsert(
                    ['routing_id' => $routingId, 'sequence_no' => $seq + 1],
                    [
                        'code' => $code,
                        'name' => $name,
                        'machine_group_id' => $groups[$group] ?? null,
                        'std_rate_per_hour' => $rate,
                        'setup_minutes' => $setupMinutes,
                        'setup_qty' => $setupQty,
                        'wastage_pct' => $wastage,
                        'manning_level' => $manning,
                        'consumes_web' => $consumesWeb,
                        'allow_parallel' => false,
                        'requires_qc' => $requiresQc,
                    ],
                );
            }
        }
    }

    /** BR-30 — ISO 2859-1, General Inspection Level II, AQL 2.5. Data, not code. */
    private function aqlPlans(): void
    {
        $bands = [
            [51, 90, 13, 1, 2],
            [91, 150, 20, 1, 2],
            [151, 280, 32, 2, 3],
            [281, 500, 50, 3, 4],
            [501, 1200, 80, 5, 6],
            [1201, 3200, 125, 7, 8],
            [3201, 10000, 200, 10, 11],
            [10001, 35000, 315, 14, 15],
            [35001, 150000, 500, 21, 22],
            [150001, 500000, 800, 21, 22],
            [500001, 99999999, 1250, 21, 22],
        ];

        foreach ($bands as [$from, $to, $sample, $accept, $reject]) {
            DB::table('aql_plans')->updateOrInsert(
                [
                    'standard' => 'ISO 2859-1',
                    'inspection_level' => 'II',
                    'aql_value' => 2.5,
                    'lot_size_from' => $from,
                ],
                [
                    'lot_size_to' => $to,
                    'sample_size' => $sample,
                    'accept_number' => $accept,
                    'reject_number' => $reject,
                ],
            );
        }
    }

    /** BR-32 — the nine tests the factory advertises, with methods and house thresholds. */
    private function labTests(): void
    {
        $this->upsert('lab_tests', 'code', [
            ['code' => 'CF-WASH', 'name' => 'Colour fastness to washing', 'method' => 'ISO 105-C06', 'scale' => 'grey_1_5', 'default_pass_value' => '4.0', 'unit' => 'grade'],
            ['code' => 'CF-RUB-D', 'name' => 'Colour fastness to rubbing (dry)', 'method' => 'ISO 105-X12', 'scale' => 'grey_1_5', 'default_pass_value' => '4.0', 'unit' => 'grade'],
            ['code' => 'CF-RUB-W', 'name' => 'Colour fastness to rubbing (wet)', 'method' => 'ISO 105-X12', 'scale' => 'grey_1_5', 'default_pass_value' => '3.0', 'unit' => 'grade'],
            ['code' => 'CF-IRON', 'name' => 'Colour fastness to hot ironing', 'method' => 'ISO 105-X11', 'scale' => 'grey_1_5', 'default_pass_value' => '4.0', 'unit' => 'grade'],
            ['code' => 'SUBLIM', 'name' => 'Sublimation / dry heat', 'method' => 'ISO 105-P01', 'scale' => 'grey_1_5', 'default_pass_value' => '4.0', 'unit' => 'grade'],
            ['code' => 'BLEED', 'name' => 'Colour bleeding', 'method' => 'In-house', 'scale' => 'pass_fail', 'default_pass_value' => 'pass', 'unit' => null],
            ['code' => 'STAIN', 'name' => 'Colour staining (multifibre)', 'method' => 'ISO 105-A03', 'scale' => 'grey_1_5', 'default_pass_value' => '3.5', 'unit' => 'grade'],
            ['code' => 'SHRINK', 'name' => 'Dimensional shrinkage', 'method' => 'ISO 5077', 'scale' => 'percent', 'default_pass_value' => '3.0', 'unit' => '%'],
            ['code' => 'SHADE', 'name' => 'Shade variation vs standard', 'method' => 'In-house', 'scale' => 'delta_e', 'default_pass_value' => '1.0', 'unit' => 'ΔE'],
        ]);
    }

    private function defects(): void
    {
        $this->upsert('defects', 'code', [
            ['code' => 'WRONGTXT', 'name' => 'Wrong text / wrong artwork', 'process' => 'general', 'severity' => 'critical'],
            ['code' => 'WRONGCARE', 'name' => 'Incorrect care symbol', 'process' => 'general', 'severity' => 'critical'],
            ['code' => 'MISSCOL', 'name' => 'Missing colour', 'process' => 'printing', 'severity' => 'critical'],
            ['code' => 'SHADEVAR', 'name' => 'Shade variation', 'process' => 'weaving', 'severity' => 'major'],
            ['code' => 'MISSPICK', 'name' => 'Missing pick / weft', 'process' => 'weaving', 'severity' => 'major'],
            ['code' => 'FLOAT', 'name' => 'Float / loose thread', 'process' => 'weaving', 'severity' => 'minor'],
            ['code' => 'SELVEDGE', 'name' => 'Selvedge fray', 'process' => 'weaving', 'severity' => 'minor'],
            ['code' => 'MISREG', 'name' => 'Misregistration', 'process' => 'printing', 'severity' => 'major'],
            ['code' => 'SMUDGE', 'name' => 'Ink smudge', 'process' => 'printing', 'severity' => 'major'],
            ['code' => 'WEAKINK', 'name' => 'Weak ink deposit', 'process' => 'printing', 'severity' => 'minor'],
            ['code' => 'OFFCUT', 'name' => 'Off-centre cut', 'process' => 'cutting', 'severity' => 'major'],
            ['code' => 'FRAYCUT', 'name' => 'Frayed cut edge', 'process' => 'cutting', 'severity' => 'minor'],
            ['code' => 'BADFOLD', 'name' => 'Incorrect fold', 'process' => 'folding', 'severity' => 'major'],
            ['code' => 'SHORTQTY', 'name' => 'Short quantity in bundle', 'process' => 'packing', 'severity' => 'major'],
            ['code' => 'MIXLABEL', 'name' => 'Mixed sizes in one bundle', 'process' => 'packing', 'severity' => 'critical'],
            ['code' => 'DIRTY', 'name' => 'Soiled / dirty mark', 'process' => 'general', 'severity' => 'major'],
        ]);
    }

    private function downtimeReasons(): void
    {
        $this->upsert('downtime_reasons', 'code', [
            ['code' => 'MECH', 'name' => 'Mechanical breakdown', 'category' => 'mechanical', 'is_planned' => false],
            ['code' => 'ELEC', 'name' => 'Electrical fault', 'category' => 'electrical', 'is_planned' => false],
            ['code' => 'MATWAIT', 'name' => 'Waiting for material', 'category' => 'material', 'is_planned' => false],
            ['code' => 'QUALHOLD', 'name' => 'Quality hold', 'category' => 'quality', 'is_planned' => false],
            ['code' => 'CHANGEOVER', 'name' => 'Changeover / shade change', 'category' => 'changeover', 'is_planned' => true],
            ['code' => 'POWER', 'name' => 'Power failure', 'category' => 'power', 'is_planned' => false],
            ['code' => 'MANPOWER', 'name' => 'Operator unavailable', 'category' => 'manpower', 'is_planned' => false],
            ['code' => 'PM', 'name' => 'Preventive maintenance', 'category' => 'planned', 'is_planned' => true],
        ]);
    }

    /**
     * The factory's certificate registry. BR-41 thresholds and the BR-42 maximum conversion
     * factor live on the scope rows, per scheme, because those numbers belong to the standard.
     */
    private function certifications(): void
    {
        $certs = [
            ['scheme' => 'GRS', 'certificate_no' => 'GRS-MDEL-PENDING', 'issuing_body' => 'Control Union', 'min_claim_pct' => 20, 'labelled_claim_pct' => 50],
            ['scheme' => 'FSC', 'certificate_no' => 'FSC-COC-PENDING', 'issuing_body' => 'SGS', 'min_claim_pct' => 70, 'labelled_claim_pct' => 70],
            ['scheme' => 'OEKO_TEX', 'certificate_no' => 'OEKO-100-PENDING', 'issuing_body' => 'Hohenstein', 'min_claim_pct' => 0, 'labelled_claim_pct' => 0],
            ['scheme' => 'ISO_9001', 'certificate_no' => 'ISO9001-PENDING', 'issuing_body' => 'BSI', 'min_claim_pct' => 0, 'labelled_claim_pct' => 0],
            ['scheme' => 'ISO_14001', 'certificate_no' => 'ISO14001-PENDING', 'issuing_body' => 'BSI', 'min_claim_pct' => 0, 'labelled_claim_pct' => 0],
            ['scheme' => 'BSCI', 'certificate_no' => 'BSCI-PENDING', 'issuing_body' => 'amfori', 'min_claim_pct' => 0, 'labelled_claim_pct' => 0],
        ];

        foreach ($certs as $cert) {
            DB::table('certifications')->updateOrInsert(
                ['scheme' => $cert['scheme'], 'certificate_no' => $cert['certificate_no']],
                [
                    'issuing_body' => $cert['issuing_body'],
                    'issued_on' => now()->startOfYear()->toDateString(),
                    'expires_on' => now()->startOfYear()->addYear()->toDateString(),
                    'scope_description' => 'Placeholder pending upload of the signed certificate.',
                    'reminder_days' => 60,
                    'status' => 'active',
                ],
            );

            $certId = DB::table('certifications')
                ->where('scheme', $cert['scheme'])
                ->where('certificate_no', $cert['certificate_no'])
                ->value('id');

            if (! DB::table('certification_scopes')->where('certification_id', $certId)->exists()) {
                DB::table('certification_scopes')->insert([
                    'certification_id' => $certId,
                    'min_claim_pct' => $cert['min_claim_pct'],
                    'labelled_claim_pct' => $cert['labelled_claim_pct'],
                    'max_conversion_factor' => 1,
                ]);
            }
        }
    }

    /** BR-34 — one series per document type for the current year. */
    private function numberSequences(): void
    {
        $year = now()->format('y');

        $types = [
            'inquiry' => ['INQ', 5],
            'quotation' => ['QTN', 5],
            'sales_order' => ['SO', 5],
            'job_card' => ['JC', 6],
            'purchase_requisition' => ['PR', 5],
            'purchase_order' => ['PO', 5],
            'grn' => ['GRN', 5],
            'material_issue' => ['MI', 5],
            'stock_transfer' => ['STR', 5],
            'stock_adjustment' => ['ADJ', 5],
            'physical_count' => ['PC', 5],
            'packing_list' => ['PL', 5],
            'delivery_challan' => ['DC', 5],
            'trip' => ['TRP', 5],
            'sales_invoice' => ['INV', 5],
            'credit_note' => ['CN', 5],
            'receipt' => ['RCT', 5],
            'payment' => ['PAY', 5],
            'supplier_bill' => ['SB', 5],
            'test_report' => ['LAB', 5],
            'qc_inspection' => ['QC', 5],
            'ncr' => ['NCR', 5],
            'sample_request' => ['SMP', 5],
            'production_plan' => ['PLAN', 5],
            'mrp_run' => ['MRP', 5],
            'fg_receipt' => ['FGR', 5],
        ];

        foreach ($types as $type => [$prefix, $padding]) {
            DB::table('number_sequences')->updateOrInsert(
                ['document_type' => $type, 'series_key' => $year],
                ['prefix' => $prefix, 'padding' => $padding],
            );
        }

        // Lots are numbered by date, not by year: L{YYMMDD}-{#####} (01-domain-model §5).
        DB::table('number_sequences')->updateOrInsert(
            ['document_type' => 'lot', 'series_key' => now()->format('ymd')],
            ['prefix' => 'L', 'padding' => 5],
        );
    }

    /**
     * The coefficients of the formulas. The formulas themselves are code with tests; these
     * change without a deploy (08-architecture §8).
     */
    private function settings(): void
    {
        $settings = [
            ['overhead_pct', 12, 'costing', 'Factory overhead on direct cost (BR-19)'],
            ['admin_pct', 5, 'costing', 'Administrative overhead on subtotal (BR-19)'],
            ['default_margin_pct', 20, 'costing', 'Default margin, applied on price (BR-20)'],
            ['margin_floor_pct', 12, 'costing', 'Below this, a quotation needs cost_sheet.override_margin'],
            ['labour_rate_per_hour', 80, 'costing', 'Standard labour rate, BDT/hour (BR-17)'],
            ['tariff_per_kwh', 12, 'costing', 'Electricity tariff, BDT/kWh (BR-18)'],
            ['base_currency', 'BDT', 'general', 'Reporting currency; costs are computed here (BR-22)'],
            ['cut_gap_hot_cut', 2.0, 'consumption', 'Default cut gap in mm (BR-4)'],
            ['cut_gap_ultrasonic', 2.0, 'consumption', 'Default cut gap in mm (BR-4)'],
            ['cut_gap_laser', 1.5, 'consumption', 'Default cut gap in mm (BR-4)'],
            ['cut_gap_die_cut', 3.0, 'consumption', 'Default cut gap in mm (BR-4)'],
            ['cut_gap_straight_cut', 1.0, 'consumption', 'Default cut gap in mm (BR-4)'],
            ['default_bundle_size', 500, 'consumption', 'Labels per bundle (BR-12)'],
            ['default_bundles_per_carton', 20, 'consumption', 'Bundles per carton (BR-12)'],
            ['under_tolerance_pct', 5, 'sales', 'Default under-delivery tolerance (BR-44)'],
            ['over_tolerance_pct', 5, 'sales', 'Default over-delivery tolerance (BR-44)'],
            ['qc_days', 1, 'planning', 'Days allowed for final QC in the promised date (BR-29)'],
            ['packing_days', 1, 'planning', 'Days allowed for packing in the promised date (BR-29)'],
            ['po_approval_band_manager', 100000, 'approval', 'PO value up to which purchase_manager may approve (06-rbac §5)'],
            ['adjustment_approval_band_manager', 25000, 'approval', 'Stock adjustment value store_manager may approve'],
            ['credit_note_approval_band_accounts', 50000, 'approval', 'Credit note value accounts may approve'],
            ['merchandiser_sees_own_only', false, 'scoping', 'When on, a merchandiser sees only their own records (06-rbac §4)'],
            ['expiry_alert_days', 30, 'inventory', 'Days before expiry at which ink and chemicals flag (BR-39)'],
        ];

        foreach ($settings as [$key, $value, $group, $description]) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'group_name' => $group,
                    'description' => $description,
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(string $table, string $key, array $rows): void
    {
        foreach ($rows as $row) {
            $match = [$key => $row[$key]];
            unset($row[$key]);

            DB::table($table)->updateOrInsert($match, $row);
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertScoped(string $table, array $keys, array $rows): void
    {
        foreach ($rows as $row) {
            $match = [];

            foreach ($keys as $key) {
                $match[$key] = $row[$key];
                unset($row[$key]);
            }

            DB::table($table)->updateOrInsert($match, $row);
        }
    }
}
