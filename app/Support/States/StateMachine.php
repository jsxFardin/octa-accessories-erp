<?php

declare(strict_types=1);

namespace App\Support\States;

use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Base for every document state machine (05-workflows).
 *
 * Nothing changes status by direct assignment. A transition validates its guard, applies its
 * side effects and writes the audit row inside one transaction, so a guard can never be
 * skipped and an effect can never be half-applied.
 *
 * Deliberately hand-written rather than a package (08-architecture §5): the guards here are
 * domain rules with business-rule IDs attached, and expressing them as configuration would
 * hide the very logic that has to be reviewable.
 *
 * @template TModel of Model
 */
abstract class StateMachine
{
    public function __construct(protected readonly AuditLogger $audit) {}

    /**
     * Allowed transitions: `from` status => list of reachable `to` statuses.
     *
     * @return array<string, list<string>>
     */
    abstract protected function transitions(): array;

    /**
     * The permission required for a transition, keyed by target status (06-rbac §1).
     *
     * @return array<string, string>
     */
    abstract protected function permissions(): array;

    protected function statusColumn(): string
    {
        return 'status';
    }

    /**
     * Guard hook. Throw a TransitionDenied to block; return normally to allow.
     *
     * @param  TModel  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void {}

    /**
     * Work that must happen *before* the status is written, inside the same transaction.
     *
     * Rare, and always for the same reason: a uniqueness rule that the new status would
     * violate until the old row moves out of the way — superseding the previous approved
     * artwork version being the case that matters (A2).
     *
     * @param  TModel  $document
     * @param  array<string, mixed>  $context
     */
    protected function prepare(Model $document, string $from, string $to, array $context): void {}

    /**
     * Side effects, applied inside the transition's transaction, after the status is written.
     *
     * @param  TModel  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void {}

    /**
     * @param  TModel  $document
     * @param  array<string, mixed>  $context
     *
     * @throws TransitionDenied
     */
    public function transition(Model $document, string $to, array $context = []): void
    {
        $column = $this->statusColumn();
        $from = (string) $document->getAttribute($column);

        // Idempotence: transitioning to the current status is a no-op, not an error. This is
        // what protects the shop floor from double-submits on flaky wifi (05-workflows §13).
        if ($from === $to) {
            return;
        }

        $this->assertAllowed($document, $from, $to);
        $this->assertPermitted($to);

        DB::transaction(function () use ($document, $column, $from, $to, $context): void {
            $this->guard($document, $from, $to, $context);
            $this->prepare($document, $from, $to, $context);

            $document->setAttribute($column, $to);
            $document->save();

            $this->effect($document, $from, $to, $context);

            $this->audit->recordTransition($document, $from, $to, $this->auditContext($context));
        });
    }

    /** @param TModel $document */
    public function can(Model $document, string $to): bool
    {
        $from = (string) $document->getAttribute($this->statusColumn());

        if ($from === $to) {
            return true;
        }

        if (! in_array($to, $this->transitions()[$from] ?? [], true)) {
            return false;
        }

        $permission = $this->permissions()[$to] ?? null;

        return $permission === null || (auth()->user()?->hasPermission($permission) ?? false);
    }

    /**
     * The transitions a user may currently take — what the UI renders as buttons.
     *
     * @param  TModel  $document
     * @return list<string>
     */
    public function available(Model $document): array
    {
        $from = (string) $document->getAttribute($this->statusColumn());

        return array_values(array_filter(
            $this->transitions()[$from] ?? [],
            fn (string $to): bool => $this->can($document, $to),
        ));
    }

    /** @param TModel $document */
    protected function assertAllowed(Model $document, string $from, string $to): void
    {
        if (! in_array($to, $this->transitions()[$from] ?? [], true)) {
            throw TransitionDenied::notAllowed(class_basename($document), $from, $to);
        }
    }

    protected function assertPermitted(string $to): void
    {
        $permission = $this->permissions()[$to] ?? null;

        if ($permission === null) {
            return;
        }

        $user = auth()->user();

        if ($user === null || ! $user->hasPermission($permission)) {
            throw TransitionDenied::notPermitted($permission);
        }
    }

    /**
     * Reasons and approver references belong in the audit row; bulky payloads do not.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function auditContext(array $context): array
    {
        return array_filter(
            $context,
            fn (mixed $value, string $key): bool => is_scalar($value) && str_contains($key, 'reason')
                || in_array($key, ['approved_by', 'customer_ref', 'override', 'waived'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
