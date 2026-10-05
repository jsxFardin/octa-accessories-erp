<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';

/**
 * Choosing the winning quotation on an RFQ, from either page that offers it.
 *
 * Above a set value the factory wants three quotations, or a recorded reason for going ahead
 * with fewer. The rule is the server's — it converts the quotation to the factory's currency
 * before comparing — so this does not guess at it: it asks, and if the answer is "three
 * quotations or a reason", it opens a dialog with that answer in it and a place for the reason.
 *
 * It used to post and show nothing. The refusal came back under a key no field displayed, so
 * "Select" appeared to do nothing at all; one page had a reason box nobody was told to fill,
 * and the other had none.
 */
const props = defineProps({
    rfqId: { type: Number, required: true },
});

/** The quotation being selected, while a request for it is in flight. */
const busy = ref(null);
const open = ref(false);
const quotation = ref(null);
const refusal = ref('');
const reason = ref('');

function post(withReason) {
    if (busy.value !== null) return;

    busy.value = quotation.value.id;

    router.post(`/rfqs/${props.rfqId}/select`, {
        quotation_id: quotation.value.id,
        override_reason: withReason ? reason.value.trim() : null,
    }, {
        preserveScroll: true,
        onSuccess: () => { open.value = false; },
        onError: (errors) => {
            // The one refusal that has an answer: say it, and ask for the reason.
            refusal.value = errors.override_reason ?? errors.quotation_id ?? Object.values(errors)[0] ?? 'This quotation could not be selected.';
            open.value = true;
        },
        onFinish: () => { busy.value = null; },
    });
}

function choose(row) {
    quotation.value = row;
    refusal.value = '';
    reason.value = '';
    post(false);
}

defineExpose({ choose, busy });
</script>

<template>
    <Modal
        v-model:open="open"
        :title="`Select ${quotation?.supplier?.name ?? 'this quotation'} with fewer than three quotations?`"
        width="max-w-lg"
        :dirty="reason.trim() !== ''"
    >
        <div class="flex flex-col gap-3">
            <p class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert" data-winner-refusal>{{ refusal }}</p>

            <FormField
                label="Reason for going ahead"
                required
                hint="Kept with the quotation, where an auditor will read it. Or close this and record more quotations first."
            >
                <textarea
                    v-model="reason"
                    rows="3"
                    class="form-textarea"
                    maxlength="500"
                    placeholder="Sole approved supplier for this yarn count; other mills declined to quote."
                    data-winner-reason
                />
            </FormField>

            <p v-if="!reason.trim()" id="winner-blocked" class="text-xs text-ink-600">Give the reason to select this quotation now.</p>
        </div>

        <template #footer>
            <Button @click="open = false">Cancel</Button>
            <Button
                variant="primary"
                :loading="busy !== null"
                :disabled="busy !== null || !reason.trim()"
                :aria-describedby="reason.trim() ? null : 'winner-blocked'"
                data-winner-confirm
                @click="post(true)"
            >Select with this reason</Button>
        </template>
    </Modal>
</template>
