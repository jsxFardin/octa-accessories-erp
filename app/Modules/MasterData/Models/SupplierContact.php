<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $supplier_id
 * @property string $name
 * @property string|null $designation
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 */
class SupplierContact extends Model
{
    protected $table = 'supplier_contacts';

    public $timestamps = false;

    protected $fillable = [
        'supplier_id',
        'name',
        'designation',
        'email',
        'phone',
        'is_primary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'is_primary' => 'boolean',
        ];
    }
}
