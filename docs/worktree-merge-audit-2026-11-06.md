# Worktree merge audit — 2026-11-06

Target branch: `ai/menam-workflow`

Remote and local target after approved merges: `9c046916`

## Automatically merged and pushed

| Branch | Result | Verification |
|---|---|---|
| `codex/formfc-e-piece-kg-display` | Merged as `1ad10e48` | Production endpoint and Excel verified; PHP lint passed; focused test passed 3 tests / 11 assertions |
| `codex/stock-withdrawal-daily-mail` | No-content merge as `bf5c0632` | Four runtime files matched Production exactly; focused test passed 2 tests / 2 assertions |
| `codex/formfc-approval-sales-remark` | No-content merge as `9c046916` | Virtual merge and real merge changed no target files; main already contained the content |
| `codex/formpo-step3-digest-production-controls` | Merged concurrently through PR #28 as `84d024ee` | Focused `SendPoStep3PendingDigestTest` passed before the remote merge was detected |

Backup branches retained:

- `codex/backup-ai-before-formfc-so-kg-20261106`
- `codex/backup-ai-before-stock-withdrawal-mail-20261106`
- `codex/backup-ai-before-no-content-merges-20261106`

## Already covered; do not merge the whole historical branch

| Branch | Evidence | Reason not to merge whole branch |
|---|---|---|
| `codex/tracking-weight-tolerance` | Support class, test, detail view, and help partial are already identical in main; the runtime views are also present in Production | Branch contains many unrelated historical commits and virtual merge conflicts |

## Changes cleanly in virtual merge; awaiting approval

### Branch-focused tests passed

- `codex/formdp-planner-department-access` — `InquiryByShipDateExportTest` passed 4 tests / 12 assertions.
- `codex/formdp-tracking-quick-access` — `ProductionProcessGroupTest` passed.
- `codex/invoice-mfg-profit` — `InvoiceMfgProfitServiceTest` passed.
- `codex/po-step3-recovery-mail` — `PoStep3RecoveryServiceTest` passed.
- `codex/sidebar-essential-menus` — `SidebarEssentialMenusTest` passed.

### No branch-specific test or dependency unavailable

- `codex/formpo-closed-mail-remarks-font` — no changed test found.
- `codex/request-form-sso-ready-local` — no changed test found.
- `codex/group-form-vcl-logistics` — test exists, but this worktree has no `vendor/`.
- `codex/group-formdp-routing-stations` — test exists, but this worktree has no `vendor/`.
- `codex/group-formfc-period-order-balance` — tests exist, but this worktree has no `vendor/`.
- `codex/group-formpo-approver-pdf-tools` — tests exist, but this worktree has no `vendor/`.
- `codex/group-invoice-packing-document` — test exists, but this worktree has no `vendor/`.
- `codex/group-sidebar-essential-menus` — test exists, but this worktree has no `vendor/`.

These branches change target files and were not proven present in Production. They require explicit merge approval after a focused diff review.

## Virtual merge conflicts; do not auto-merge

- `claude/distracted-shamir-da6d0b`
- `codex/admin-password-reset`
- `codex/adminweb-password-min4`
- `codex/bulk-user-import`
- `codex/department-organization`
- `codex/formdp-reconfirm-date-changes`
- `codex/formfc-division-export-detail`
- `codex/formfc-four-month-everywhere`
- `codex/formfc-manual-save-validation`
- `codex/formfc-planner-sales-view`
- `codex/formfc-routing-import`
- `codex/formgp-folder-clean`
- `codex/formgp-latest-step-comparison`
- `codex/formpkg-packaging-shortage-mail`
- `codex/formpo-closed-mail-remarks`
- `codex/formpo-mail-sender-name`
- `codex/formpo-reopen-approval-clean`
- `codex/formwos-otd-dashboard`
- `codex/integrate-formpo-pov-mail`
- `codex/merge-vc-main`
- `codex/owasp-foundation-hardening`
- `codex/po-pour-pdf-visibility`
- `codex/stock-withdrawal-alert`
- `codex/vc-multi-year-month-filter`
- `codex/wos-delivery-volume-filter`

## Dirty worktrees requiring preservation before rebase or cleanup

- `codex/formfc-manual-save-validation` — untracked `.deploy/`.
- `claude/distracted-shamir-da6d0b` — untracked `.claude/settings.local.json`.
- `codex/integrate-formpo-pov-mail` — eight tracked changes, including unresolved conflicts in `PoController.php` and `config/menu.php`.
- `codex/formwos-otd-dashboard` — five tracked changes and one untracked config file.
- `codex/vc-multi-year-month-filter` — two untracked worktree helper files.

## Known unrelated target-branch test issue

Running all FormFC unit tests on the target produced two errors in `FormFcPlannerSalesViewTest`. Both errors are caused by the test referencing the absent `resources/views/formfc/division_forecast_planning.blade.php`. The new SO kilogram test still passed independently.

## Recommended approval order

1. `codex/formdp-planner-department-access` and `codex/formdp-tracking-quick-access` — clean virtual merges with passing focused tests.
2. `codex/invoice-mfg-profit` and `codex/po-step3-recovery-mail` — clean virtual merges with passing focused tests.
3. Dependency-missing worktrees — provide/reuse dependencies in an isolated test checkout, then retest.
4. Conflict branches — review feature intent and rebase one at a time; never resolve by choosing an entire side.
