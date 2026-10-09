<script setup>
/**
 * The shell every create/edit screen sits in.
 *
 * One decision it exists to enforce: **forms are narrow**. A list wants the full 1600px; a
 * form does not. A product code in a 1,500px-wide input is unreadable — the eye cannot connect
 * the label at the left to the caret at the right, and the screen reads as empty rather than
 * spacious. Two columns of ~360px is what a data-entry form actually wants, and it is what the
 * sibling products use.
 *
 * `wide` is the escape hatch for the two screens that genuinely need the room: a quotation with
 * a cost breakdown beside each line, and a GRN with landed-cost columns.
 *
 * `full` goes one step further and drops the cap entirely, for the screens that pair a line
 * table with a rail beside it — there the width is spent on two columns of content, not on
 * stretching a single input across the monitor.
 */
defineProps({
    wide: { type: Boolean, default: false },
    full: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex gap-8">
        <!--
            Tall enough to reach the bottom of the window even when the form is three fields
            long. The action bar is `sticky bottom-0 mt-auto`, which pins it while a long
            document scrolls — but on a short one the column used to end halfway up the screen
            and the bar ended with it, floating in the middle of an empty page.

            The subtraction is the shell: a 56px header plus the 16px padding above and below
            the main region.
        -->
        <div
            class="flex min-h-[calc(100vh-5.5rem)] min-w-0 flex-1 flex-col"
            :class="full ? '' : wide ? 'max-w-6xl' : 'max-w-3xl'"
        >
            <slot />
        </div>
    </div>
</template>
