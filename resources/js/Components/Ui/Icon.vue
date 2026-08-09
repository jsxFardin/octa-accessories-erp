<script setup>
import { computed } from 'vue';
import {
    Activity, ArchiveRestore, Award, BadgeCheck, Banknote, Barcode, Beaker, Bell, Blocks,
    Boxes, Building2, CalendarDays, Check, ChevronDown, ChevronLeft, ChevronRight, ChevronUp,
    ClipboardCheck, ClipboardList, Cog, Command, Copy, CreditCard, Download, Factory,
    FileSpreadsheet, FileText, Filter, Gauge, Inbox, Layers, LayoutDashboard, LogOut, Mail,
    MapPin, Menu, MoreVertical, Package, PackageCheck, PackageOpen, Pencil, Plus, Printer,
    Receipt, RefreshCw, Route, Ruler, Search, Send, Settings2, Shield, ShieldCheck,
    ShoppingCart, SlidersHorizontal, Sparkles, Trash2, Truck, User, Users, Warehouse, X,
} from '@lucide/vue';

/**
 * One icon component, one registry.
 *
 * The sidebar used to run on unicode glyphs (`⇉ ⌗ ▦ ⛬`), which render in whatever font the
 * device happens to have — different baselines, different stroke weights, and on some Android
 * scanners nothing at all. Lucide is installed through npm and bundled, so nothing is fetched
 * from a CDN (08-architecture §5).
 *
 * Domain names, not shape names: pages ask for `goods-receipt`, and this file decides what
 * that looks like. Changing the drawing later is then a one-line edit here.
 */
const REGISTRY = {
    // Navigation — one per menu entry
    dashboard: LayoutDashboard,
    inbox: Inbox,
    quote: FileSpreadsheet,
    order: ClipboardList,
    customers: Users,
    product: Package,
    artwork: Pencil,
    routing: Route,
    tool: Ruler,
    requisition: ClipboardCheck,
    'purchase-order': ShoppingCart,
    'goods-receipt': PackageOpen,
    supplier: Factory,
    stock: Boxes,
    lot: Layers,
    issue: ArchiveRestore,
    item: Blocks,
    planning: CalendarDays,
    mrp: Activity,
    'job-card': FileText,
    machine: Cog,
    inspection: BadgeCheck,
    lab: Beaker,
    compliance: ShieldCheck,
    packing: PackageCheck,
    challan: Truck,
    trip: MapPin,
    invoice: Receipt,
    receipt: Banknote,
    users: User,
    roles: Shield,
    building: Building2,
    sliders: SlidersHorizontal,
    sequence: Barcode,
    audit: Gauge,

    // Interface
    add: Plus,
    edit: Pencil,
    remove: Trash2,
    close: X,
    check: Check,
    search: Search,
    filter: Filter,
    menu: Menu,
    more: MoreVertical,
    down: ChevronDown,
    up: ChevronUp,
    left: ChevronLeft,
    right: ChevronRight,
    print: Printer,
    download: Download,
    copy: Copy,
    refresh: RefreshCw,
    send: Send,
    logout: LogOut,
    settings: Settings2,
    mail: Mail,
    bell: Bell,
    command: Command,
    warehouse: Warehouse,
    card: CreditCard,
    award: Award,
    sparkles: Sparkles,
};

const props = defineProps({
    name: { type: String, required: true },
    /** Tailwind size class; icons inherit `currentColor` so tone is the parent's business. */
    size: { type: String, default: 'size-4' },
    strokeWidth: { type: [Number, String], default: 1.75 },
});

const component = computed(() => REGISTRY[props.name] ?? null);
</script>

<template>
    <component
        :is="component"
        v-if="component"
        :class="size"
        :stroke-width="Number(strokeWidth)"
        aria-hidden="true"
    />
    <!-- An unknown name renders nothing rather than a broken box; the layout stays put. -->
    <span v-else :class="size" aria-hidden="true" />
</template>
