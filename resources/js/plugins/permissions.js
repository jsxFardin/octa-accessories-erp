import { usePage } from '@inertiajs/vue3';

/**
 * `$can('sales_order.confirm')` in a template.
 *
 * The frontend hides what the user cannot do; the route middleware is the security boundary
 * and this is a courtesy (06-rbac §7). Never the only check.
 */
export function can(permission) {
    const auth = usePage().props.auth;

    if (!auth?.user) {
        return false;
    }

    if (auth.user.roles?.includes('super_admin')) {
        return true;
    }

    return auth.permissions?.includes(permission) ?? false;
}

export function canAny(...permissions) {
    return permissions.some((permission) => can(permission));
}

export function hasRole(role) {
    return usePage().props.auth?.user?.roles?.includes(role) ?? false;
}

export default {
    install(app) {
        app.config.globalProperties.$can = can;
        app.config.globalProperties.$canAny = canAny;
        app.config.globalProperties.$hasRole = hasRole;
        app.provide('can', can);
    },
};
