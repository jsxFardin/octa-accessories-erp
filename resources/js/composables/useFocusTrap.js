import { nextTick, onMounted, onUnmounted, watch } from 'vue';

/**
 * Focus, for anything that covers the page.
 *
 * A dialog that opens without taking focus leaves a keyboard user in the page behind it: Tab
 * walks the sidebar and the form underneath while the dialog sits on top, unreachable. Only the
 * confirmation dialog handled this; the modal, the slide-over and the command palette did not.
 *
 * While open, focus is:
 *  - moved in — to the element marked `data-autofocus`, else the first field, else the panel;
 *  - kept in — Tab and Shift+Tab wrap inside the panel;
 *  - put back — on close, to whatever had it when the dialog opened.
 *
 * A select's list, a row menu and the calendar are drawn outside the panel that owns them
 * (teleported to the body so nothing clips them). Focus inside one of those is left alone:
 * it belongs to the control the user just opened, and that control returns focus itself.
 */
const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
    'summary',
].join(',');

const POPOVERS = '[data-select-popover], [data-dropdown-menu], [data-date-popover]';

/**
 * Where Tab goes next, given the stops inside the panel.
 *
 * @param {number} count  how many focusable stops the panel has
 * @param {number} current  index of the stop that has focus, or -1 when focus is elsewhere
 * @param {boolean} backwards  Shift+Tab
 * @returns {number|null} the index to focus, or null to let the browser move focus itself
 */
export function nextStop(count, current, backwards) {
    if (count === 0) return null;
    // Focus has wandered out (or sits on the panel itself): bring it to the nearest end.
    if (current === -1) return backwards ? count - 1 : 0;
    if (!backwards && current === count - 1) return 0;
    if (backwards && current === 0) return count - 1;

    return null;
}

function stops(panel) {
    return [...panel.querySelectorAll(FOCUSABLE)].filter((element) => element.offsetParent !== null || element === document.activeElement);
}

/**
 * @param {import('vue').Ref<HTMLElement|null>} panel  the dialog element
 * @param {() => boolean} isOpen
 * @param {() => boolean} isTop  whether this is the innermost overlay
 */
export function useFocusTrap(panel, isOpen, isTop = () => true) {
    let opener = null;

    async function enter() {
        opener = document.activeElement;
        await nextTick();

        const element = panel.value;

        if (!element) return;

        const preferred = element.querySelector('[data-autofocus]')
            // The first thing to fill in, rather than the close button in the header.
            ?? element.querySelector('input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [role="combobox"]:not([disabled])')
            ?? element;

        preferred.focus({ preventScroll: true });
    }

    function leave() {
        // Only if it is still on the page: the row that opened a dialog may have been deleted by it.
        if (opener && document.contains(opener)) opener.focus?.({ preventScroll: true });
        opener = null;
    }

    function onKeydown(event) {
        if (event.key !== 'Tab' || !isOpen() || !isTop() || !panel.value) return;

        const active = document.activeElement;

        if (active?.closest?.(POPOVERS)) return;

        const list = stops(panel.value);
        const target = nextStop(list.length, list.indexOf(active), event.shiftKey);

        if (list.length === 0) {
            event.preventDefault();
            panel.value.focus();

            return;
        }

        if (target === null) return;

        event.preventDefault();
        list[target].focus();
    }

    watch(isOpen, (open) => (open ? enter() : leave()));

    onMounted(() => {
        document.addEventListener('keydown', onKeydown, true);
        if (isOpen()) enter();
    });

    onUnmounted(() => {
        document.removeEventListener('keydown', onKeydown, true);
        if (isOpen()) leave();
    });
}
