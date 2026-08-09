<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sample_request_id
 * @property \Illuminate\Support\Carbon $dispatched_on
 * @property string|null $courier_name
 * @property string|null $tracking_no
 * @property string|null $recipient
 * @property \Illuminate\Support\Carbon|null $delivered_on
 * @property string|null $remarks
 */
class SampleDispatch extends Model
{
    protected $table = 'sample_dispatches';

    public $timestamps = false;

    protected $fillable = [
        'sample_request_id',
        'dispatched_on',
        'courier_name',
        'tracking_no',
        'recipient',
        'delivered_on',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sample_request_id' => 'integer',
            'dispatched_on' => 'date',
            'delivered_on' => 'date',
        ];
    }
}
