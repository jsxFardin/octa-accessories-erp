<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sales_invoice_id
 * @property int $line_no
 * @property int|null $sales_order_line_id
 * @property int|null $product_id
 * @property string $description
 * @property string $qty
 * @property string $rate_per_m
 * @property int|null $tax_id
 * @property string $tax_amount
 * @property string $amount
 */
class SalesInvoiceLine extends Model
{
    protected $table = 'sales_invoice_lines';

    public $timestamps = false;

    protected $fillable = [
        'sales_invoice_id',
        'line_no',
        'sales_order_line_id',
        'product_id',
        'description',
        'qty',
        'rate_per_m',
        'tax_id',
        'tax_amount',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_invoice_id' => 'integer',
            'line_no' => 'integer',
            'sales_order_line_id' => 'integer',
            'product_id' => 'integer',
            'qty' => 'decimal:6',
            'rate_per_m' => 'decimal:4',
            'tax_id' => 'integer',
            'tax_amount' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }
}
