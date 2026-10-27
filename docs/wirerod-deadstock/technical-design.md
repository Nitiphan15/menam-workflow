# Wire Rod Dead Stock — technical design

## 1. User interface

Wire Rod navigation retains Incoming and adds FG-Deadstock-style pages:

- Incoming / Stock — existing behavior remains unchanged.
- Dead Stock Dashboard — KPIs, trends, group breakdowns, and drill-down links.
- Dead Stock Review — filterable lot table, compare state, follow-up workflow, override, and audit history.

Shared filters preserved while switching pages: Site, Item, and Material Group. Incoming-only filters remain Vendor, PO, and Required Year. Dead-Stock-only filters include received date, age, stock state, Grade, Diameter, Heat, Customer, Sales Division, trace state, and Became Dead.

FG Deadstock visual/status patterns should be reused, but its due-date query, FF/SS filters, and FG tables must not be reused.

## 2. Read model

### Current state

`wr_ds_current_items` contains one current on-hand row for each `Site + Serial`. The hourly sync upserts current values only after the complete source result has passed validation. A failed or timed-out source never zeroes or clears the last successful state.

### Lifecycle

`wr_ds_item_lifecycles` owns stable review identity. When a current serial disappears or becomes off-hand, its cycle is cleared. If it returns, the service creates `cycle_no + 1`; prior review and override are not copied.

### Immutable history

- `wr_ds_snapshots`: daily, month-end, and manual snapshot headers.
- `wr_ds_snapshot_items`: immutable lot values at snapshot time.
- `wr_ds_compare_logs`: each comparison result and before/after details.
- `wr_ds_item_review_logs`: every review edit.
- `wr_ds_sales_overrides`: override and revoke history.

## 3. Data acquisition

ERP queries are read-only and scoped per site:

- Stock lot: `serializeunits` joined to `parts` and `partstype`.
- PO: `purchase_invoice_id -> receiveitems -> orderitems -> oe`.
- MFG trace for Wire Rod: serial/heat -> MFG `workorderbom.fheatno` -> `workorder`.
- Customer/Sales: MFG Customer first; PO Customer fallback; `customer.saleperson_id -> employee`.
- Stock total reconciliation: serialized current quantity versus `SUM(partsmvmt.qty)`.

The two sites are isolated. A failure in one site produces Partial Data and retains that site's prior successful rows.

## 4. Classification

Material Group precedence:

1. Description starts with `Slit Coil` (case-insensitive) -> Slit Coil.
2. Part type 70 -> Stainless Steel.
3. Part type 71 -> Steel.
4. Part type 95 -> Grating.
5. Other `R%` -> Other.

Stock state:

- Missing purchase date -> UNKNOWN.
- `as_of_date - purchase_date > 180` -> DEAD.
- Otherwise (`stock_age_days <= 180`) -> INCOMING.

`became_dead_at = purchase_date + 181 days`.

## 5. Unit policy

Business unit derives from effective Sales Division:

- D8 -> PCS.
- Other known divisions -> KG.
- Unmapped Sales -> ERP unit fallback (`03` PCS, `01` KG).
- Any disagreement is a Data Issue and retains both source and derived values.

Dashboards expose separate KG and PCS quantities. Value uses current quantity multiplied by unit cost and is assumed THB for MVP.

## 6. Mapping and override

Effective Sales precedence:

1. Active manual override for the current lifecycle.
2. MFG Customer/Sales mapping.
3. PO Customer/Sales fallback.
4. Unmapped.

An override requires reason and remark, is immediately effective, and is never written back to ERP. Scheduled refresh retains it. If the ERP mapping later differs, the UI raises `OVERRIDE_CONFLICT`. Admin may revoke with a mandatory reason.

## 7. Compare rules

The item is Changed if any rule is true:

- `ABS(current_qty - previous_qty) > 0.01`.
- `ABS(current_unitcost - previous_unitcost) > 0.01`.
- Purchase date changed.
- Part number or Material Group changed.
- Stock state changed.
- Trace state changed.

MFG, SO, Customer, and Sales list changes are stored in change details. They do not duplicate quantity in aggregates.

## 8. Failure behavior

- Manual query timeout: 30 seconds; show last successful cache plus warning.
- Hourly source failure: keep previous site state and log failure outside business totals.
- Partial source: show per-site freshness and exclude failed-site data from any total labelled Complete.
- Missing purchase date/unit/Sales: Data Issue, never silently classified as Incoming.
- No source result must be treated as zero until the source returned successfully and completeness checks passed.

## 9. Implementation phases

1. Review and manually run additive SQL Server scripts.
2. Build read-only ERP repository and classification tests.
3. Build lifecycle/current-state sync with safe complete-result swap.
4. Build daily/month-end snapshots and hourly comparison.
5. Build FG-parity Dashboard and Review pages.
6. Build scoped authorization, review, override, and audit.
7. Build Excel/PDF using the exact same filter object as the UI.
8. Execute UAT and obtain Planning/Procurement sign-off.

No production deployment or scheduler activation belongs to the database-script step.
