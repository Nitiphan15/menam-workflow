# FormWR current reserved raw material

## Rules

- Read the latest ERP data on each page/export request; no daily snapshot or scheduled delivery.
- Reservation starts with `workorder.qty` in KG, as confirmed by the user. Do not add a loss allowance. `workorderbom.qty` is not populated in the current databases.
- Remaining reservation = `max(MFG quantity - sum(workordermatusage.qty), 0)`. Aggregate issues per work order and material before joining. Do not sum production-step `workorderusage` rows.
- Include open, non-suspended work orders with a positive quantity at 4-decimal KG precision, including unapproved MFG waiting for material. Closed, suspended, fully issued and overissued orders do not reserve stock.
- An MFG issued to at least `config('formwr.reservation_complete_ratio')` (default 95%) of its quantity counts as fully issued, so its small remainder does not reserve stock. The 95% default is pending planner confirmation.
- One distinct RM Item/Heat allocation per work order. Duplicate identical BOM rows are deduplicated. A work order with multiple distinct allocations is left out of the totals and listed as a warning on the page and summary sheet, instead of duplicating its MFG quantity or failing the whole report.
- Net stock = movement balance minus remaining reservation; negative values remain visible.
- Keep companies separate in Heat/MFG details. The existing Item summary combines the selected companies.
- Empty or `-` BOM Heat goes into the waiting-material bucket, with zero stock and negative net. Never allocate it to an arbitrary Heat.

## Sources and reconciliation

| Data | WIRE / PLUS connection | Source |
| --- | --- | --- |
| Current Item stock | `pgsqlw` / `pgsqlp` | `SUM(partsmvmt.qty)` |
| Heat stock / receipt dates | Same ERP connections | `serializeunits.onhand = true`, `qty`, `purchasedate`; Heat is the serial prefix before the first hyphen |
| MFG reservation and Heat | `pgsqlmfgw` / `pgsqlmfgp` | Open `workorder` + `workorderbom` + `parts` |
| Issued material | Same MFG connections | `workordermatusage`, grouped by workorder/material |
| Product, customer, sales order | Same MFG connections | `parts`, `workorder.customer`, `workorder.ordnumber` |

The difference between Item movements and serial balances is an explicit reconciliation row, not a fabricated Heat. During validation, 39 of 651 company/Item records had a difference. Heat-level availability needs review for those records. These are existing ERP differences, not adjustments written by this feature.

Company and Item filters apply to stock/reservations. Year, PO and vendor filters apply only to incoming PO data; they must not hide older open MFG reservations or limit current stock. KG values are never multiplied by the existing PO scale of 1000.

## UI and Excel

- Existing balance table gains remaining reservation and net columns; click Item for company/Heat/receipt detail, then Heat for MFG detail.
- MFG detail includes order/due dates, sales order, customer, product, state, planned, issued and remaining reservation quantities.
- Existing three Excel sheets remain in their original order; `Stock by Heat` and `MFG Reservations` are appended. The balance sheet gains reservation and net columns. New detail sheets preserve identifiers such as `+W...` as text.
- Retrieval time is shown in Bangkok time. It is retrieval time, not a historical closing date. Separate live requests may differ when ERP data changes.

## Weekly stock and shipment summary

- The Wirerod Incoming page shows the same figures as the `Stock & Shipment Summary` sheet, in MT, filtered by company and Item only. The company-wide target and over-stock rows are hidden on the page when an Item filter is set; the Excel sheet is unchanged.
- Weeks run Saturday-Friday. The first week of a month keeps its days from the previous month (27 Jun - 3 Jul belongs to July), matching the PU-30 report.
- Data is live on each request. There is no weekly snapshot or email. To be arrived uses the PO required date (`oe.reqdate`), which may differ from the ETA in the manual PU-30 report.
- A failure in this summary is logged and shown in its card; the rest of the page keeps working.
- Not included: PU-30 shipment detail (L/C, Invoice, ETD/ETA, shipping documents).

## Verification and release

- Focused unit tests cover partial/full/over issue, unassigned shortage, company separation, multiple receipt dates, movement reconciliation, ambiguous allocation rejection and spreadsheet text typing.
- Read-only live checks cover both companies separately/together, empty filters, all Item/Heat totals, and all five Excel sheets. Summary, Heat and MFG reservation totals reconcile with the page.
- Example checked: `RER312XXX0550`: stock 27,236 KG, remaining reservation 4,582 KG, net 22,654 KG.
- Blade page and detail rendering passed using an isolated preview layout. Browser interaction verification remains pending because the in-app browser could not attach.
- Review/merge the scoped branch into `ai/menam-workflow`. No migration is required. Deploy normally and clear compiled views if the deployment does not already do so.
- Staging check: filter Item `RER312XXX0550`, open Item then Heat, inspect partial issues, switch WIRE/PLUS, try an MFG waiting for material, and compare Export totals. Confirm negative net displays in red.
- Worktree changes are not automatically served by the main Apache checkout.
