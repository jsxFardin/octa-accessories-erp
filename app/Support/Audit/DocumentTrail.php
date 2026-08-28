<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use App\Support\Validation\DocumentValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * F-01/F-02 — a document's own history, in words an ERP operator can read.
 *
 * The rows were being written the whole time. `Auditable` has been on `Quotation`,
 * `SalesOrder` and `Inquiry` for some while and `audit_logs` holds hundreds of rows against
 * them, but no detail page ever asked for them: the quotation screen ended at the document
 * total, and the only way to read the trail was the global admin log, filtered by hand, by
 * someone holding `audit_log.view_any`. The data existed and the answer was unreachable.
 *
 * Three things this deliberately does:
 *
 * **It reads both shapes of row.** Most events are recorded against the model class, but the
 * print path records against the table name (`recordTable('quotations', …)`). Those rows are
 * real history and are matched too, so a reprint is not invisible merely because it was
 * written through a different door.
 *
 * **It says "created" even where no row says so.** Documents seeded or imported before
 * auditing covered them have no `created` event, and a trail that opens at "status changed to
 * sent" reads as though the document appeared from nowhere. Where the document's own
 * `created_at`/`created_by` columns answer the question, the opening entry is derived from
 * them and flagged `derived` — the audit table is not written to in order to make a screen
 * look complete.
 *
 * **It does not leak.** The narrative is available to anyone who may view the document; the
 * raw before/after values, the IP and the user agent are only attached for a viewer holding
 * `audit_log.view_any`, which is the permission that already gates the global log.
 */
class DocumentTrail
{
    /** Documents whose own columns can answer "who made this, and when". */
    private const CREATION_COLUMNS = ['created_at', 'created_by'];

    /**
     * The trail for one document, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function for(Model $document, ?User $viewer = null): array
    {
        $detailed = $viewer?->hasPermission('audit_log.view_any') ?? false;

        $rows = AuditLog::query()
            ->with('user:id,name')
            ->where('auditable_id', $document->getKey())
            // Both doors: the model class the trait writes, and the table name the print and
            // reference paths write.
            ->whereIn('auditable_type', [$document::class, $document->getTable()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = $this->entry($row, $detailed);
        }

        $opening = $this->derivedCreation($document, $rows);

        if ($opening !== null) {
            array_unshift($entries, $opening);
        }

        return $entries;
    }

    /**
     * The "created" entry for a document whose creation predates its auditing.
     *
     * Returns null when a real `created` row exists — the recorded event always wins — or when
     * the document carries nothing to derive one from.
     *
     * @param  \Illuminate\Support\Collection<int, AuditLog>  $rows
     * @return array<string, mixed>|null
     */
    private function derivedCreation(Model $document, $rows): ?array
    {
        if ($rows->contains(fn (AuditLog $row): bool => $row->event === 'created')) {
            return null;
        }

        $columns = $document->getAttributes();

        foreach (self::CREATION_COLUMNS as $column) {
            if (! array_key_exists($column, $columns)) {
                return null;
            }
        }

        if ($columns['created_at'] === null) {
            return null;
        }

        $actor = $columns['created_by'] === null
            ? null
            : DB::table('users')->where('id', $columns['created_by'])->value('name');

        return [
            'event' => 'created',
            'label' => 'Created',
            'description' => 'Recorded from this document rather than from the audit trail — '
                .'it was created before auditing covered this document type.',
            'at' => $columns['created_at'],
            'actor' => $actor,
            'reference' => null,
            'href' => null,
            'derived' => true,
        ];
    }

    /**
     * One audit row as a line of history.
     *
     * @return array<string, mixed>
     */
    private function entry(AuditLog $row, bool $detailed): array
    {
        $new = $row->new_values ?? [];
        $old = $row->old_values ?? [];

        [$label, $description, $reference, $href] = match ($row->event) {
            'created' => ['Created', null, null, null],
            'status_changed' => $this->transition($old, $new),
            'converted' => $this->conversion($new),
            'printed' => ['Printed', 'A copy of this document was produced.', null, null],
            'updated' => ['Edited', $this->changedFields($new), null, null],
            'deleted' => ['Deleted', null, null, null],
            default => [ucfirst(str_replace('_', ' ', $row->event)), null, null, null],
        };

        $entry = [
            'event' => $row->event,
            'label' => $label,
            'description' => $description,
            'at' => $row->created_at,
            'actor' => $row->user?->name,
            'reference' => $reference,
            'href' => $href,
            'derived' => false,
        ];

        // The field-level record is the auditor's view, not the operator's, and it is what the
        // `audit_log.view_any` permission has always gated.
        if ($detailed) {
            $entry['detail'] = [
                'old' => $old === [] ? null : $old,
                'new' => $new === [] ? null : $new,
                'ip_address' => $row->ip_address,
            ];
        }

        return $entry;
    }

    /**
     * `draft → sent`, plus whatever the state machine recorded about why.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{0: string, 1: string|null, 2: string|null, 3: string|null}
     */
    private function transition(array $old, array $new): array
    {
        $from = $this->humanise((string) ($old['status'] ?? ''));
        $to = $this->humanise((string) ($new['status'] ?? ''));

        $label = $from === '' ? "Status set to {$to}" : "Status changed from {$from} to {$to}";

        // The state machines record the reason alongside the transition — a rejection reason, a
        // waiver, a hold. It is the part of the row anyone actually wants.
        $reasons = array_filter([
            $new['reject_reason'] ?? null,
            $new['lost_reason'] ?? null,
            $new['reason'] ?? null,
            $new['hold_reason'] ?? null,
            $new['detail'] ?? null,
        ], fn ($value): bool => is_string($value) && $value !== '');

        return [$label, $reasons === [] ? null : implode(' ', $reasons), null, null];
    }

    /**
     * What this document became, with a link to it.
     *
     * @param  array<string, mixed>  $new
     * @return array{0: string, 1: string|null, 2: string|null, 3: string|null}
     */
    private function conversion(array $new): array
    {
        $type = is_string($new['target_type'] ?? null) ? $new['target_type'] : null;
        $id = isset($new['target_id']) ? (int) $new['target_id'] : null;

        if ($type === null || $id === null || ! class_exists($type)) {
            return ['Converted', null, null, null];
        }

        /** @var Model $target */
        $target = new $type;

        $number = DB::table($target->getTable())->where('id', $id)->value('number');
        $reference = is_string($number) && $number !== '' ? $number : class_basename($type)." #{$id}";

        return [
            'Converted to '.$reference,
            null,
            $reference,
            $this->routeFor($target->getTable(), $id),
        ];
    }

    /**
     * Which fields an edit touched, named as a person would name them.
     *
     * The values themselves are not in the sentence: an operator wants to know that the
     * delivery date moved, and an auditor wants the two dates, and only the second of those is
     * entitled to see them here.
     *
     * @param  array<string, mixed>  $new
     */
    private function changedFields(array $new): ?string
    {
        $fields = array_keys($new);

        if ($fields === []) {
            return null;
        }

        $named = array_map(
            fn (string $field): string => DocumentValidator::attributeLabel($field),
            $fields,
        );

        sort($named);

        return 'Changed '.implode(', ', array_slice($named, 0, 6))
            .(count($named) > 6 ? ' and '.(count($named) - 6).' more' : '').'.';
    }

    /** The screen a converted document lives on. */
    private function routeFor(string $table, int $id): ?string
    {
        return match ($table) {
            'sales_orders' => "/sales-orders/{$id}",
            'quotations' => "/quotations/{$id}",
            'inquiries' => "/inquiries/{$id}",
            'purchase_orders' => "/purchase-orders/{$id}",
            'job_cards' => "/job-cards/{$id}",
            'delivery_challans' => "/delivery-challans/{$id}",
            'packing_lists' => "/packing-lists/{$id}",
            'sales_invoices' => "/invoices/{$id}",
            default => null,
        };
    }

    private function humanise(string $status): string
    {
        return str_replace('_', ' ', $status);
    }
}
