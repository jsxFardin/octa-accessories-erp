<?php

declare(strict_types=1);

namespace App\Support\Reference;

/**
 * The short, fixed lists on the item master that are CHECK constraints rather than tables:
 * what kind of thing an item is, whether it is made or bought, which garments it goes on.
 *
 * One copy, read by the form request, the import definition and the pages, so the dropdown
 * and the constraint cannot disagree (docs/02a-schema.sql `items_*_chk`).
 */
class ItemVocabulary
{
    /** @var array<string, string> */
    public const ITEM_TYPES = [
        'finished_good' => 'Finished good',
        'semi_finished' => 'Semi-finished',
        'component' => 'Component',
        'raw_material' => 'Raw material',
        'consumable' => 'Consumable',
        'packaging' => 'Packaging',
        'tool' => 'Tool',
        'service' => 'Service',
    ];

    /** The types that come out of production rather than off a purchase order. */
    public const MADE_TYPES = ['finished_good', 'semi_finished', 'component'];

    /** @var array<string, string> */
    public const MAKE_OR_BUY = ['make' => 'Make', 'buy' => 'Buy'];

    /** @var array<string, string> */
    public const GARMENT_TYPES = ['knit' => 'Knit', 'woven' => 'Woven', 'both' => 'Both'];

    /** @var array<string, string> */
    public const SPEC_SCOPES = ['standard' => 'Standard', 'buyer' => 'Buyer-specific'];

    /** @var array<string, string> */
    public const MATERIAL_BASES = ['textile' => 'Textile', 'plastic' => 'Plastic', 'metal' => 'Metal', 'paper' => 'Paper', 'film' => 'Film'];

    /** @var array<string, string> */
    public const VARIANT_AXES = ['color' => 'Colour', 'size' => 'Size', 'length' => 'Length'];

    /** FIFO is not offered: `InventoryValuator` values by weighted average or standard rate only. */
    public const VALUATION_METHODS = ['weighted_average' => 'Weighted average', 'standard' => 'Standard cost'];

    /** @var array<string, string> */
    public const CHARGE_BASES = ['per_piece' => 'Per piece', 'per_hour' => 'Per hour', 'per_lot' => 'Per lot', 'per_kg' => 'Per kg', 'per_metre' => 'Per metre'];

    /**
     * As a `SelectInput` expects them.
     *
     * @param  array<string, string>  $list
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $list): array
    {
        $options = [];

        foreach ($list as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /** @return array<string, list<array{value: string, label: string}>> every list, keyed for a page's props */
    public static function all(): array
    {
        return [
            'itemTypes' => self::options(self::ITEM_TYPES),
            'makeOrBuy' => self::options(self::MAKE_OR_BUY),
            'garmentTypes' => self::options(self::GARMENT_TYPES),
            'specScopes' => self::options(self::SPEC_SCOPES),
            'materialBases' => self::options(self::MATERIAL_BASES),
            'variantAxes' => self::options(self::VARIANT_AXES),
            'valuationMethods' => self::options(self::VALUATION_METHODS),
            'chargeBases' => self::options(self::CHARGE_BASES),
        ];
    }
}
