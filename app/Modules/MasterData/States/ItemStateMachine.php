<?php

declare(strict_types=1);

namespace App\Modules\MasterData\States;

use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Services\ItemActivationChecklist;
use App\Support\Audit\AuditLogger;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * IM-1 — the item lifecycle.
 *
 * An item is born `draft` and becomes `active` only when the activation checklist is clear;
 * the audit row of that transition is the approver's signature. Active items may be put on
 * hold and brought back, or discontinued for good. Only an active item may be bought, put on
 * a bill of materials, quoted or ordered.
 *
 * @extends StateMachine<Item>
 */
class ItemStateMachine extends StateMachine
{
    public function __construct(AuditLogger $audit, private readonly ItemActivationChecklist $checklist)
    {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            Item::DRAFT => [Item::ACTIVE, Item::DISCONTINUED],
            Item::ACTIVE => [Item::ON_HOLD, Item::DISCONTINUED],
            Item::ON_HOLD => [Item::ACTIVE, Item::DISCONTINUED],
            Item::DISCONTINUED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            Item::ACTIVE => 'item.activate',
            Item::ON_HOLD => 'item.update',
            Item::DISCONTINUED => 'item.update',
        ];
    }

    /**
     * @param  Item  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === Item::ACTIVE && $from === Item::DRAFT) {
            $blockers = $this->checklist->blockers($document);

            if ($blockers !== []) {
                throw TransitionDenied::guard('IM-1', "Not ready to activate.\n• ".implode("\n• ", $blockers));
            }
        }

        if ($to === Item::DISCONTINUED && blank($context['reason'] ?? null)) {
            throw TransitionDenied::guard('IM-1', 'Discontinuing an item needs a reason; the documents that still name it will show it.');
        }
    }

    /**
     * @param  Item  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        if ($to === Item::ACTIVE && $document->activated_at === null) {
            $document->forceFill(['activated_at' => now(), 'activated_by' => auth()->id()])->save();
        }
    }
}
