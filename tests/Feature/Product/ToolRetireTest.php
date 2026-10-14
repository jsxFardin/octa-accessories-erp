<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Product\Models\Tool;
use Illuminate\Support\Facades\DB;

/** GAP M-11 — PD-5 AC4: a tool leaves service with the reason on its audit row. */
it('retires a tool only with a reason, and records it', function (): void {
    $engineer = User::query()->where('email', 'engineer@octapussolution.com')->firstOrFail();
    $toolId = DB::table('tools')->insertGetId(['kind' => 'flexo_plate', 'code' => 'PLATE-RETIRE-1', 'life_impressions' => 1000, 'used_impressions' => 400, 'status' => 'available']);

    $this->actingAs($engineer)->post("/tools/{$toolId}/retire", [])->assertSessionHasErrors('reason');
    expect(DB::table('tools')->where('id', $toolId)->value('status'))->toBe('available');

    $this->actingAs($engineer)->post("/tools/{$toolId}/retire", ['reason' => 'Cracked on the press'])->assertSessionHas('success');

    $row = DB::table('audit_logs')->where('auditable_type', Tool::class)->where('auditable_id', $toolId)
        ->where('event', 'status_changed')->orderByDesc('id')->first();

    expect(DB::table('tools')->where('id', $toolId)->value('status'))->toBe('scrapped')
        ->and(json_decode((string) $row->new_values, true)['reason'])->toBe('Cracked on the press');
});
