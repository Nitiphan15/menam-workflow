# Workflow Rules

## Core Concepts

Workflow logic may include:

- workflow_steps
- wf_forms
- wf_form_authorize
- wf_action_history
- department_roles
- users
- departments
- parent_id
- level_no
- next_on_approve
- next_on_reject
- department_next_on_approve
- department_next_on_reject

## Approval Rules

- A user can approve only if they are resolved as an actual approver.
- Do not rely only on Blade button visibility.
- Controller or Service must check authorization.
- Every submit / approve / reject action should write action history.
- Missing approver should return a clear message.
- Unauthorized user should receive a clear error and must not change status.
- Prevent duplicate approval actions where possible.
- Do not skip steps unless business rules explicitly say so.

## Department Rules

- Be careful when interpreting department parent/child relationships.
- If using parent_id fallback, document the rule clearly.
- If using level_no, confirm which level is required for each step.
- Do not assume parent department is always the approver department.

## Recommended Service Flow

1. Load form.
2. Load current workflow step.
3. Resolve current user's role/department.
4. Resolve valid approvers.
5. Check whether current user is allowed.
6. Validate action.
7. Update status/current step inside transaction.
8. Write action history.
9. Return clear result.

## UI Rules

- Current step may be highlighted.
- Next step may be highlighted.
- Irrelevant steps may be greyed out.
- Buttons are useful for UX only; they are not security.
- Server-side authorization is required.

## Test Cases

- Submit happy path
- Approve happy path
- Reject happy path
- Unauthorized user cannot approve
- Missing approver returns clear message
- Duplicate approve is blocked
- Parent department fallback works as expected
- Rejected form moves to the correct step
- Action history is written