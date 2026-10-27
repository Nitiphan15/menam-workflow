# Wire Rod Dead Stock — UAT acceptance plan

Production release requires Planning and Procurement sign-off on all items:

1. Total Stock by Site and Unit equals ERP 100%.
2. Dead Stock strictly older than 180 days equals an independent read-only SQL result 100%.
3. Sample at least 30 lots per Site: Part, Serial, Purchase Date, Qty, Cost, and Value.
4. Sample 20 lots per Material Group: MFG -> Customer -> Sales trace.
5. D8 displays PCS; every other confirmed Sales division displays KG.
6. Exercise Conflict, Unmapped, Unknown Age, Unit Conflict, Override Conflict, and lifecycle reopen.
7. Test WR viewer, scoped Sales editor, Planning/Procurement manager, and Admin.
8. Screen, Excel, and PDF return identical rows and totals for identical filters.
9. Force a source timeout; last successful values remain and no total becomes zero.
10. Planning and Procurement provide recorded acceptance before Production.

Additional safeguards:

- Day 180 is Incoming; day 181 is Dead.
- KG and PCS never share one quantity total.
- One lot with multiple MFGs but one Sales is not a conflict.
- MFG/PO Sales disagreement is not auto-resolved.
- A revoked override restores source-derived mapping and leaves complete audit history.
- A reopened serial starts a new cycle with no copied current review.
