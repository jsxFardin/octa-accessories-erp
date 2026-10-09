<?php

declare(strict_types=1);

namespace App\Support\Reference;

/**
 * The processes a machine group runs and an operation master names — every step the ten
 * production families use (spec §2), shared or not. One list, because the CHECK on
 * `machine_groups.process_type`, the CHECK on `operations.process_type` and the select on
 * both setup screens must agree.
 */
final class ProcessVocabulary
{
    /** @var list<string> */
    public const TYPES = [
        // Pre-press and design
        'design', 'digitizing',
        // Narrow textile, braiding, support textile
        'warping', 'weaving', 'knitting', 'braiding', 'twisting', 'heat_setting', 'dyeing', 'finishing',
        'coating', 'adhesive', 'drying',
        // Printing
        'flexo', 'screen', 'heat_transfer', 'offset', 'thermal', 'printing', 'curing', 'lamination',
        // Conversion
        'slitting', 'cutting', 'die_cutting', 'creasing', 'punching', 'folding', 'barcode_verify',
        'eyeleting', 'stringing', 'tipping',
        // Zipper
        'chain_forming', 'assembly', 'slider_fitting', 'stopping', 'puller_fitting',
        // Fastener and injection
        'moulding', 'injection', 'trimming', 'polishing', 'drilling', 'logo_marking',
        'stamping', 'die_casting', 'forming', 'deburring', 'plating', 'mixing', 'cooling',
        // Packaging
        'extrusion', 'sealing', 'gluing',
        // Decoration
        'embroidery',
        // Everywhere
        'inspection', 'packing',
    ];

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (string $type): array => ['value' => $type, 'label' => ucfirst(str_replace('_', ' ', $type))],
            self::TYPES,
        );
    }

    /** The CHECK body, so a migration and the document print the same list. */
    public static function sqlList(): string
    {
        return implode(',', array_map(fn (string $type): string => "'{$type}'", self::TYPES));
    }
}
