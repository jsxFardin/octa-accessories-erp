<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes `audit_logs`. Application-level, never a trigger: a trigger cannot see the
 * authenticated user (02-database-schema §3.1), and "who" is the whole point.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(Model $model, string $event, ?array $old = null, ?array $new = null): void
    {
        $request = request();

        DB::table('audit_logs')->insert([
            'user_id' => auth()->id(),
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    /**
     * Status transitions are the audit rows anyone actually reads (05-workflows §13).
     *
     * @param  array<string, mixed>  $context
     */
    public function recordTransition(Model $model, string $from, string $to, array $context = []): void
    {
        $this->record(
            $model,
            'status_changed',
            ['status' => $from],
            ['status' => $to] + $context,
        );
    }
}
