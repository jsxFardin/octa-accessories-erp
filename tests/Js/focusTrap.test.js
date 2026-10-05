import { describe, expect, it } from 'vitest';
import { nextStop } from '../../resources/js/composables/useFocusTrap.js';

// UX audit H-47: Tab used to walk out of an open dialog into the page behind it.
describe('nextStop', () => {
    it('wraps from the last stop to the first', () => {
        expect(nextStop(4, 3, false)).toBe(0);
    });

    it('wraps from the first stop to the last on Shift+Tab', () => {
        expect(nextStop(4, 0, true)).toBe(3);
    });

    it('leaves a move in the middle to the browser', () => {
        expect(nextStop(4, 1, false)).toBeNull();
        expect(nextStop(4, 2, true)).toBeNull();
    });

    it('brings focus back in when it is outside the panel', () => {
        expect(nextStop(4, -1, false)).toBe(0);
        expect(nextStop(4, -1, true)).toBe(3);
    });

    it('keeps a single stop on itself', () => {
        expect(nextStop(1, 0, false)).toBe(0);
        expect(nextStop(1, 0, true)).toBe(0);
    });

    it('has nowhere to send focus in an empty panel', () => {
        expect(nextStop(0, -1, false)).toBeNull();
    });
});
