<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $test_report_id
 * @property int $lab_test_id
 * @property string $result_value
 * @property string|null $pass_value
 * @property string $result
 * @property string|null $remarks
 */
class TestReportLine extends Model
{
    protected $table = 'test_report_lines';

    public $timestamps = false;

    protected $fillable = [
        'test_report_id',
        'lab_test_id',
        'result_value',
        'pass_value',
        'result',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'test_report_id' => 'integer',
            'lab_test_id' => 'integer',
        ];
    }
}
