<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property int $customer_id
 * @property int|null $customer_contact_id
 * @property int|null $brand_id
 * @property \Illuminate\Support\Carbon $inquiry_date
 * @property \Illuminate\Support\Carbon|null $required_by
 * @property string|null $source
 * @property int|null $merchandiser_id
 * @property string $status
 * @property string|null $lost_reason
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Inquiry extends Model
{
    protected $table = 'inquiries';

    protected $fillable = [
        'number',
        'customer_id',
        'customer_contact_id',
        'brand_id',
        'inquiry_date',
        'required_by',
        'source',
        'merchandiser_id',
        'status',
        'lost_reason',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'customer_contact_id' => 'integer',
            'brand_id' => 'integer',
            'inquiry_date' => 'date',
            'required_by' => 'date',
            'merchandiser_id' => 'integer',
            'created_at' => 'datetime',
            'created_by' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Customer::class, 'customer_id');
    }

    /** @return HasMany<InquiryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InquiryLine::class, 'inquiry_id')->orderBy('line_no');
    }
}
