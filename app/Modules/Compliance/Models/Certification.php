<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $scheme
 * @property string $certificate_no
 * @property string|null $issuing_body
 * @property \Illuminate\Support\Carbon $issued_on
 * @property \Illuminate\Support\Carbon $expires_on
 * @property string|null $scope_description
 * @property string|null $document_path
 * @property int $reminder_days
 * @property string $status
 */
class Certification extends Model
{
    protected $table = 'certifications';

    public $timestamps = false;

    protected $fillable = [
        'scheme',
        'certificate_no',
        'issuing_body',
        'issued_on',
        'expires_on',
        'scope_description',
        'document_path',
        'reminder_days',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'reminder_days' => 'integer',
        ];
    }
}
