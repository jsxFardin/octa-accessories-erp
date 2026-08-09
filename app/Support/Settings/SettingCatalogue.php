<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * How each business setting is presented: a human label, the unit it is measured in, and the
 * rule it feeds.
 *
 * The keys are good identifiers and terrible labels — `po_approval_band_manager` tells a
 * purchase manager nothing about what number belongs in the box, and `cut_gap_hot_cut` reads
 * as a typo until you know it is millimetres. This is the single place that decides, so the
 * screen and any future export agree.
 *
 * Presentation lives here rather than in the `settings` table because it is not data: a label
 * should change with a deploy and a review, not with an UPDATE.
 */
class SettingCatalogue
{
    /**
     * Group order is deliberate — costing first because it is edited most.
     *
     * @var array<string, string>
     */
    public const GROUPS = [
        'costing' => 'Costing',
        'consumption' => 'Consumption',
        'sales' => 'Sales tolerances',
        'planning' => 'Planning',
        'approval' => 'Approval bands',
        'inventory' => 'Inventory',
        'scoping' => 'Visibility',
        'general' => 'General',
        // Edited on its own screen; listed here so its keys are grouped rather than falling
        // into General and reappearing on the raw settings list.
        'organisation' => 'Organisation',
    ];

    /**
     * key => [group, label, unit, hint]
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: string}>
     */
    public const ENTRIES = [
        'overhead_pct' => ['costing', 'Factory overhead', '%', 'Applied to direct cost — material, machine, labour and energy (BR-19).'],
        'admin_pct' => ['costing', 'Administrative overhead', '%', 'Applied to the subtotal after factory overhead (BR-19).'],
        'default_margin_pct' => ['costing', 'Default margin', '%', 'Pre-filled on new quotation lines. Applied on price, never on cost (BR-20).'],
        'margin_floor_pct' => ['costing', 'Margin floor', '%', 'Below this a quotation needs the cost_sheet.override_margin permission.'],
        'labour_rate_per_hour' => ['costing', 'Labour rate', 'BDT / hour', 'Standard rate used when an operation has no rate of its own (BR-17).'],
        'tariff_per_kwh' => ['costing', 'Electricity tariff', 'BDT / kWh', 'Machine energy cost is drawn against this (BR-18).'],

        'cut_gap_hot_cut' => ['consumption', 'Cut gap — hot cut', 'mm', 'Web length lost between labels at this cut type (BR-4).'],
        'cut_gap_ultrasonic' => ['consumption', 'Cut gap — ultrasonic', 'mm', 'Web length lost between labels at this cut type (BR-4).'],
        'cut_gap_laser' => ['consumption', 'Cut gap — laser', 'mm', 'Web length lost between labels at this cut type (BR-4).'],
        'cut_gap_die_cut' => ['consumption', 'Cut gap — die cut', 'mm', 'Web length lost between labels at this cut type (BR-4).'],
        'cut_gap_straight_cut' => ['consumption', 'Cut gap — straight cut', 'mm', 'Web length lost between labels at this cut type (BR-4).'],
        'default_bundle_size' => ['consumption', 'Labels per bundle', 'pcs', 'Used to derive bundle and carton counts when a product does not state its own (BR-12).'],
        'default_bundles_per_carton' => ['consumption', 'Bundles per carton', 'bundles', 'Used to derive carton counts when a product does not state its own (BR-12).'],

        'under_tolerance_pct' => ['sales', 'Under-delivery tolerance', '%', 'Default for new customers. A short delivery inside this band still closes the line (BR-44).'],
        'over_tolerance_pct' => ['sales', 'Over-delivery tolerance', '%', 'Default for new customers. Production above this band cannot be dispatched (BR-44).'],

        'qc_days' => ['planning', 'QC allowance', 'days', 'Reserved for final inspection when a promised date is calculated (BR-29).'],
        'packing_days' => ['planning', 'Packing allowance', 'days', 'Reserved for packing when a promised date is calculated (BR-29).'],

        'po_approval_band_manager' => ['approval', 'Purchase order — manager band', 'BDT', 'A purchase manager may approve up to this value; above it the order needs the Managing Director (06-rbac §5).'],
        'adjustment_approval_band_manager' => ['approval', 'Stock adjustment — manager band', 'BDT', 'A store manager may approve write-offs up to this value.'],
        'credit_note_approval_band_accounts' => ['approval', 'Credit note — accounts band', 'BDT', 'Accounts may approve credit notes up to this value.'],

        'expiry_alert_days' => ['inventory', 'Expiry warning', 'days', 'Ink and chemical lots flag this many days before they expire (BR-39).'],

        'merchandiser_sees_own_only' => ['scoping', 'Merchandisers see only their own records', null, 'When on, a merchandiser sees only the customers and orders assigned to them (06-rbac §4).'],

        'base_currency' => ['general', 'Base currency', null, 'Costs are computed and reported in this currency (BR-22). Changing it does not convert existing records.'],

        // The organisation profile — see /admin/organisation.
        'org_name' => ['organisation', 'Trading name', null, 'Shown in the sidebar.'],
        'org_legal_name' => ['organisation', 'Legal name', null, 'Printed on invoices and export documents.'],
        'org_short_name' => ['organisation', 'Product name', null, 'Shown in the browser tab.'],
        'org_logo_path' => ['organisation', 'Wordmark', null, 'Uploaded on the organisation screen.'],
        'org_icon_path' => ['organisation', 'Square mark', null, 'Uploaded on the organisation screen; used as the favicon.'],
        'org_address' => ['organisation', 'Address', null, 'Printed on documents.'],
        'org_phone' => ['organisation', 'Phone', null, 'Printed on documents.'],
        'org_email' => ['organisation', 'Email', null, 'Printed on documents.'],
        'org_website' => ['organisation', 'Website', null, 'Printed on documents.'],
        'org_tax_id' => ['organisation', 'BIN / VAT registration', null, 'Printed on invoices.'],
        'timezone' => ['organisation', 'Timezone', null, 'Timestamps are stored UTC and displayed in this zone (NFR-49).'],
        'date_format' => ['organisation', 'Date format', null, 'How dates render across the application.'],
        'time_format' => ['organisation', 'Time format', null, '24-hour or 12-hour clock.'],
        'week_start' => ['organisation', 'Week starts on', null, 'First day of the week in calendars and planning boards.'],
        'number_locale' => ['organisation', 'Number format', null, 'Thousands and decimal separators.'],
        'default_locale' => ['organisation', 'Default language', null, 'Applied to users who have not chosen one.'],
    ];

    /** @return array{group: string, label: string, unit: ?string, hint: string} */
    public function describe(string $key): array
    {
        $entry = self::ENTRIES[$key] ?? null;

        if ($entry === null) {
            // An unknown key still renders — a setting added by a migration and not yet
            // catalogued should be editable, not invisible.
            return [
                'group' => 'general',
                'label' => ucfirst(str_replace('_', ' ', $key)),
                'unit' => null,
                'hint' => '',
            ];
        }

        return ['group' => $entry[0], 'label' => $entry[1], 'unit' => $entry[2], 'hint' => $entry[3]];
    }

    public function groupLabel(string $group): string
    {
        return self::GROUPS[$group] ?? ucfirst(str_replace('_', ' ', $group));
    }

    /** @return list<string> */
    public function groupOrder(): array
    {
        return array_keys(self::GROUPS);
    }
}
