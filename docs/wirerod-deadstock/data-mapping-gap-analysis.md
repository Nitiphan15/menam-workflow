# Wire Rod Dead Stock — data mapping and gaps

## Mapping

| Business field | Source | Notes |
| --- | --- | --- |
| Site | connection context | WIRE=`pgsqlw`, PLUS=`pgsqlp` |
| Part | `parts.id`, `parts.partnumber` | Scope is active `R%` |
| Description | `parts.description` | Also identifies Slit Coil |
| Material Group | description + `parts.partstype_id` | Precedence defined in technical design |
| Serial/Lot | `serializeunits.id`, `serialnumber` | Snapshot identity uses Site + Serial + cycle |
| Received Date | `serializeunits.purchasedate` | Missing becomes UNKNOWN |
| Current Qty | `serializeunits.qty` where on-hand | Stored as DECIMAL(18,4) |
| ERP Unit | `parts.unit`/source unit code | `01` KG, `03` PCS |
| Cost | `serializeunits.unitcost` | Assumed THB pending Accounting confirmation |
| Value | Qty x Unit Cost | Separate quantities, common THB value |
| Heat | serial convention / MFG BOM | Type-dependent; retain source evidence |
| Grade | `workorderbom.fgrade` | May be absent before MFG allocation |
| Diameter/Size | `workorderbom.fsize` | May be absent before MFG allocation |
| PO | purchase invoice -> receipt -> PO line/header | Fallback trace source |
| MFG/SO | `workorderbom` -> `workorder` | Primary trace source |
| Customer | MFG Customer; PO fallback | Multiple Customers under one Sales are valid |
| Sales | Customer salesperson -> employee | FormDP division mapping reused |
| Review | SQL Server `wr_ds_item_reviews` | One per lifecycle |

## Confirmed gaps and controls

1. Grade/size may not exist for unallocated stock. Display Unknown; do not parse unapproved text rules.
2. One PO line may create multiple lots. Do not claim PO-line ordered quantity belongs wholly to one lot.
3. Used quantity needs validated movement semantics. MVP leaves it nullable rather than inventing `purchased - current`.
4. A Heat may map to several MFGs. Several MFGs with one Sales are valid; several Sales are conflict.
5. ERP and MFG are separate databases. Join in the application using bounded keys and chunked read-only queries.
6. Current local application database does not prove production SQL Server schema. Scripts avoid user foreign keys and retain actor ID/name snapshots.
7. Currency has no lot-level currency column. THB is an explicit provisional assumption.
8. Snapshot deletion is not automated. A read-only retention report precedes any later approved deletion.
