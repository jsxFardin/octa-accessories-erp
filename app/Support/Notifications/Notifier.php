<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\States\CreditNoteStateMachine;
use App\Modules\Quality\Models\Ncr;
use App\Modules\Sales\Models\SalesOrder;
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
                'body' => 'The corrective action was due on '.$dueDate.' and has not been recorded. '
                    .'An overdue CAPA is what a brand audit looks for first. Record what was done, '
                    .'or move the date and say why.',
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
                    'body' => 'The corrective action has been carried out and is waiting for someone '
                        .'other than the person who did it to confirm it worked. The NCR stays open, '
                        .'and the batch with it, until that is recorded.',
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
                    'body' => 'The action was verified and the NCR is closed, so nothing further is '
                        .'needed from you. Open it if you want the record of what was done.',
                    'dedupe_key' => 'ncr:closed:'.$ncr->id,
                ]);
            }
        });
    }

    /**
     * BR-46 — an order that would breach the customer's credit limit lands on `credit_hold`
     * rather than `confirmed`.
     *
     * Until now the only trace was a status on a screen nobody had a reason to open, so the
     * order sat there and the customer was told it was confirmed. The people who can clear it
     * are exactly the ones who hold the release permission.
     */
    public function notifyOrderOnCreditHold(SalesOrder $order, float $excess): void
    {
        $this->afterCommit(function () use ($order, $excess): void {
            // `sales_orders.customer_id` is NOT NULL and the foreign key is enforced, so the
            // customer is always there to name.
            $customer = $order->customer->name;
            $reference = $order->number ?? "draft order #{$order->id}";

            foreach ($this->usersWith(['sales_order.release_credit_hold']) as $user) {
                $this->deliver($user, [
                    'document_type' => 'sales_order',
                    'document_id' => (int) $order->id,
                    'document_number' => $order->number,
                    'action' => 'credit_hold',
                    'href' => '/sales-orders/'.$order->id,
                    'title' => 'Order '.$reference.' is held on credit',
                    'body' => sprintf(
                        'Confirming it takes %s past their credit limit by %s, so nothing can be '
                        .'planned or made against it. Review the exposure and either release the '
                        .'hold with a reason or cancel the order.',
                        $customer,
                        number_format($excess, 2),
                    ),
                    'dedupe_key' => 'sales_order:credit_hold:'.$order->id,
                ]);
            }
        });
    }

    /**
     * A rejection raises an NCR with no owner yet (P1-3). Nobody was told, so it waited for
     * whoever happened to open the NCR list next — which is the one queue a rejection cannot
     * afford to sit in.
     */
    public function notifyNcrRaised(Ncr $ncr): void
    {
        $this->afterCommit(function () use ($ncr): void {
            foreach ($this->usersWith(['ncr.update']) as $user) {
                $this->deliver($user, [
                    'document_type' => 'ncr',
                    'document_id' => (int) $ncr->id,
                    'document_number' => $ncr->number,
                    'action' => 'raised',
                    'href' => '/ncrs/'.$ncr->id,
                    'title' => 'NCR '.$ncr->number.' raised from a '.$ncr->severity.' rejection',
                    'body' => 'A lot failed inspection and the batch is frozen until this is '
                        .'dispositioned. Assign an owner and record the investigation.',
                    'dedupe_key' => 'ncr:raised:'.$ncr->id,
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
                    'body' => sprintf(
                        '%s is past its due date of %s and %s is still outstanding. Chase the '
                        .'payment, or record a receipt if it has already been settled.',
                        // `sales_invoices.customer_id` is NOT NULL with the key enforced.
                        $invoice->customer->name,
                        $invoice->due_date?->toDateString() ?? 'its agreed date',
                        // BR-47 — most invoices here are raised in USD; an unlabelled figure
                        // beside a taka one is how two currencies read as one.
                        $this->money((float) $invoice->total - (float) $invoice->received_amount, $invoice->currency_id),
                    ),
                    'dedupe_key' => 'invoice:overdue:'.$invoice->id,
                ]);
            }
        });
    }

    public function notifyCreditNoteApproval(CreditNote $note): void
    {
        $this->afterCommit(function () use ($note): void {
            $band = $this->settings->decimal('credit_note_approval_band_accounts', 50000);
            // BR-51 — the band is base currency, so the note is converted before comparing.
            // The state machine owns the arithmetic; asking it here keeps who-gets-told and
            // who-may-sign the same answer.
            $aboveBand = app(CreditNoteStateMachine::class)->baseValue($note) > $band;
            $users = $this->usersWith(['credit_note.approve'], $aboveBand ? 'md' : null);

            foreach ($users as $user) {
                $this->deliver($user, [
                    'document_type' => 'credit_note',
                    'document_id' => (int) $note->id,
                    'document_number' => $note->number,
                    'action' => 'draft',
                    'href' => '/credit-notes/'.$note->id,
                    'title' => 'Credit note awaiting approval',
                    'body' => sprintf(
                        'A credit note for %s is waiting for approval%s. Nothing is credited to the '
                        .'customer until it is approved. Review what it is for and approve or reject it.',
                        $this->money((float) $note->amount, $note->currency_id ?? null),
                        $aboveBand ? ' and is above the accounts approval band, so only the MD may sign it' : '',
                    ),
                    'dedupe_key' => 'credit_note:draft:'.$note->id,
                ]);
            }
        });
    }

    /**
     * An amount with the currency it is actually in (BR-47).
     *
     * A notification is read away from the document, with none of the context the screen gives,
     * so an unlabelled figure is worse here than anywhere: `1,240.00` means two very different
     * debts depending on whether the invoice behind it was raised in dollars or taka, and most
     * of this factory's are in dollars.
     */
    private function money(float $amount, ?int $currencyId): string
    {
        $code = $currencyId === null
            ? null
            : DB::table('currencies')->where('id', $currencyId)->value('code');

        return ($code ?? $this->settings->get('base_currency', 'BDT')).' '.number_format($amount, 2);
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
     *     body?: string,
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
