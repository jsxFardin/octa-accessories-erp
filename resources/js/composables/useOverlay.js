/**
 * One stack for everything that covers the page.
 *
 * Overlays nest — the import guidelines are a modal opened from inside the import slide-over —
 * and two things break the moment they do:
 *
 *  - **The scroll lock.** The inner overlay closes, sets `overflow: ''`, and the page starts
 *    scrolling behind a panel that is still open. Counted here, so the lock lifts when the
 *    last overlay closes rather than the first.
 *  - **Escape.** Both components listen on `document`, so one keypress closed the modal *and*
 *    the panel underneath it. `isTopOverlay` lets each one ignore a key that was not meant
 *    for it.
 */
let sequence = 0;

/** @type {number[]} innermost overlay last. */
const stack = [];

/** @returns {number} a token to hand back on close. */
export function openOverlay() {
    sequence += 1;
    stack.push(sequence);

    if (stack.length === 1) {
        document.body.style.overflow = 'hidden';
    }

    return sequence;
}

export function closeOverlay(token) {
    const index = stack.indexOf(token);

    if (index === -1) {
        return;
    }

    stack.splice(index, 1);

    if (stack.length === 0) {
        document.body.style.overflow = '';
    }
}

export function isTopOverlay(token) {
    return stack.length > 0 && stack[stack.length - 1] === token;
}
