<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Calculators\SpecInput;
use App\Support\Reference\Vocabulary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable, versioned technical truth about a product (P2, P3).
 *
 * A spec is frozen once a quotation, sales order or job card references it; a change creates
 * a new version. That is what lets a job card released in March keep producing to March's
 * numbers after engineering revises the spec in April.
 *
 * @property int $id
 * @property int $product_id
 * @property int $version_no
 * @property string $status
 * @property string $label_width_mm
 * @property string $label_height_mm
 * @property string|null $web_width_mm
 * @property string $selvedge_mm
 * @property string $lane_gap_mm
 * @property string $cut_gap_mm
 * @property int|null $ends
 * @property string|null $base_material
 * @property string|null $fabric_gsm
 * @property string $warp_ratio
 * @property int $colours
 * @property array<array-key, mixed> $colour_list
 * @property string|null $cut_type
 * @property string|null $fold_type
 * @property string|null $finish
 * @property string $coverage_pct
 * @property int $bundle_size
 * @property int $bundles_per_carton
 * @property array<array-key, mixed> $care_symbols
 * @property string|null $fibre_composition
 * @property string|null $country_of_origin
 * @property array<array-key, mixed> $claims
 * @property array<array-key, mixed> $attributes
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property int|null $current_key
 */
class ProductSpec extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const CURRENT = 'current';

    public const SUPERSEDED = 'superseded';

    protected $table = 'product_specs';

    public const UPDATED_AT = null;

    // `current_key` is a STORED generated column enforcing P2; MySQL writes it, we never do.

    protected $fillable = [
        'product_id',
        'version_no',
        'status',
        'label_width_mm',
        'label_height_mm',
        'web_width_mm',
        'selvedge_mm',
        'lane_gap_mm',
        'cut_gap_mm',
        'ends',
        'base_material',
        'fabric_gsm',
        'warp_ratio',
        'colours',
        'colour_list',
        'cut_type',
        'fold_type',
        'finish',
        'coverage_pct',
        'bundle_size',
        'bundles_per_carton',
        'care_symbols',
        'fibre_composition',
        'country_of_origin',
        'claims',
        'attributes',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'version_no' => 'integer',
            'label_width_mm' => 'decimal:2',
            'label_height_mm' => 'decimal:2',
            'web_width_mm' => 'decimal:2',
            'selvedge_mm' => 'decimal:2',
            'lane_gap_mm' => 'decimal:2',
            'cut_gap_mm' => 'decimal:2',
            'ends' => 'integer',
            'fabric_gsm' => 'decimal:3',
            'warp_ratio' => 'decimal:4',
            'colours' => 'integer',
            'colour_list' => 'array',
            'coverage_pct' => 'decimal:4',
            'bundle_size' => 'integer',
            'bundles_per_carton' => 'integer',
            'care_symbols' => 'array',
            'claims' => 'array',
            'attributes' => 'array',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lift the spec into the calculators' value object. `product_type` lives on the product,
     * so the relation has to be loaded — the spec alone cannot say whether it is woven.
     */
    public function toCalculatorInput(?string $productType = null): SpecInput
    {
        $type = $productType ?? $this->product->product_type;

        return SpecInput::fromArray([
            'product_type' => $type,
            'cut_type' => $this->cut_type ?? 'hot_cut',
            'label_width_mm' => $this->label_width_mm,
            'label_height_mm' => $this->label_height_mm,
            'web_width_mm' => $this->web_width_mm,
            'selvedge_mm' => $this->selvedge_mm,
            'lane_gap_mm' => $this->lane_gap_mm,
            'cut_gap_mm' => $this->cut_gap_mm,
            'ends' => $this->ends,
            'fabric_gsm' => $this->fabric_gsm,
            'warp_ratio' => $this->warp_ratio,
            'colours' => $this->colours,
            'coverage_pct' => $this->coverage_pct,
            'bundle_size' => $this->bundle_size,
            'bundles_per_carton' => $this->bundles_per_carton,
            'sheet_length_mm' => $this->attributes['sheet_length_mm'] ?? 0,
            'sheet_width_mm' => $this->attributes['sheet_width_mm'] ?? 0,
        ], Vocabulary::productType($type), Vocabulary::cutType($this->cut_type));
    }

    /**
     * BR-4, BR-5, BR-6 — the derived geometry a designer needs to see live while typing,
     * before any order quantity exists.
     *
     * @return array{pitch_mm: float, labels_per_metre: float, suggested_ends: int, labels_per_web_metre: float}
     */
    public function derivedGeometry(?string $productType = null): array
    {
        $calc = new ConsumptionCalculator;
        $input = $this->toCalculatorInput($productType);

        $labelsPerMetre = $calc->labelsPerMetre($input);
        $suggested = $calc->suggestedEnds($input);
        $ends = $this->ends ?? $suggested;

        return [
            'pitch_mm' => round($calc->pitchMm($input), 2),
            'labels_per_metre' => round($labelsPerMetre, 4),
            'suggested_ends' => $suggested,
            'labels_per_web_metre' => round($calc->labelsPerWebMetre($labelsPerMetre, max(1, $ends)), 4),
        ];
    }

    /**
     * BR-9 — colour weighting from the ordered colour list
     * `[{index, name, pantone, yarn_item_id, weight_pct}]`.
     *
     * @return array<int, float>
     */
    public function colourWeights(): array
    {
        $weights = [];

        foreach ($this->colour_list ?? [] as $colour) {
            if (isset($colour['index'], $colour['weight_pct'])) {
                $weights[(int) $colour['index']] = (float) $colour['weight_pct'];
            }
        }

        return $weights;
    }

    public function isCurrent(): bool
    {
        return $this->status === self::CURRENT;
    }
}
