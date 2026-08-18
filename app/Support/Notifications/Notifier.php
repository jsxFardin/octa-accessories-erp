<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Quality\Models\Ncr;
use App\Notifications\DocumentNotification;
use App\Support\Settings\Settings;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * P2-4 — the one place that decides who is told, and whether they have already been told.
 *
 * Recipients come from role_permissions (not a full-user hasPermission scan, which would
 * include super_admin on every event). Dispatch is after-commit so a notification failure
 * cannot roll back an NCR close, an invoice transition or a credit-note draft.
 */
class Notifier
{
    public function __construct(private readonly Settings $settings) {}

    public function notifyNcrAssigned(Ncr $ncr): void
    {
        $ownerId = $ncr->owner_id;

        if ($ownerId === null) {
            return;
        }

        $this->afterCommit(function () use ($ncr, $ownerId): void {
            $owner = User::query()->whereKey($ownerId)->where('is_active', true)->first();

            if ($owner === null) {
                return;
            }

            if (! $owner->hasPermission('ncr.view') && ! $owner->hasPermission('ncr.update')) {
                return;
            }

            $this->deliver($owner, [
                'document_type' => 'ncr',
                'document_id' => (int) $ncr->id,
                'document_number' => $ncr->number,
                'action' => 'assigned',
                'href' => '/ncrs/'.$ncr->id,
                'title' => 'NCR '.$ncr->number.' assigned to you',
                'dedupe_key' => 'ncr:assigned:'.$ncr->id.':'.$ownerId,
            ]);
        });
    }

    public function notifyNcrOverdue(Ncr $ncr, string $dueDate): void
    {
        $ownerId = $ncr->owner_id;

        if ($ownerId === null) {
            return;
        }

        $this->afterCommit(function () use ($ncr, $ownerId, $dueDate): void {
            $owner = User::query()->whereKey($ownerId)->where('is_active', true)->first();

            if ($owner === null) {
                return;
            }

            if (! $owner->hasPermission('ncr.view') && ! $owner->hasPermission('ncr.update')) {
                return;
            }

            $this->deliver($owner, [
                'document_type' => 'ncr',
                'document_id' => (int) $ncr->id,
                'document_number' => $ncr->number,
                'action' => 'overdue',
                'href' => '/ncrs/'.$ncr->id,
                'title' => 'NCR '.$ncr->number.' CAPA is overdue',
                'dedupe_key' => 'ncr:overdue:'.$ncr->id.':'.$dueDate,
            ]);
        });
    }

    public function notifyNcrVerificationRequired(Ncr $ncr): void
    {
        $this->afterCommit(function () use ($ncr): void {
            foreach ($this->usersWith(['ncr.close']) as $user) {
                $this->deliver($user, [
                    'document_type' => 'ncr',
                    'document_id' => (int) $ncr->id,
                    'document_number' => $ncr->number,
                    'action' => 'action_taken',
                    'href' => '/ncrs/'.$ncr->id,
                    'title' => 'NCR '.$ncr->number.' needs verification',
                    'dedupe_key' => 'ncr:action_taken:'.$ncr->id,
                ]);
            }
        });
    }

    public function notifyNcrClosed(Ncr $ncr): void
    {
        $this->afterCommit(function () use ($ncr): void {
            $ids = array_values(array_unique(array_filter([(int) $ncr->owner_id, (int) $ncr->raised_by])));

            foreach (User::query()->whereIn('id', $ids)->where('is_active', true)->get() as $user) {
                if (! $user->hasPermission('ncr.view') && ! $user->hasPermission('ncr.view_any')) {
                    continue;
                }

                $this->deliver($user, [
                    'document_type' => 'ncr',
                    'document_id' => (int) $ncr->id,
                    'document_number' => $ncr->number,
                    'action' => 'closed',
                    'href' => '/ncrs/'.$ncr->id,
                    'title' => 'NCR '.$ncr->number.' is closed',
                    'dedupe_key' => 'ncr:closed:'.$ncr->id,
                ]);
            }
        });
    }

    public function notifyInvoiceOverdue(SalesInvoice $invoice): void
    {
        $this->afterCommit(function () use ($invoice): void {
            foreach ($this->usersWith(['sales_invoice.view', 'sales_invoice.view_any']) as $user) {
                $this->deliver($user, [
                    'document_type' => 'sales_invoice',
                    'document_id' => (int) $invoice->id,
                    'document_number' => $invoice->number,
                    'action' => 'overdue',
                    'href' => '/invoices/'.$invoice->id,
                    'title' => 'Invoice '.$invoice->number.' is overdue',
                    'dedupe_key' => 'invoice:overdue:'.$invoice->id,
                ]);
            }
        });
    }

    public function notifyCreditNoteApproval(CreditNote $note): void
    {
        $this->afterCommit(function () use ($note): void {
            $band = $this->settings->decimal('credit_note_approval_band_accounts', 50000);
            $aboveBand = (float) $note->amount > $band;
            $users = $this->usersWith(['credit_note.approve'], $aboveBand ? 'md' : null);

            foreach ($users as $user) {
                $this->deliver($user, [
                    'document_type' => 'credit_note',
                    'document_id' => (int) $note->id,
                    'document_number' => $note->number,
                    'action' => 'draft',
                    'href' => '/credit-notes/'.$note->id,
                    'title' => 'Credit note awaiting approval',
                    'dedupe_key' => 'credit_note:draft:'.$note->id,
                ]);
            }
        });
    }

    /**
     * @param  list<string>  $permissions
     * @return Collection<int, User>
     */
    private function usersWith(array $permissions, ?string $onlyRole = null): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas(
                'roles.permissions',
                fn ($query) => $query->whereIn('permissions.name', $permissions),
            )
            ->when(
                $onlyRole !== null,
                fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('name', $onlyRole)),
            )
            ->whereDoesntHave('roles', fn ($roles) => $roles->where('name', 'portal_customer'))
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @param  array{
     *     document_type: string,
     *     document_id: int,
     *     document_number: string|null,
     *     action: string,
     *     href: string,
     *     title: string,
     *     dedupe_key: string
     * }  $payload
     */
    private function deliver(User $user, array $payload): void
    {
        $already = DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('type', DocumentNotification::class)
            ->where('data->dedupe_key', $payload['dedupe_key'])
            ->exists();

        if ($already) {
            return;
        }

        Notification::send($user, new DocumentNotification($payload));
    }

    /**
     * Run after the surrounding business transaction commits. Feature tests wrap the whole
     * case in a transaction that never commits, so they run immediately — still inside a
     * try/catch so a delivery failure cannot undo the savepoint.
     */
    private function afterCommit(Closure $callback): void
    {
        $run = function () use ($callback): void {
            try {
                $callback();
            } catch (Throwable $e) {
                report($e);
            }
        };

        if (app()->runningUnitTests() || DB::transactionLevel() === 0) {
            $run();

            return;
        }

        DB::afterCommit($run);
    }
}
