/**
 * The item master form, as `ResourceForm` sections — one builder for the Materials screen and
 * the Products screen, so a drawcord and a yarn are asked the same questions in the same order
 * and the finished-goods form differs only in what it fixes (type, make) and what it adds
 * (the make profile).
 *
 * `options` is the bag of lists the controller sends: families, groups, uoms, warehouses,
 * customers, suppliers, categories, familyAttributes (by family id) and the fixed vocabularies.
 *
 * Hints are one short line, and only where the label alone could be misread. A form that
 * explains every field is a form nobody reads.
 */

const MADE_TYPES = ['finished_good', 'semi_finished', 'component'];

const isMade = (form) => form.make_or_buy === 'make';
const isFinishedGood = (form) => form.item_type === 'finished_good';
const isBuyerSpecific = (form) => form.spec_scope === 'buyer';
const hasFamily = (form) => Boolean(form.production_family_id);

/**
 * @param {object} options the controller's lists
 * @param {{ fixedType?: string, isEdit?: boolean }} variant
 */
export function identitySection(options, { fixedType = null, isEdit = false } = {}) {
    const fields = [
        { key: 'name', label: 'Name', required: true, span: 'full' },
    ];

    if (fixedType === null) {
        fields.push(
            { key: 'item_type', label: 'Item type', type: 'select', options: options.itemTypes, default: 'raw_material', required: true },
            { key: 'make_or_buy', label: 'Make or buy', type: 'select', options: options.makeOrBuy, default: 'buy', required: true },
            { key: 'item_category_id', label: 'Category', type: 'select', options: options.categories ?? [], valueKey: 'id', labelKey: 'name', required: true },
        );
    }

    fields.push(
        { key: 'code', label: 'Code', disabled: isEdit, hint: isEdit ? null : 'Assigned on save.' },
        { key: 'description', label: 'Description', type: 'textarea', span: 'full' },
    );

    return { title: 'Identity', fields };
}

/** Who it is for: standard, or made for one buyer. On the product form this carries brand and style too. */
export function scopeSection(options, { forProduct = false } = {}) {
    const fields = [
        { key: 'spec_scope', label: 'Scope', type: 'select', options: options.specScopes, default: forProduct ? 'buyer' : 'standard', required: true },
        {
            key: 'customer_id', label: 'Buyer', type: 'select', options: options.customers ?? [], valueKey: 'id', labelKey: 'name', required: true,
            when: isBuyerSpecific,
            hint: 'Cannot be changed later; a product stays with its buyer.',
        },
    ];

    if (forProduct) {
        fields.push(
            {
                key: 'brand_id', label: 'Brand', type: 'select', valueKey: 'id', labelKey: 'name', when: isBuyerSpecific,
                options: (form) => (options.brands ?? []).filter((brand) => brand.customer_id === null || Number(brand.customer_id) === Number(form.customer_id)),
            },
            { key: 'customer_style_ref', label: 'Buyer style ref', when: isBuyerSpecific },
        );
    }

    return {
        title: 'Made for',
        rule: 'P1',
        description: 'A standard item may be ordered by any customer. A buyer-specific one belongs to its buyer.',
        fields,
    };
}

export function classificationSection(options, { forProduct = false } = {}) {
    const fields = [
        {
            key: 'production_family_id', label: 'Production family', type: 'select', options: options.families, required: forProduct,
            hint: forProduct ? null : 'Required for a made item.',
        },
        {
            key: 'item_group_id', label: 'Group', type: 'select', valueKey: 'id', labelKey: 'name',
            options: (form) => (options.groups ?? []).filter((group) => Number(group.production_family_id) === Number(form.production_family_id)),
        },
        { key: 'garment_type', label: 'Garment type', type: 'select', options: options.garmentTypes },
        { key: 'material_base', label: 'Material base', type: 'select', options: options.materialBases },
    ];

    return { title: 'Classification', rule: 'IM-1', fields };
}

export function unitsSection(options, { forProduct = false } = {}) {
    const fields = [
        { key: 'base_uom_id', label: 'Stock unit', type: 'select', options: options.uoms, valueKey: 'id', labelKey: 'code', required: !forProduct, hint: forProduct ? 'Pieces unless counted otherwise.' : null },
        { key: 'order_uom_id', label: 'Order unit', type: 'select', options: options.uoms, valueKey: 'id', labelKey: 'code', when: (form) => isFinishedGood(form) || forProduct, hint: 'What the buyer orders in. A different unit needs a conversion in Setup.' },
        { key: 'purchase_uom_id', label: 'Buying unit', type: 'select', options: options.uoms, valueKey: 'id', labelKey: 'code', when: (form) => !isMade(form) && !forProduct },
        { key: 'pack_pcs_per_inner', label: 'Pieces per inner', type: 'number', when: (form) => isFinishedGood(form) || forProduct || form.item_type === 'packaging' },
        { key: 'pack_inners_per_carton', label: 'Inners per carton', type: 'number', when: (form) => isFinishedGood(form) || forProduct || form.item_type === 'packaging' },
        { key: 'default_warehouse_id', label: 'Default warehouse', type: 'select', options: options.warehouses, valueKey: 'id', labelKey: 'name' },
    ];

    if (!forProduct) {
        fields.push(
            { key: 'default_supplier_id', label: 'Default supplier', type: 'select', options: options.suppliers ?? [], valueKey: 'id', labelKey: 'name', when: (form) => !isMade(form) && form.item_type !== 'service' },
            { key: 'min_order_qty', label: 'Minimum order qty', type: 'number', step: '0.000001', default: 0, rule: 'BR-25', when: (form) => !isMade(form) },
            { key: 'order_multiple', label: 'Order multiple', type: 'number', step: '0.000001', default: 1, rule: 'BR-25', when: (form) => !isMade(form) },
            { key: 'reorder_level', label: 'Reorder level', type: 'number', step: '0.000001', default: 0 },
            { key: 'max_stock_qty', label: 'Maximum stock', type: 'number', step: '0.000001' },
            { key: 'safety_days', label: 'Safety days', type: 'number', default: 0, rule: 'BR-26', when: (form) => !isMade(form) },
        );
    }

    return { title: forProduct ? 'Units and packing' : 'Units, purchasing and stock', rule: 'BR-2 · BR-25', fields };
}

export function specificationSection(options) {
    const definitions = options.familyAttributes ?? {};

    return {
        title: 'Specification and variants',
        empty: 'Choose a production family above and its specification fields appear here.',
        fields: [
            // One field per family attribute; only the chosen family's are shown.
            ...Object.entries(definitions).flatMap(([familyId, attributes]) => attributes.map((attribute) => ({
                key: `attributes.${attribute.key}`,
                label: attribute.unit ? `${attribute.label} (${attribute.unit})` : attribute.label,
                required: attribute.required,
                when: (form) => Number(form.production_family_id) === Number(familyId),
                ...(attribute.type === 'select'
                    ? { type: 'select', options: (attribute.options ?? []).map((option) => ({ value: option, label: option })) }
                    : attribute.type === 'number'
                        ? { type: 'number', step: 'any' }
                        : attribute.type === 'boolean'
                            ? { type: 'checkbox' }
                            : {}),
            }))),
            { key: 'variant_axes', label: 'Varies by', type: 'checkboxes', options: options.variantAxes, default: [], span: 'full', when: hasFamily, hint: 'A zipper varies by colour and length; a button by colour.' },
        ],
    };
}

export function inventorySection(options, { forProduct = false } = {}) {
    return {
        title: 'Costing and quality',
        fields: [
            { key: 'std_rate', label: 'Standard rate', type: 'number', step: '0.0001', default: 0, when: () => !forProduct, hint: 'Per stock unit.' },
            { key: 'valuation_method', label: 'Valuation', type: 'select', options: options.valuationMethods, default: 'weighted_average' },
            { key: 'standard_wastage_pct', label: 'Standard wastage %', type: 'number', step: '0.0001', default: 0 },
            { key: 'is_lot_tracked', label: 'Lot tracked', type: 'checkbox', default: true, checkboxLabel: 'Received into numbered lots' },
            { key: 'qc_plan_ref', label: 'QC plan reference' },
        ],
    };
}

export function byTypeSection(options) {
    return {
        title: 'Tool and service',
        when: (form) => form.item_type === 'tool' || form.item_type === 'service',
        fields: [
            { key: 'tool_cavities', label: 'Cavities', type: 'number', when: (form) => form.item_type === 'tool', required: true, hint: 'Pieces per shot or stroke.' },
            { key: 'tool_owner_customer_id', label: 'Tool owner', type: 'select', options: options.customers ?? [], valueKey: 'id', labelKey: 'name', when: (form) => form.item_type === 'tool', hint: 'Empty: the factory owns it.' },
            { key: 'service_charge_basis', label: 'Charge basis', type: 'select', options: options.chargeBases, when: (form) => form.item_type === 'service', required: true },
        ],
    };
}

export function technicalSection() {
    return {
        title: 'Technical attributes',
        rule: 'BR-9 · BR-10 · BR-37 · BR-39',
        when: (form) => !MADE_TYPES.includes(form.item_type),
        fields: [
            { key: 'density', label: 'Density', type: 'number', step: '0.000001', hint: 'Ink and chemicals.' },
            { key: 'gsm', label: 'GSM', type: 'number', step: '0.001', hint: 'Paper and film.' },
            { key: 'ink_lay_gsm', label: 'Ink lay g/m²', type: 'number', step: '0.001', rule: 'BR-10' },
            { key: 'shade_code', label: 'Shade code' },
            { key: 'is_shade_critical', label: 'Shade critical', type: 'checkbox', rule: 'BR-37', checkboxLabel: 'Suggest same-shade lots first' },
            { key: 'has_expiry', label: 'Has expiry', type: 'checkbox', rule: 'BR-39' },
            { key: 'shelf_life_days', label: 'Shelf life (days)', type: 'number', when: (form) => Boolean(form.has_expiry) },
        ],
    };
}

/** The Materials screen: every type, make or buy. */
export function itemSections(options, { isEdit = false } = {}) {
    return [
        // What it is.
        { ...identitySection(options, { isEdit }), column: 'left' },
        { ...scopeSection(options), column: 'left' },
        { ...classificationSection(options), column: 'left' },
        { ...specificationSection(options), column: 'left' },
        // How it is handled.
        { ...unitsSection(options), column: 'right' },
        { ...inventorySection(options), column: 'right' },
        { ...byTypeSection(options), column: 'right' },
        { ...technicalSection(), column: 'right' },
    ];
}

/** The Products screen: a finished good, made, with its commercial and manufacturing profile. */
export function productSections(options, { isEdit = false } = {}) {
    return [
        // What it is.
        { ...identitySection(options, { fixedType: 'finished_good', isEdit }), column: 'left' },
        { ...scopeSection(options, { forProduct: true }), column: 'left' },
        { ...classificationSection(options, { forProduct: true }), column: 'left' },
        { ...specificationSection(options), column: 'left' },
        // How it is handled.
        { ...unitsSection(options, { forProduct: true }), column: 'right' },
        {
            column: 'right',
            title: 'Manufacturing',
            fields: [
                { key: 'product_type', label: 'Process type', type: 'select', options: options.productTypes, required: true, hint: 'Groups the routings. Outside the label families: Other.' },
                { key: 'routing_id', label: 'Routing', type: 'select', options: options.routings, valueKey: 'id', labelKey: 'label', hint: 'Left blank, the default routing for the process type.' },
                { key: 'is_running_programme', label: 'Running programme', type: 'checkbox', rule: 'BR-15', checkboxLabel: 'Amortise tooling over the annual forecast' },
                { key: 'annual_forecast_qty', label: 'Annual forecast qty', type: 'number', step: '0.000001', rule: 'BR-15', when: (form) => Boolean(form.is_running_programme) },
            ],
        },
        { ...inventorySection(options, { forProduct: true }), column: 'right' },
    ];
}

/** The live summary beside the form: what is about to be saved, in a reader's words. */
export function summarise(form, options, { forProduct = false } = {}) {
    const label = (list, value) => (options[list] ?? []).find((option) => String(option.value ?? option.id) === String(value))?.label ?? null;
    const family = (options.families ?? []).find((option) => String(option.value) === String(form.production_family_id));
    const group = (options.groups ?? []).find((group) => String(group.id) === String(form.item_group_id));
    const customer = (options.customers ?? []).find((customer) => String(customer.id) === String(form.customer_id));
    const uom = (id) => (options.uoms ?? []).find((unit) => String(unit.id) === String(id))?.code ?? null;

    return {
        name: form.name || null,
        kind: forProduct ? 'Finished good · Make' : [label('itemTypes', form.item_type), label('makeOrBuy', form.make_or_buy)].filter(Boolean).join(' · '),
        code: form.code || null,
        family: family ? `${family.code} ${family.label}${group ? ` › ${group.name}` : ''}` : null,
        madeFor: form.spec_scope === 'buyer' ? (customer?.name ?? 'Buyer not chosen yet') : 'Any customer',
        units: uom(form.base_uom_id)
            ? `Stocked in ${uom(form.base_uom_id)}${uom(form.order_uom_id) && form.order_uom_id !== form.base_uom_id ? `, ordered in ${uom(form.order_uom_id)}` : ''}`
            : null,
        variants: Array.isArray(form.variant_axes) && form.variant_axes.length
            ? form.variant_axes.map((axis) => label('variantAxes', axis)).join(', ')
            : null,
    };
}
