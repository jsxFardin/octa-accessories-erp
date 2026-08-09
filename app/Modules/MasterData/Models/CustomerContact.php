<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string|null $designation
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 * @property int|null $portal_user_id
 */
class CustomerContact extends Model
{
    protected $table = 'customer_contacts';

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'name',
        'designation',
        'email',
        'phone',
        'is_primary',
        'portal_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'is_primary' => 'boolean',
            'portal_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
