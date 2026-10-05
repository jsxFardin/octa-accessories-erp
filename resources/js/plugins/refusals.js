/**
 * A write the server refused is not a success.
 *
 * Most controllers answer a refused action — a transition the rules do not allow, a gate that
 * is still red — by redirecting back with a flash error. To Inertia that is a perfectly good
 * response, so the page's `onSuccess` ran: the dialog closed, the form reset, and the reason
 * the user had just typed for a waiver or an override was gone, with the refusal left to a
 * toast. Forty-eight call sites did this.
 *
 * It is settled here once instead: for any visit that is not a GET, `onSuccess` is skipped
 * when the page that came back carries a flash error. The dialog stays open with its input,
 * `Modal` and `SlideOver` show the message inside themselves, and `onFinish` still runs so the
 * button stops spinning. A call that wants to react to a refusal can pass `onRefused`.
 */
export function isRefusal(page) {
    return Boolean(page?.props?.flash?.error);
}

/** @param {object} options  Inertia visit options */
export function withRefusalGuard(options = {}) {
    const method = String(options.method ?? 'get').toLowerCase();

    if (method === 'get' || typeof options.onSuccess !== 'function') return options;

    const { onSuccess, onRefused } = options;

    return {
        ...options,
        onSuccess: (page) => (isRefusal(page) ? onRefused?.(page) : onSuccess(page)),
    };
}

/** Routes every visit this router makes through the guard. */
export function installRefusalGuard(router) {
    const visit = router.visit.bind(router);

    router.visit = (href, options = {}) => visit(href, withRefusalGuard(options));
}
