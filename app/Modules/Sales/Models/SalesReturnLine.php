<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Finance\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_return_id
 * @property int $line_no
 * @property int $sales_invoice_line_id
 * @property int|null $product_id
 * @property int|null $lot_id
 * @property string $qty
 * @property string $rate_per_m
 */
class SalesReturnLine extends Model
{
    protected $table = 'sales_return_lines';

    public $timestamps = false;

    protected $fillable = [
        'sales_return_id',
        'line_no',
        'sales_invoice_line_id',
        'product_id',
        'lot_id',
        'qty',
        'rate_per_m',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_return_id' => 'integer',
            'line_no' => 'integer',
            'sales_invoice_line_id' => 'integer',
            'product_id' => 'integer',
            'lot_id' => 'integer',
            'qty' => 'decimal:6',
            'rate_per_m' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SalesInvoiceLine, $this> */
    public function salesInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class);
    }

    /**
     * BR-1 — everything in this business is priced per 1000 pieces, so the value of a
     * returned line is the same arithmetic the invoice line used to bill it.
     */
    public function value(): float
    {
        return round((float) $this->qty / 1000 * (float) $this->rate_per_m, 4);
    }
}
