import { describe, expect, it, vi } from 'vitest';
import { installRefusalGuard, withRefusalGuard } from '../../resources/js/plugins/refusals.js';

const refused = { props: { flash: { error: 'This job card is not ready.' } } };
const accepted = { props: { flash: { success: 'Released.' } } };

describe('withRefusalGuard', () => {
    // UX audit H-04: the dialog closed and the typed reason was wiped on a refusal.
    it('does not call onSuccess when the server flashed an error', () => {
        const onSuccess = vi.fn();
        const onRefused = vi.fn();

        withRefusalGuard({ method: 'post', onSuccess, onRefused }).onSuccess(refused);

        expect(onSuccess).not.toHaveBeenCalled();
        expect(onRefused).toHaveBeenCalledWith(refused);
    });

    it('calls onSuccess when the write went through', () => {
        const onSuccess = vi.fn();

        withRefusalGuard({ method: 'put', onSuccess }).onSuccess(accepted);

        expect(onSuccess).toHaveBeenCalledWith(accepted);
    });

    it('leaves GET visits alone — a list page carrying an old flash is not a refused write', () => {
        const options = { method: 'get', onSuccess: vi.fn() };

        expect(withRefusalGuard(options)).toBe(options);
    });

    it('leaves options with no onSuccess untouched', () => {
        const options = { method: 'post', preserveScroll: true };

        expect(withRefusalGuard(options)).toBe(options);
    });
});

describe('installRefusalGuard', () => {
    it('guards every visit the router makes', () => {
        const seen = [];
        const router = { visit: (href, options) => seen.push(options) };
        const onSuccess = vi.fn();

        installRefusalGuard(router);
        router.visit('/job-cards/1/release', { method: 'post', onSuccess });
        seen[0].onSuccess(refused);

        expect(onSuccess).not.toHaveBeenCalled();
    });
});
