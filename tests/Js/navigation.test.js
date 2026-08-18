import { describe, expect, it } from 'vitest';
import { ADMIN_PREFIXES, adminNavigation, navigation, visibleSections } from '../../resources/js/navigation.js';

const all = () => true;
const none = () => false;
const only = (...granted) => (...wanted) => wanted.some((p) => granted.includes(p));

function rows(sections) {
    return sections.flatMap((section) => section.items.map((item) => item.label));
}

describe('sidebar visibility', () => {
    it('renders leaf items, not just hubs', () => {
        // The regression this exists for: a filter meant to drop empty hubs dropped every leaf
        // item too, so the whole sidebar rendered blank while every page still answered 200.
        const labels = rows(visibleSections(navigation, all));

        expect(labels).toContain('Inquiries');
        expect(labels).toContain('Suppliers');
        expect(labels).toContain('Job cards');
        expect(labels).toContain('Products');
        expect(labels).toContain('Reports');
        expect(labels.length).toBeGreaterThan(10);
    });

    it('groups the factory sequence, not a heading per hub', () => {
        const labels = visibleSections(navigation, all).map((section) => section.label);

        expect(labels).toEqual(['Overview', 'Sales', 'Buying', 'Floor', 'Records']);
        expect(visibleSections(navigation, all).find((section) => section.label === 'Records')?.heading).toBe(false);
    });

    it('shows every administration screen inside the shell', () => {
        expect(rows(visibleSections(adminNavigation, all))).toEqual([
            'Setup',
            'Settings',
            'Number sequences',
            'Users',
            'Roles & permissions',
            'Audit log',
        ]);
    });

    it('hides everything from a user with no permissions', () => {
        expect(visibleSections(navigation, none)).toEqual([]);
        expect(visibleSections(adminNavigation, none)).toEqual([]);
    });

    it('drops a hub whose every tab is out of reach', () => {
        const sections = visibleSections(navigation, only('sales_order.view_any'));

        expect(rows(sections)).toEqual(['Sales orders']);
    });

    it('points a hub at the first tab the user may open', () => {
        // Someone who may read lots but not stock balances should not land on a 403.
        const sections = visibleSections(navigation, only('stock_issue.view_any'));
        const inventory = sections.flatMap((s) => s.items).find((i) => i.label === 'Inventory');

        expect(inventory.href).toBe('/material-issues');
        expect(inventory.children.map((c) => c.label)).toEqual(['Material issues']);
    });

    it('keeps every hub tab reachable by its own URL', () => {
        // Collapsing rows into tabs must not orphan a screen: each child keeps a real href.
        for (const section of navigation) {
            for (const item of section.items) {
                for (const child of item.children ?? []) {
                    expect(child.href.startsWith('/')).toBe(true);
                    expect(child.permissions.length).toBeGreaterThan(0);
                }
            }
        }
    });

    it('keeps administration out of the working application', () => {
        const mainHrefs = navigation
            .flatMap((s) => s.items)
            .flatMap((i) => [i.href, ...(i.children ?? []).map((c) => c.href)]);

        for (const href of mainHrefs) {
            expect(ADMIN_PREFIXES.some((prefix) => href.startsWith(prefix))).toBe(false);
        }
    });
});

describe('hub tabs', () => {
    // The strip belongs to the lists it groups. Above a half-filled form it invites a click
    // that discards the form, and "Artwork · Routings · Tools" says nothing while you are
    // creating a product.
    const hubChildHrefs = navigation
        .flatMap((section) => section.items)
        .flatMap((item) => item.children ?? [])
        .map((child) => child.href);

    it('knows a list URL from a form URL', () => {
        expect(hubChildHrefs).toContain('/products');
        expect(hubChildHrefs).not.toContain('/products/create');
    });

    it('gives every hub child a URL that is a list, never a detail route', () => {
        for (const href of hubChildHrefs) {
            expect(href).not.toMatch(/\/(create|edit)$/);
            expect(href).not.toMatch(/\{|\}|:/);
        }
    });
});
