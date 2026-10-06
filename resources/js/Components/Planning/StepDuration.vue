<script setup>
import { computed } from 'vue';
import Icon from '@/Components/Ui/Icon.vue';
import { minutes } from '@/plugins/formatting';
import { implausibleMinutes } from '@/planning/board';

/**
 * How long a step takes — and, when the figure cannot be right, that it cannot.
 *
 * A plate-making step of 52,501 hours was printed like any other duration, so it read as a
 * fact. The figure is left as the routing computed it; this only says it wants checking.
 */
const props = defineProps({
    value: { type: [Number, String], default: 0 },
});

const suspect = computed(() => implausibleMinutes(props.value));
</script>

<template>
    <span
        v-if="suspect"
        class="inline-flex items-center gap-1 font-medium text-amber-800"
        title="Check routing — duration looks wrong"
        data-suspect-duration
    >
        <Icon name="warning" size="size-3.5" class="shrink-0" />
        {{ minutes(value) }}
        <span class="sr-only">Check routing — duration looks wrong</span>
    </span>
    <span v-else>{{ minutes(value) }}</span>
</template>
