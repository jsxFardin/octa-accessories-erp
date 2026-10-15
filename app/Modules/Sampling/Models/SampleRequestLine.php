<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Models;

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sample_request_id
 * @property int $line_no
 * @property int|null $product_id
 * @property int|null $product_spec_id
 * @property int|null $artwork_version_id
 * @property string $description
 * @property string $qty
 * @property string|null $colourway
 * @property string $status
 * @property-read SampleRequest $request
 * @property-read Product|null $product
 * @property-read ProductSpec|null $spec
 * @property-read ArtworkVersion|null $artworkVersion
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SampleApproval> $approvals
 * @property-read \Illuminate\Database\Eloquent\Collection<int, JobCard> $jobCards
 */
class SampleRequestLine extends Model
{
    public const PENDING = 'pending';

    public const PRODUCED = 'produced';

    public const DISPATCHED = 'dispatched';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'sample_request_lines';

    public $timestamps = false;

    protected $fillable = [
        'sample_request_id',
        'line_no',
        'product_id',
        'product_spec_id',
        'artwork_version_id',
        'description',
        'qty',
        'colourway',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sample_request_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'product_spec_id' => 'integer',
            'artwork_version_id' => 'integer',
            'qty' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<SampleRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SampleRequest::class, 'sample_request_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductSpec, $this> */
    public function spec(): BelongsTo
    {
        return $this->belongsTo(ProductSpec::class, 'product_spec_id');
    }

    /** @return BelongsTo<ArtworkVersion, $this> */
    public function artworkVersion(): BelongsTo
    {
        return $this->belongsTo(ArtworkVersion::class);
    }

    /** @return HasMany<SampleApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(SampleApproval::class)->orderBy('id');
    }

    /** AS-5 AC4 — the sample run(s) raised from this line. @return HasMany<JobCard, $this> */
    public function jobCards(): HasMany
    {
        return $this->hasMany(JobCard::class, 'sample_request_line_id')->orderBy('id');
    }
}
