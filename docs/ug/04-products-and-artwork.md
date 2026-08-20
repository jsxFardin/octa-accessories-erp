# Products & artwork

Engineering lives under **Products** (Products, Artwork, BOMs, Routings, Tools). Machines sit on **Floor**.

Nothing ships from a product code alone. A **current spec** plus an **approved artwork version** are what Gate 1 checks.

## Products

**Products → New product** (or open `PRD-NFJ-CARE-01` from the demo).

Identity: code, name, customer, brand, product type (woven, flexo, screen, heat transfer, offset, thermal…). Type drives which spec fields and consumption formulas apply.

A product is not quotable until it has:

- a **current** spec,
- a **routing** (process steps and machines),
- eventually a **BOM** (what stores will issue).

## Specs

On the product, maintain versions of the technical spec (width, cut type, fold, colours, substrate…). **Make current** when the factory should use this version. The previous current becomes superseded.

Sales-order confirm looks at the **current** spec, not at a draft sitting on the product.

## Artwork (Gate 1)

**Products → Artwork**, or the Artwork list.

1. Create artwork against the product.
2. Upload a version (file + checksum). Status `draft`.
3. **Submit** to the customer.
4. Customer approves → **Approve** with a **customer reference** (email, portal id, signed PDF — evidence is required). That is the only approved version; a new approval **supersedes** the old one in the same transaction. Two approved versions of the same artwork cannot exist.
5. Reject → reason goes on the thread; designer revises with a new version.

Job cards point at `artwork_version_id` and it is **not null**. There is no path that releases production against an unapproved design. If release is blocked, the artwork is not approved — not “the planner forgot a tick box”.

Merchandiser typically submits; merchandiser / sales manager / MD approve when they hold `artwork.approve`.

## Routings

**Products → Routings**: ordered operations (warp, weave, cut, fold, QC…), machine group, standard minutes, whether the step **requires QC**.

Parallel operations are allowed only where the routing says `allow_parallel`. Otherwise an operation cannot start before its predecessor is completed.

## BOM

**Products → BOMs**, or the **Bills of material** card on the product. Yarn, ink, ribbon, packing, per the consumption plan. **Activate** one BOM; the previous active is superseded.

Job-card release checks that required material can be issued (or a supervisor waives with a reason). A BOM that is still draft will not satisfy the gate.

## Tools

Plates, screens, dies, cylinders — linked to products and operations. Track them here so a job card can demand the right tool.

## Machines

**Floor → Machines.** Rate, width, colours, kW, department, active flag. Planning and costing read these. Do not delete a machine that has history; deactivate it.
