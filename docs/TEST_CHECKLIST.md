# Test Checklist

## General

- Page loads successfully.
- Filters work.
- Validation messages are clear.
- Unauthorized users cannot access restricted actions.
- Data is not duplicated.
- Date filters work correctly.
- Empty state is handled.
- Large dataset does not make the page unusable.

## Workflow

- Submit works.
- Approve works.
- Reject works.
- Unauthorized user cannot approve/reject.
- Missing approver message is clear.
- Action history is recorded.
- Current step updates correctly.
- Rejected step updates correctly.
- Duplicate action is blocked.

## Database

- Query returns expected rows.
- Cancelled/closed records are filtered where needed.
- Join does not create duplicate rows.
- SQL injection is not possible.
- Heavy query is reasonably optimized.

## UI

- Tables are readable.
- Buttons are clear.
- Modals scroll when needed.
- Save button is accessible.
- Thai labels are understandable.
- Dashboard numbers are readable.

## Export

- PDF layout fits page.
- Thai fonts display correctly.
- Excel does not auto-convert important numbers.
- Export filters match screen filters.

## Email

- Recipients are correct.
- CC/BCC are correct.
- Email body has correct date/revision/status.
- Attachments are correct.