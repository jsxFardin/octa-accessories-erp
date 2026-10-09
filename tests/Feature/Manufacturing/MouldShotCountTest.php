<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\OperationBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spec §2, family 07 — a mould's life is shots. Finishing an operation that ran on a mould
 * adds the shots it took: pieces out, good and waste, divided by the cavities.
 */
it('adds the shots an operation took to the mould it ran on', function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $toolId = DB::table('tools')->insertGetId([
        'kind' => 'mould',
        'code' => 'MOULD-SHOT-1',
        'cavity_count' => 4,
        'tonnage' => 120,
        'life_impressions' => 100000,
        'used_impressions' => 10,
        'status' => 'available',
    ]);

    $card = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $operation = $card->operations()->orderBy('sequence_no')->firstOrFail();

    DB::table('job_card_operations')->where('id', $operation->id)->update([
        'tool_id' => $toolId,
        'input_qty' => 1010,
        'good_qty' => 1000,
        'waste_qty' => 10,
        'status' => 'in_progress',
        'started_at' => now()->subHour(),
    ]);

    app(OperationBookingService::class)->finish($operation->fresh(), CarbonImmutable::now());

    // 1,010 pieces over 4 cavities: 253 shots, on top of the 10 already run.
    expect((int) DB::table('tools')->where('id', $toolId)->value('used_impressions'))->toBe(263);

    // A plate is not shot-counted.
    $plateId = DB::table('tools')->insertGetId(['kind' => 'flexo_plate', 'code' => 'PLATE-SHOT-1', 'status' => 'available']);
    $second = $card->operations()->orderBy('sequence_no')->skip(1)->firstOrFail();
    DB::table('job_card_operations')->where('id', $second->id)->update(['tool_id' => $plateId, 'input_qty' => 500, 'good_qty' => 500, 'status' => 'in_progress']);

    app(OperationBookingService::class)->finish($second->fresh(), CarbonImmutable::now());

    expect((int) DB::table('tools')->where('id', $plateId)->value('used_impressions'))->toBe(0);
});
