# Accounting Cost Center Data Dictionary

This note documents the inferred PostgreSQL relationships for the ERP accounting
and cost center report. The ERP schema is `public` and does not expose reliable
foreign key constraints, so the relationships below are inferred from column
names, report behavior, and accounting transaction patterns.

## Core Tables

### `acc_trans`

Accounting transaction line table.

| Column | Meaning |
| --- | --- |
| `id` | Primary key of the accounting line. |
| `trans_id` | Document/header id. It can point to AP, AR, GL, cash/payment, or return headers depending on document type. |
| `chart_id` | Fallback account id, normally maps to `chart.id`. |
| `amount` | Accounting amount. |
| `transdate` | Accounting transaction date. |
| `source` | Description/source text. Often used as fallback item text. |
| `class_id` | Fallback class id. In this ERP it is often `-1`, so do not use it as the main cost center source. |

### `chart`

Chart of accounts.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `accno` | Account number. |
| `description` | Account name. |
| `charttype` | `A` account, `H` heading. |
| `category` | `A` Asset, `L` Liability, `Q` Equity, `I` Income, `E` Expense. |
| `link` | ERP module link such as `AP_amount`, `AR_paid`, `IC_cogs`, `AP_tax`, `AR_tax`. |
| `parent_id`, `active`, `notes` | Account hierarchy/status metadata. |

### `classinfo`

Class/cost center master.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `classnumber` | Cost center code, for example `PD14`, `SL01`, `RD01`. |
| `description` | Cost center name, for example `Welding and Fabrication`, `EXPORT`, `RD`. |

### `ccalloc`

Cost center allocation table.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `acc_trans_id` | Accounting line id. Maps to `acc_trans.id`. |
| `trans_id` | Document/header id. Usually same accounting document id as `acc_trans.trans_id`. |
| `chart_id` | Allocated account id. Maps to `chart.id`. |
| `class_id` | Allocated class/cost center id. Maps to `classinfo.id`. |
| `amount` | Allocated amount. This is the report amount source. |

Important rule: use `ccalloc` as the primary class/cost center source for this
report. Do not use `acc_trans.class_id` as the main class source.

Fallback rule discovered from source report: some AP documents have
`acc_trans.class_id = -1` and no matching `ccalloc`, while the source report
still includes the cost center from `invoice.class_id`. In that case, use
`invoice.class_id`, `invoice.chart_id`, and `ABS(invoice.qty) * ABS(invoice.sellprice)`
or `ABS(invoice.unitcost)` as an invoice-line fallback. Guard this fallback with
an anti-join against existing `ccalloc`/accounting allocation rows so the same
document is not counted twice.

## Document Tables

### `ap`

Accounts payable header.

Relationship:

- `acc_trans.trans_id = ap.id`
- `invoice.trans_id = ap.id`

Important columns:

- `id`, `transdate`, `apnumber`, `invnumber`, `ordnumber`
- `amount`, `netamount`, `paid`, `datepaid`
- `notes`, `f1` to `f5`

### `ar`

Accounts receivable header.

Relationship:

- `acc_trans.trans_id = ar.id`
- `invoice.trans_id = ar.id`

Important columns:

- `id`, `customer_id`, `transdate`, `arnumber`, `invnumber`, `ordnumber`
- `amount`, `netamount`, `paid`, `datepaid`
- `notes`, `notes1` to `notes5`, `f1` to `f5`

### `gl`

General ledger header.

Relationship:

- `acc_trans.trans_id = gl.id`

Important columns:

- `id`, `transdate`, `reference`, `notes`

### `invoice`

Document detail line table.

Relationship:

- `invoice.trans_id` maps to AP/AR/GL/return document id depending on source.
- A document can have many `invoice` rows. Never limit to one line when building
  an item detail report.

Important columns:

- `id`, `trans_id`, `parts_id`, `description`
- `qty`, `allocated`, `sellprice`, `unitcost`
- `chart_id`, `class_id`, `tax_chart_id`, `tax_amount`
- `return_id`, `ref1`, `ref2`, `f1` to `f5`

Additional columns present in the live schema and useful for reconciliation:

- `vatsellprice`, `fxsellprice`
- `discount`, `disc1`, `disc2`, `disc3`
- `assemblyitem`, `faitem`, `unit`
- `project_id`, `addcost`, `deliverydate`

Important rule: invoice detail can be either a pure item detail or the only
place where the class/account split exists. For AP expense report matching, the
class source precedence is:

1. `ccalloc.class_id`
2. valid `acc_trans.class_id`
3. `invoice.class_id` fallback when no allocation exists for the same
   document/account/class

The invoice account source precedence is:

1. `ccalloc.chart_id`
2. `acc_trans.chart_id`
3. `invoice.chart_id` fallback when no allocation exists

### `parts`

Item master.

Relationship:

- `invoice.parts_id = parts.id`
- `returnitems.parts_id = parts.id`
- `orderitems.parts_id = parts.id`
- `pritems.parts_id = parts.id`

Important columns:

- `id`, `partnumber`, `description`
- `unit`, `purchase_unit`, `sale_unit`
- `averagecost`, `lastcost`, `stdcost`
- `inventory_accno_id`, `income_accno_id`, `expense_accno_id`
- `partstype_id`, `partscategory_id`, `partsgroup_id`
- `f1` to `f5`, `desc1` to `desc5`

Use `parts.description` only as a fallback or supporting item name. The report
source often uses `invoice.description` as the item text.

### `cashtrans`

Payment/receipt bridge.

Relationship:

- `acc_trans.trans_id = cashtrans.trans_id`
- `cashtrans.ap_ar_id = ap.id` or `ar.id`

Important columns:

- `id`, `trans_id`, `ap_ar_id`, `amount`, `transdate`

### `return`

Return / credit note / EC / CN header.

Relationship:

- `acc_trans.trans_id = return.id`
- `return.invnumber = ar.invnumber`
- Do not rely only on `return.ap_ar_id`; it can be blank.

Important columns:

- `id`, `returnnumber`, `invnumber`, `amount`, `netamount`, `ap_ar_id`, `transdate`

### `returnitems`

Return / credit note line detail.

Relationship:

- `returnitems.trans_id = return.id`
- `returnitems.invoice_id = invoice.id` when the return references an original
  invoice line
- `returnitems.chart_id = chart.id`
- `returnitems.class_id = classinfo.id`

Important columns:

- `id`, `trans_id`, `parts_id`, `description`
- `qty`, `allocated`, `sellprice`, `unitcost`
- `project_id`, `class_id`, `chart_id`
- `tax_chart_id`, `tax_amount`, `returndate`, `invoice_id`
- `ref1`, `ref2`, `f1` to `f5`

This table is required if the source report includes credit notes/returns at
item detail level. Header-only `return` rows are not enough for item/class
reconciliation.

### `cashout`

Cash payment / payment voucher header.

Relationship:

- `acc_trans.trans_id = cashout.id`
- `ccalloc.acc_trans_id = acc_trans.id` for class allocation

Important columns:

- `id`, `vouchernumber`, `description`, `transdate`, `amount`
- `chart_id`, `wht`, `wht_chart_id`, `wht_desc_id`
- `curr`, `exchangerate_id`, `notes`, `name`
- `vendor_id`, `employee_id`

The current report supports `cashout` through `acc_trans` and `ccalloc`.

### `cashin`

Cash receipt header.

Relationship:

- `acc_trans.trans_id = cashin.id`
- `ccalloc.acc_trans_id = acc_trans.id` for class allocation

Important columns:

- `id`, `vouchernumber`, `description`, `transdate`, `amount`
- `chart_id`, `wht`, `wht_chart_id`, `wht_desc_id`
- `curr`, `exchangerate_id`, `notes`, `name`
- `customer_id`, `employee_id`

This is not currently included in the AP expense report path. Add it only if the
source report is expected to include cash receipts or non-AP income-side
transactions.

### `receipt` and `receipttrans`

Receipt header and AR receipt bridge.

Relationship:

- `receipttrans.trans_id = receipt.id`
- `receipttrans.ap_ar_id = ar.id`
- `acc_trans.trans_id = receipt.id` when receipt accounting lines exist

Important columns:

- `receipt.id`, `receipt.receiptnumber`, `receipt.customer_id`,
  `receipt.transdate`, `receipt.amount`, `receipt.netamount`, `receipt.notes`
- `receipttrans.trans_id`, `receipttrans.ap_ar_id`, `receipttrans.amount`

This is not currently included in the AP expense report path.

### `apnotice`, `arnotice`, and `noticetrans`

Notice documents and bridge rows.

Relationship:

- `noticetrans.trans_id` points to the notice/payment transaction
- `noticetrans.ap_ar_id` points to AP/AR document id

Important columns:

- `apnotice.noticenumber`, `apnotice.vendor_id`, `apnotice.transdate`,
  `apnotice.amount`, `apnotice.notes`
- `arnotice.noticenumber`, `arnotice.customer_id`, `arnotice.transdate`,
  `arnotice.amount`, `arnotice.notes`
- `noticetrans.trans_id`, `noticetrans.ap_ar_id`

These are not currently included. Add them if notices appear in the ERP source
report.

### `addcost`

Additional cost lines tied to a source transaction.

Relationship:

- `addcost.trans_id` points to the document/header id
- `addcost.chart_id = chart.id`
- `addcost.class_id = classinfo.id`

Important columns:

- `id`, `trans_id`, `chart_id`, `amount`, `description`
- `project_id`, `class_id`

This table is not currently included. It is a candidate source for import duty,
shipping, or other add-on purchase costs if those amounts do not reconcile from
`acc_trans`, `ccalloc`, or `invoice`.

### `employee`

Employee/requester master.

Relationship:

- `ap.requester_id = employee.id`
- `ap.employee_id = employee.id`
- `ar.requester_id = employee.id`
- `gl.employee_id = employee.id`
- `cashout.employee_id = employee.id`

Important columns:

- `id`, `login`, `name`, `class_id`, `employed`

Useful for requester/user columns and debugging the source of a transaction.

### `vendor` and `customer`

Vendor/customer masters.

Relationship:

- `ap.vendor_id = vendor.id`
- `cashout.vendor_id = vendor.id`
- `ar.customer_id = customer.id`
- `cashin.customer_id = customer.id`

Important columns:

- `vendor.id`, `vendor.vendornumber`, `vendor.name`, `vendor.ap_account_id`
- `customer.id`, `customer.customernumber`, `customer.name`,
  `customer.ar_account_id`

These are not required by the current Excel output columns, but they are useful
for audit/reconciliation and vendor/customer filtering.

## Candidate Tables Not Yet Used

The live schema includes additional tables that can affect accounting/cost
center reporting depending on the source report scope:

- `adjustentry`, `adjustentrytrans`: accounting adjustment documents. Use if ERP
  report includes adjustment entries outside normal GL/AP/AR.
- `deposit`, `depositused`, `dusedinfo`: deposit accounting and usage.
- `dm`, `dmitems`, `predm`, `predmitems`: delivery/debit memo style documents.
- `contract`, `contractitems`, `contractdue`, `contractduepayment`,
  `contractduereceive`: contract-related accounting.
- `fixedasset`, `depreciation`: fixed asset/depreciation expense source.
- `partsmvmt`, `partscostadj`, `workorderusage`, `workorderreceive`: inventory,
  cost adjustment, and work order cost sources.

Do not add these blindly to the AP expense report. Add them only when a source
Excel row is proven to originate from that table family.

## Parent Type Mapping

Use this precedence when identifying a transaction parent:

```sql
CASE
    WHEN gl.id IS NOT NULL THEN 'GL'
    WHEN ap.id IS NOT NULL THEN 'AP'
    WHEN ar.id IS NOT NULL THEN 'AR'
    WHEN cashtrans.trans_id IS NOT NULL THEN 'PAYMENT/CASHTRANS'
    WHEN rt.id IS NOT NULL THEN 'RETURN'
    ELSE 'UNKNOWN'
END
```

## Cost Center Report Rules

- Date filter should use a half-open range: `transdate >= date_from` and
  `transdate < date_to`. For April 2026, use `2026-04-01` to `2026-05-01`.
- Optional class filter should match `classinfo.classnumber` exactly, for example
  `PD14`.
- Do not filter `chart.category = 'E'`; the source report can include required
  accounts outside expense category.
- Use `ABS(ccalloc.amount)` as the primary class/report amount.
- If no `ccalloc` or valid accounting class exists for the same AP
  document/account/class, use invoice-line fallback amount:
  `ABS(invoice.qty) * ABS(COALESCE(invoice.sellprice, invoice.unitcost, 0))`.
- Use `invoice.qty`, `invoice.sellprice`, and `invoice.description` for detail
  rows when matched invoice lines exist.
- If invoice detail is missing, fall back to `acc_trans.source` and
  `ABS(ccalloc.amount)`.
- Account label is `chart.accno || ' ' || chart.description`.
- The Excel column `ข้อมูลเพิ่มเติม 1` is the class description.
- Detail rows should be sorted by class, account number, date, document, and
  invoice line id.
- Running balance is cumulative within each class in the same sort order.
- Subtotal rows are presentation rows after each account group, not accounting
  source rows.

## Encoding Notes

Some old ERP text can be stored as WIN874/TIS-620/SQL_ASCII bytes. Columns most
likely to trigger decoding issues are:

- `acc_trans.source`
- `chart.description`
- `classinfo.description`
- `invoice.description`, `invoice.ref1`, `invoice.ref2`, `invoice.f1` to `invoice.f5`
- `returnitems.description`, `returnitems.ref1`, `returnitems.ref2`
- `addcost.description`
- `cashin.description`, `cashin.notes`, `cashout.description`, `cashout.notes`
- `ap.notes`, `ar.notes`, `gl.reference`, `gl.notes`
- `vendor.name`, `customer.name`, `employee.name`

For pgAdmin/manual SQL, use `public.erp_safe_text(text)` from
`database/sql/cost_center_report_pd14_april.sql`. In Laravel/PHP, normalize text
after fetching with an encoding-safe helper before exporting.
