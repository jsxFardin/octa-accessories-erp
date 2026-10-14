<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;

/**
 * Opt-in auditing for a model. Applied to documents and master data, not to ledger rows —
 * `stock_ledger` is already append-only and auditing an insert-only table twice is noise.
 *
 * @phpstan-require-extends Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            app(AuditLogger::class)->record($model, 'created', null, $model->auditAttributes($model->getAttributes()));
        });

        static::updated(function (self $model): void {
            // Secrets and noise are dropped first, so a change to nothing but `last_login_at`
            // or a remember token does not leave an "updated" row with an empty diff.
            $changed = $model->auditAttributes($model->getChanges());

            if ($changed === []) {
                return;
            }

            $old = Arr::only($model->getOriginal(), array_keys($changed));

            app(AuditLogger::class)->record(
                $model,
                'updated',
                $model->auditAttributes($old),
                $changed,
            );
        });

        static::deleted(function (self $model): void {
            app(AuditLogger::class)->record($model, 'deleted', $model->auditAttributes($model->getOriginal()), null);
        });
    }

    /** @return MorphMany<AuditLog, $this> */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * Secrets and noise never reach the audit trail.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditAttributes(array $attributes): array
    {
        return Arr::except($attributes, [
            'password',
            'remember_token',
            'two_factor_secret',
            'last_login_at',
            'created_at',
            'updated_at',
        ]);
    }
}
