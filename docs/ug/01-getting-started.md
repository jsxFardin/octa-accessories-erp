# Getting started

## Two doors

| Door | URL | Who |
|---|---|---|
| Desk | `/login` | Everyone with an email and password |
| Shop floor | `/floor` | Operators (badge + PIN). Supervisors can also use the desk. |

The desk is dense: keyboard, lists, documents. The floor terminal is large type, Bangla by default, four actions. They do not share a layout on purpose.

## Sign in (desk)

1. Open `/login`.
2. Enter your email and password.
3. Optionally tick **Keep me signed in**.
4. You land on a page your role can actually open — not a 403.

After the first sign-in, change the password at **your initials → My account**. Seeded accounts all start as `password`; the account page warns you while that is still true.

Sign out from the same account menu.

## Demo accounts

After `php artisan migrate --seed` on a local install, every role has a user. Password for all: `password`.

| Email | Role | Lands on |
|---|---|---|
| `admin@maheenlabel.test` | Super admin | Dashboard |
| `md@maheenlabel.test` | Managing Director | Dashboard |
| `merchandiser@maheenlabel.test` | Merchandiser | Dashboard |
| `sales@maheenlabel.test` | Sales manager | Dashboard |
| `designer@maheenlabel.test` | Designer | Artwork |
| `engineer@maheenlabel.test` | Engineer | Products |
| `planner@maheenlabel.test` | Planner | Planning board |
| `supervisor@maheenlabel.test` | Production supervisor | Job cards |
| `operator@maheenlabel.test` | Operator | `/floor` |
| `store@maheenlabel.test` | Store keeper | Stock |
| `storemanager@maheenlabel.test` | Store manager | Stock |
| `qc@maheenlabel.test` | QC inspector | Inspections |
| `quality@maheenlabel.test` | Quality manager | Inspections |
| `lab@maheenlabel.test` | Lab technician | Laboratory |
| `compliance@maheenlabel.test` | Compliance officer | Compliance |
| `purchase@maheenlabel.test` | Purchase officer | Purchase orders |
| `purchasemanager@maheenlabel.test` | Purchase manager | Purchase orders |
| `dispatch@maheenlabel.test` | Dispatch officer | Packing lists |
| `driver@maheenlabel.test` | Driver | Trips |
| `accounts@maheenlabel.test` | Accounts | Dashboard |
| `auditor@maheenlabel.test` | Read only | Dashboard (no exports) |

Use **admin** to see every screen. Use **merchandiser** if you want the commercial path as it feels on a real day.

## Shop-floor sign-in

1. Open `/floor`.
2. Scan or type the badge (`card_no` on the employee record).
3. Enter the PIN (in this build: the **last four digits of the badge**).
4. Optionally pick a machine, so the queue is only that machine’s work.
5. **শুরু · SIGN IN**.

Demo operator:

| | |
|---|---|
| Badge | `BADGE-0009` |
| PIN | `0009` |

The session lives in the browser so a wifi drop does not send the operator back to the office. Scan again if the session expires.

## What is already in a local seed

On `local`, the seeder loads a walkthrough order **and 100 extra journeys**, plus a catalogue so Products, Import, Buying and Quality are not empty.

| Record | What it is |
|---|---|
| Customer `CUST-001` | Nordic Apparel Ltd (brand Nordfjell) |
| Product `PRD-NFJ-CARE-01` | 50,000 centre-fold satin woven care labels |
| Extra products | Flexo, screen, heat transfer, offset, thermal — Nordic extras plus one per local buyer (`PRD-L-01` … `PRD-L-10`) |
| Artwork | Approved version (Gate 1 already passed) |
| BOM | Active, yarn or ink plus packing |
| Import | Letters of credit and shipments (draft through cleared), with a posted ink GRN |
| Buying | Requisitions, RFQs, purchase orders, a supplier bill |
| Quality | Lab reports and NCRs |
| Floor | 23 machines across every group (looms, presses, cutters, pack tables) |
| Dispatch | Vehicles, a driver, trips against issued challans |
| Stock | Posted GRN into RM / PACK, including one GRS yarn lot |
| Sales order | Customer PO `NFJ-PO-2026-0918`, **confirmed** |
| Job card | **Draft**, waiting for the planner to release |

Open **Sales → Inquiries**. A local seed also loads **100 journeys** parked at every stage (draft inquiry through issued invoice) so lists, filters and pagination have real documents. The original Nordic Apparel order (`NFJ-PO-2026-0918`) is still the single walkthrough you can follow by number.

| Seeded slice | What you will see |
|---|---|
| Inquiries 1–20 | Draft (unnumbered) |
| 21–30 | Open |
| 31–40 | Lost |
| 41–62 | Quoted (draft / sent) |
| 63–67 | Rejected quotes |
| 68–85 | Sales orders (draft then confirmed) |
| 86–95 | Job cards (planned, then on the floor) |
| 96–100 | Packed → challan issued → invoice issued |

This volume seeder runs **only in `local`**. Tests and production do not get it. Re-run with `php artisan db:seed --class=Database\\Seeders\\LocalProcessSeeder`. The 100 inquiries are skipped if they already exist; the catalogue (products, import, buying) still fills in if it was missing.

## First hour as implementer

Signed in as `admin@maheenlabel.test`:

1. Change your password.
2. Open **Configuration** (sidebar footer) → **Lists**. Confirm factory unit, departments, shifts, warehouses.
3. **Settings** — costing percentages, delivery tolerances, approval bands.
4. **Users** — real people, real roles. Do not keep sharing `password`.
5. Walk the seeded order through release → issue material → produce → QC → pack → challan → invoice so you have seen every gate once.

Then give each department its own login from [By role](12-by-role.md).
