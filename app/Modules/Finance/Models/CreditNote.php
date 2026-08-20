<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Support\Audit\Auditable;
use App\Support\Notifications\Notifier;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $customer_id
 * @property int|null $sales_invoice_id
 * @property \Illuminate\Support\Carbon $note_date
 * @property string $reason
 * @property int|null $ncr_id
 * @property int $currency_id
 * @property string $amount
 * @property string $status
 * @property int|null $approved_by
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 */
class CreditNote extends Model
{
    use Auditable;

    protected $table = 'credit_notes';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'customer_id',
        'sales_invoice_id',
        'note_date',
        'reason',
        'ncr_id',
        'currency_id',
        'amount',
        'status',
        'approved_by',
        'remarks',
    ];

    protected static function booted(): void
    {
        // Draft is the awaiting-approval state. Both the accounts form and a return-after-invoice
        // create a draft; this is the one trigger so those paths cannot diverge.
        static::created(function (CreditNote $note): void {
            if ($note->status !== 'draft') {
                return;
            }

            try {
                app(Notifier::class)->notifyCreditNoteApproval($note);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'sales_invoice_id' => 'integer',
            'note_date' => 'date:Y-m-d',
            'ncr_id' => 'integer',
            'currency_id' => 'integer',
            'amount' => 'decimal:4',
            'approved_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Modules\MasterData\Models\Customer, $this> */
    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Customer::class, 'customer_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<SalesInvoice, $this> */
    public function salesInvoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
