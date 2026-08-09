<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use App\Modules\MasterData\Models\Employee;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $product_id
 * @property string $code
 * @property string $title
 * @property int|null $designer_id
 * @property \Illuminate\Support\Carbon $created_at
 */
class Artwork extends Model
{
    use Auditable;

    protected $table = 'artworks';

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'code',
        'title',
        'designer_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'designer_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function designer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'designer_id');
    }

    /**
     * A1 — versions are numbered contiguously from 1 and never renumbered.
     *
     * @return HasMany<ArtworkVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ArtworkVersion::class)->orderByDesc('version_no');
    }

    /** @return HasOne<ArtworkVersion, $this> */
    public function approvedVersion(): HasOne
    {
        return $this->hasOne(ArtworkVersion::class)->where('status', ArtworkVersion::APPROVED);
    }

    public function nextVersionNo(): int
    {
        return (int) $this->versions()->max('version_no') + 1;
    }
}
