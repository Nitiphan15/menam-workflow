# Wire Rod Dead Stock — approved requirement baseline

Status: design only. No application code or database change has been applied.

## Scope

- Source items: active ERP parts where `UPPER(TRIM(partnumber)) LIKE 'R%'`.
- Sites: `WIRE` and `PLUS`.
- Stock source: serialized on-hand lots from ERP PostgreSQL.
- Stock classification for RM / Wire Rod: age up to and including 180 days is `INCOMING`; age greater than 180 days is `DEAD`. Day 180 remains Incoming; day 181 becomes Dead.
- Missing purchase date: `UNKNOWN` / Data Issue. It is included in Total Stock but excluded from Incoming and Dead Stock.
- Slit Coil remains included. It is classified as its own Material Group and can be filtered.
- Material Groups: Slit Coil, Stainless Steel (part type 70), Steel (71), Grating (95), and Other.
- Currency assumption for MVP: ERP `serializeunits.unitcost` is THB. UI and exports must label this assumption until Accounting confirms it.

## Units

- Division D8 is displayed as `PCS`.
- Other Sales divisions are displayed as `KG`.
- ERP unit `03` means PCS and `01` means KG; the ERP code is retained for validation.
- D8 detection reuses FormDP Sales/Division rules: Sathit, Suthasinee, or D8 aliases map to D8.
- A division/unit disagreement is `UNIT_CONFLICT`, not silently converted.
- KG and PCS quantities are never summed together. THB values may be summed.

## Traceability

Primary path:

`Heat -> MFG BOM -> MFG -> Customer -> Sales`

Fallback path when MFG is unavailable:

`Purchase receipt / PO -> Customer -> Sales`

- MFG mapping is authoritative.
- If MFG and PO Sales disagree, status is `SALES_MAPPING_CONFLICT`; no Sales is selected automatically.
- Multiple MFGs or Customers with the same Sales are valid and remain `MAPPED`.
- More than one distinct Sales/Division for one lot is a Data Issue, not a valid allocation.
- Manual override is allowed for Planning, Procurement, and Admin. It requires Sales, reason, and remark; does not write to ERP; and is scoped to `Site + Serial + cycle_no`.

## Lifecycle and comparison

- Identity: `Site + Serial Number`.
- A returned lot opens a new `cycle_no`; old reviews and audit remain read-only history.
- Quantity difference greater than `0.01` is Changed; difference up to and including `0.01` is equal.
- Changed fields: quantity, unit cost (tolerance 0.01), purchase date, part/material group, stock status, or trace status.
- `Incoming -> Dead` is also marked `became_dead_stock` and is available as a KPI/filter.
- Compare statuses mirror FG Deadstock: Pending, Active, Changed, Cleared.
- Follow-up statuses mirror FG Deadstock: Not Followed Up, Waiting for Customer, and Delivery Scheduled.

## Refresh and retention

- Hourly at minute 00: refresh current state and compare.
- Daily at 08:03: daily snapshot.
- Last calendar day at 23:30: month-end snapshot.
- Manual refresh: read-only ERP/MFG query with a 30-second limit. On timeout, keep the latest cache and display `Refresh timeout`; never replace previous values with zero.
- Partial site failure must display `Partial data` and must not pretend combined totals are complete.
- Daily snapshots target 13 months of retention, but MVP has no automatic delete.
- Month-end snapshots, reviews, overrides, and audit logs are retained permanently.

## Security

- `WR`: view Dashboard, Review, and Export.
- Sales: edit only lots effectively mapped to their Sales scope.
- Conflicting Sales mapping: visible to related Sales, editable centrally by Planning/Procurement.
- Planning/Procurement: manage all Wire Rod Dead Stock lots.
- Admin: manage access, mappings, and revoke overrides.
- Wire Rod tables and permissions are separate from FG Deadstock.

## Database scripts

Run manually against the approved SQL Server database, in order, after review:

1. `01_create_wr_ds_tables.sql`
2. `02_create_wr_ds_permissions.sql`
3. `03_create_wr_ds_indexes.sql`
4. `04_seed_wr_ds_sales_access.sql`
5. `05_verify_wr_ds_schema.sql`

`06_rollback_wr_ds_schema.sql` is destructive and disabled unless its explicit confirmation flag is changed.
