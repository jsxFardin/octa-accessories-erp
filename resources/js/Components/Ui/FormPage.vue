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
    /** Section navigation is only worth its space past three groups. */
    sections: { type: Array, default: () => [] },
    wide: { type: Boolean, default: false },
    full: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex gap-8">
        <!--
            A rail of anchors rather than a scroll-and-hunt: on the specification screens a
            field can be four sections down, and "where was the cut gap?" should be one click.
        -->
        <nav v-if="sections.length > 2" class="sticky top-20 hidden h-fit w-44 shrink-0 xl:block" aria-label="Sections">
            <ul class="space-y-0.5 border-l border-slate-200">
                <li v-for="section in sections" :key="section">
                    <a
                        :href="`#${section.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`"
                        class="-ml-px block border-l-2 border-transparent py-1 pl-3 text-xs text-ink-500 transition hover:border-brand-400 hover:text-ink-900"
                    >
                        {{ section }}
                    </a>
                </li>
            </ul>
        </nav>

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
