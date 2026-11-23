# Branch cleanup — 2026-11-19

สำรวจ branch ทั้งหมดเทียบกับ `origin/ai/menam-workflow` (หลัง merge #33–#49) แบบอ่านอย่างเดียว
**ยังไม่ได้ลบอะไร** — รอผู้ใช้ยืนยันทีละกลุ่ม

ลำดับที่แนะนำ: (1) ลบกลุ่ม A (2) ตัดสินใจกลุ่ม C ทีละอัน (3) tag `archive/*` ให้กลุ่ม B แล้วลบ branch (4) จัดการ worktree ของ Codex ตามข้อควรระวังท้ายไฟล์

| กลุ่ม | ความหมาย | ทำอะไร |
|---|---|---|
| **A** | merged แล้ว / patch อยู่ใน base แล้ว / โค้ดที่ branch เพิ่ม ≥95% อยู่ใน base แล้ว | ลบได้ (local; remote ถ้ามี) |
| **B** | branch ซ้อนกันยาว ๆ จากช่วง ก.ค.–ต.ค. — งานส่วนใหญ่เข้า base/prod แล้วด้วยทางอื่น ที่เหลือคือ (1) งานกลุ่ม C ที่ติดมา (2) SQL ครั้งเดียวที่รันบน prod ไปแล้ว (3) test บางตัว / เวอร์ชันเก่าของไฟล์ | **เก็บเป็น tag `archive/<ชื่อ>` แล้วค่อยลบ branch** — commit ไม่หาย ย้อนดูได้ตลอด |
| **C** | งานจริงที่ยังไม่เคย merge และ (ส่วนใหญ่) ไม่เคยขึ้น prod | ตัดสินใจทีละอัน: เปิด PR / ปิดทิ้ง |

## กลุ่ม C — งานที่ต้องตัดสินใจ (11)

| branch | commit ล่าสุด | remote | PR | ใน base แล้ว | worktree |
|---|---|---|---|---|---|
| `codex/deadstock-current-month-summary` — Deadstock current-month summary sheet | 2026-07-14 | - |  | 50% ของ 222 บรรทัด |  |
| `codex/owasp-foundation-hardening` — Security hardening + MFG defect analysis (ไม่เคยขึ้น prod) | 2026-07-30 | - |  | 6% ของ 916 บรรทัด | ⚠️ worktrees |
| `codex/po-step3-recovery-mail` — PR #11 – PO step 3 fail-closed + recovery page | 2026-10-23 | มี | #11 OPEN | 0% ของ 626 บรรทัด | ⚠️ po-step3-recovery-mail |
| `codex/group-form-vcl-logistics` — Variable cost – logistics (VCL) (ไม่เคยขึ้น prod) | 2026-10-30 | - |  | 4% ของ 199 บรรทัด | ⚠️ worktrees |
| `codex/group-formfc-period-order-balance` — FormFC period/order-balance tests | 2026-10-30 | - |  | 31% ของ 173 บรรทัด | ⚠️ worktrees |
| `codex/group-invoice-packing-document` — Invoice / packing document (ไม่เคยขึ้น prod) | 2026-10-30 | - |  | 0% ของ 747 บรรทัด | ⚠️ worktrees |
| `codex/request-form-sso-ready-local` — Request Form SSO – ตัวอย่าง test env | 2026-11-05 | - |  | 0% ของ 11 บรรทัด | ⚠️ codex-worktrees |
| `codex/formpo-step3-digest-bcc-itp` — PO step 3 digest CC/BCC list | 2026-11-09 | - |  | 8% ของ 48 บรรทัด | ⚠️ .worktrees |
| `codex/pp-production-balance` — Production balance alert + history (ไม่เคยขึ้น prod) | 2026-11-16 | - |  | 1% ของ 543 บรรทัด | ⚠️ pp-production-balance |
| `codex/error-logging` — PR #35 – error log viewer / Telegram alerts (ต้อง rebase) | 2026-11-17 | มี | #35 OPEN | 1% ของ 1013 บรรทัด | ⚠️ .worktrees |
| `codex/home-hide-request-form` — Home: ซ่อนกล่อง Request Form จนกว่าจะตั้งค่า | 2026-11-17 | - |  | 14% ของ 7 บรรทัด |  |

## กลุ่ม B — branch ซ้อนที่ถูกแทนแล้ว (67)

| branch | commit ล่าสุด | remote | PR | ใน base แล้ว | worktree |
|---|---|---|---|---|---|
| `claude/distracted-shamir-da6d0b` | 2026-07-13 | - |  | 81% ของ 464 บรรทัด | ⚠️ worktrees |
| `claude/epic-kirch-e3739a` | 2026-07-13 | - |  | 4% ของ 107 บรรทัด |  |
| `claude/recursing-elgamal-90a649` | 2026-07-13 | - |  | 23% ของ 536 บรรทัด |  |
| `claude/vibrant-matsumoto-fd3189` | 2026-07-13 | - |  | 5% ของ 188 บรรทัด |  |
| `codex/adminweb-password-min4` | 2026-07-15 | - |  | 67% ของ 3 บรรทัด | ⚠️ worktrees |
| `codex/formgp-workload-overlap` | 2026-07-15 | - |  | 78% ของ 258 บรรทัด |  |
| `codex/accounting` | 2026-07-24 | - |  | 26% ของ 758 บรรทัด |  |
| `codex/bulk-user-import` | 2026-07-24 | - |  | 43% ของ 879 บรรทัด | ⚠️ worktrees |
| `codex/department-organization` | 2026-07-24 | มี | #2 CLOSED | 42% ของ 866 บรรทัด | ⚠️ worktrees |
| `codex/formfc-routing-import` | 2026-07-24 | - |  | 8% ของ 980 บรรทัด | ⚠️ worktrees |
| `codex/deadstock-follow-up-status` | 2026-07-27 | - |  | 27% ของ 4939 บรรทัด |  |
| `codex/formpo-reopen-approval` | 2026-07-27 | - |  | 27% ของ 4939 บรรทัด |  |
| `codex/formpo-step2-append-attachments-detail` | 2026-07-27 | - |  | 27% ของ 4939 บรรทัด |  |
| `codex/deadstock-sales-access-db` | 2026-08-03 | - |  | 28% ของ 5136 บรรทัด |  |
| `codex/formdp-sp001-piece` | 2026-08-03 | - |  | 28% ของ 5078 บรรทัด |  |
| `codex/formdp-tracking-quick-actions` | 2026-08-03 | - |  | 28% ของ 5136 บรรทัด |  |
| `codex/wos-delivery-volume-filter` | 2026-08-03 | - |  | 10% ของ 636 บรรทัด | ⚠️ worktrees |
| `codex/tracking-weight-tolerance` | 2026-08-05 | - |  | 30% ของ 5290 บรรทัด | ⚠️ worktrees |
| `codex/deadstock-performance-wording` | 2026-08-06 | - |  | 12% ของ 4111 บรรทัด |  |
| `codex/formfc-planning-multi-division-view` | 2026-08-06 | - |  | 12% ของ 4111 บรรทัด |  |
| `codex/formssc-guest-access` | 2026-08-06 | - |  | 14% ของ 4229 บรรทัด |  |
| `codex/tracking-reset-confirmation-on-postpone` | 2026-08-06 | - |  | 14% ของ 4229 บรรทัด |  |
| `codex/formwos-sales-summary-export-detail` | 2026-08-07 | - |  | 17% ของ 4551 บรรทัด |  |
| `codex/backup-ai-menam-workflow-20260810` | 2026-08-10 | - |  | 91% ของ 6789 บรรทัด |  |
| `codex/formgp-latest-step-comparison` | 2026-08-10 | - |  | 70% ของ 1924 บรรทัด | ⚠️ worktrees |
| `codex/formpo-department-approver-routing` | 2026-08-10 | - |  | 24% ของ 5440 บรรทัด |  |
| `codex/formpo-purchase-store-assistant-routing` | 2026-08-10 | - |  | 68% ของ 1881 บรรทัด |  |
| `codex/formpo-reopen-500-fix` | 2026-08-10 | - |  | 68% ของ 1827 บรรทัด |  |
| `codex/admin-user-signatures` | 2026-08-11 | - |  | 73% ของ 2232 บรรทัด |  |
| `codex/deadstock-division-edit-access` | 2026-08-11 | - |  | 74% ของ 2285 บรรทัด |  |
| `codex/formdp-tracking-route-stations` | 2026-08-11 | - |  | 73% ของ 2232 บรรทัด |  |
| `codex/formfc-four-month-everywhere` | 2026-08-13 | - |  | 65% ของ 2752 บรรทัด | ⚠️ .worktrees |
| `codex/formfc-sales-forecast-4-month-filters` | 2026-08-13 | - |  | 74% ของ 2852 บรรทัด |  |
| `codex/po-pour-pdf-visibility` | 2026-08-13 | - |  | 73% ของ 2455 บรรทัด | ⚠️ .worktrees |
| `codex/formpo-manager-attachments-department-tracking` | 2026-08-14 | - |  | 76% ของ 3115 บรรทัด |  |
| `codex/formpo-pov-readonly-detail` | 2026-08-14 | - |  | 76% ของ 3449 บรรทัด |  |
| `codex/fix-po-pdf-merge-after-tint` | 2026-08-24 | มี |  | 76% ของ 3478 บรรทัด |  |
| `codex/form-vcl-logistics` | 2026-08-24 | - |  | 77% ของ 3668 บรรทัด |  |
| `codex/formpo-mail-sender-name` | 2026-08-24 | - |  | 78% ของ 3673 บรรทัด | ⚠️ .worktrees |
| `codex/po-email-purchase-department` | 2026-08-24 | - |  | 77% ของ 3668 บรรทัด |  |
| `codex/formfc-division-export-detail` | 2026-08-25 | - |  | 77% ของ 3717 บรรทัด | ⚠️ .worktrees |
| `codex/erp-invoice-packing-document` | 2026-08-27 | - |  | 56% ของ 5198 บรรทัด |  |
| `codex/formwos-order-due-actual-delivery` | 2026-08-27 | - |  | 58% ของ 5295 บรรทัด |  |
| `codex/deadstock-mail-daily-scheduler` | 2026-09-07 | - |  | 60% ของ 5790 บรรทัด |  |
| `codex/die-damage-reports` | 2026-09-07 | - |  | 59% ของ 5657 บรรทัด |  |
| `codex/formwr-stock-modal` | 2026-09-07 | - |  | 59% ของ 5657 บรรทัด |  |
| `codex/po-manager-cancel` | 2026-09-07 | - |  | 60% ของ 5790 บรรทัด |  |
| `codex/po-pdf-mail-reliability` | 2026-09-07 | - |  | 60% ของ 5790 บรรทัด |  |
| `codex/po-rejected-pdf` | 2026-09-07 | - |  | 59% ของ 5657 บรรทัด |  |
| `codex/tracking-postpone-reset-note` | 2026-09-07 | - |  | 60% ของ 5790 บรรทัด |  |
| `codex/deadstock-purchase-date-filter` | 2026-09-12 | - |  | 60% ของ 5825 บรรทัด |  |
| `codex/deadstock-purchase-date-merge` | 2026-09-12 | - |  | 59% ของ 5541 บรรทัด |  |
| `codex/stock-withdrawal-10pct-completion` | 2026-09-12 | - |  | 60% ของ 5825 บรรทัด |  |
| `codex/formfc-planning-export` | 2026-09-15 | - |  | 59% ของ 5685 บรรทัด |  |
| `codex/formpkg-packaging-shortage-mail` | 2026-09-15 | - |  | 55% ของ 6246 บรรทัด | ⚠️ .worktrees |
| `codex/merge-vc-main` | 2026-09-22 | - |  | 61% ของ 5974 บรรทัด | ⚠️ .worktrees |
| `codex/vc-multi-year-month-filter` | 2026-09-22 | มี | #4 CLOSED | 61% ของ 5974 บรรทัด |  |
| `codex/formdp-confirm-planner-sheet` | 2026-09-30 | มี |  | 60% ของ 5837 บรรทัด |  |
| `codex/formpo-pov-mail-notifications` | 2026-10-01 | - |  | 62% ของ 6103 บรรทัด |  |
| `codex/formdp-planner-department-access` | 2026-10-02 | มี |  | 35% ของ 26 บรรทัด | ⚠️ .worktrees |
| `codex/formdp-reconfirm-date-changes` | 2026-10-06 | มี | #5 CLOSED | 60% ของ 6092 บรรทัด | ⚠️ formdp-reconfirm-all-date-changes |
| `codex/formfc-planner-sales-view` | 2026-10-06 | - |  | 61% ของ 6012 บรรทัด | ⚠️ .worktrees |
| `codex/formfc-manual-save-validation` | 2026-10-08 | - |  | 57% ของ 6200 บรรทัด |  |
| `codex/formpo-closed-mail-remarks` | 2026-10-21 | มี | #10 CLOSED | 94% ของ 66 บรรทัด | ⚠️ formpo-closed-mail-remarks |
| `codex/group-formpo-approver-pdf-tools` | 2026-10-30 | - |  | 86% ของ 722 บรรทัด | ⚠️ worktrees |
| `codex/wip-grouped-backup-20261030` | 2026-10-30 | - |  | 76% ของ 18353 บรรทัด |  |
| `codex/formwos-otd-dashboard` | 2026-11-06 | - |  | 55% ของ 5619 บรรทัด | ⚠️ .worktrees |

## กลุ่ม A — ลบได้ (110)

| branch | commit ล่าสุด | remote | PR | ใน base แล้ว | worktree |
|---|---|---|---|---|---|
| `claude/agitated-ptolemy-9738b0` | 2026-04-27 | - |  | 100% |  |
| `claude/flamboyant-black-0b9952` | 2026-04-27 | - |  | 100% |  |
| `claude/flamboyant-pare-5e3a72` | 2026-04-27 | - |  | 100% |  |
| `claude/focused-nightingale-95f211` | 2026-04-27 | - |  | 100% |  |
| `claude/funny-mestorf-10347b` | 2026-04-27 | - |  | 100% |  |
| `claude/happy-liskov-8f747a` | 2026-04-27 | - |  | 100% |  |
| `claude/intelligent-wilson-4e6fb5` | 2026-04-27 | - |  | 100% |  |
| `claude/serene-kowalevski-7deaf6` | 2026-04-27 | - |  | 100% |  |
| `claude/stoic-shirley-5d0ab0` | 2026-04-27 | - |  | 100% |  |
| `claude/zealous-bhabha-13e8d6` | 2026-04-27 | - |  | 100% |  |
| `master` | 2026-04-27 | - |  | 100% |  |
| `codex-branch-debug-tool` | 2026-06-01 | - |  | 100% |  |
| `codex-formdp-inquiry-stock-plus` | 2026-06-01 | - |  | 100% |  |
| `codex-formfc-division-group-master` | 2026-06-01 | มี |  | 100% |  |
| `codex-special-dispatch-docs` | 2026-06-01 | - |  | 100% |  |
| `codex-formdie-tracking-refine` | 2026-06-09 | - |  | 100% |  |
| `codex-formdp-inquiry-ui-redesign` | 2026-06-09 | - |  | 100% |  |
| `codex-plan-dashboard` | 2026-06-09 | - |  | 100% |  |
| `codex/formdp-special-customer-free-text` | 2026-06-09 | - |  | 100% |  |
| `codex/formpp-cpa30-analysis` | 2026-06-09 | - |  | 100% |  |
| `codex/accounting-loss-recovery-analysis` | 2026-06-24 | - |  | 100% |  |
| `codex/formdp-cancel-truck-dispatch` | 2026-06-24 | - |  | 100% |  |
| `codex/formfc-need-to-order-negative` | 2026-06-24 | - |  | 100% |  |
| `codex/formpo-closed-submit-mail` | 2026-06-24 | - |  | 100% |  |
| `codex/formpo-submit-attachments` | 2026-06-24 | - |  | 100% |  |
| `codex/formpp-so-line-balance` | 2026-06-24 | - |  | 100% |  |
| `codex/formwos-exclude-stock-due-date` | 2026-06-24 | - |  | 100% |  |
| `codex/grating-performance-pcs-fieldwork` | 2026-06-24 | - |  | 100% |  |
| `codex/grating-performance-rough` | 2026-06-24 | - |  | 100% |  |
| `codex/wlm-capacity-plan-filters` | 2026-06-24 | - |  | 100% |  |
| `feature/formdp-bulk-unassign` | 2026-06-24 | - |  | 100% |  |
| `codex/deadstock-pdf-report` | 2026-07-08 | - |  | 100% |  |
| `codex/grating-performance-improvements` | 2026-07-08 | - |  | 100% |  |
| `codex/formfc-forecast-duplicate-key` | 2026-07-10 | - |  | 100% |  |
| `codex/formgp-folder` | 2026-07-10 | - |  | 100% |  |
| `codex/formgp-mfg-g-sod` | 2026-07-10 | - |  | 100% |  |
| `codex/formgp-user-manual` | 2026-07-10 | - |  | 100% |  |
| `codex/formpo-plus-paper-signatures` | 2026-07-10 | - |  | 100% |  |
| `codex/formgp-folder-clean` | 2026-07-10 | มี |  | 96% ของ 5635 บรรทัด | ⚠️ menam-workflow |
| `codex/formgp-mfg-plan-qty` | 2026-07-13 | มี | #1 MERGED | 100% |  |
| `claude/interesting-swanson-9df19c` | 2026-07-13 | - |  | 98% ของ 65 บรรทัด |  |
| `claude/peaceful-cartwright-910529` | 2026-07-13 | - |  | 98% ของ 64 บรรทัด |  |
| `codex/formpo-reopen-approval-clean` | 2026-07-27 | มี |  | 100% ของ 134 บรรทัด | ⚠️ Temp |
| `codex/formfc-approval-sales-remark` | 2026-08-05 | - |  | 100% | ⚠️ worktrees |
| `codex/integrate-fc-tracking-main` | 2026-08-05 | - |  | 100% |  |
| `codex/formgp-dev-latest` | 2026-08-10 | - |  | 100% |  |
| `codex/formdp-tracking-quick-access` | 2026-08-11 | - |  | 97% ของ 112 บรรทัด | ⚠️ .worktrees |
| `codex/formwr-reserved-stock` | 2026-09-07 | มี | #3 MERGED | 100% |  |
| `codex/merge-vc-main-clean` | 2026-09-22 | - |  | 100% |  |
| `codex/formdp-confirm-planner-sheet-pr` | 2026-09-30 | มี | #6 MERGED | 100% |  |
| `codex/integrate-formpo-pov-mail` | 2026-10-01 | - |  | 100% | ⚠️ .worktrees |
| `codex/formdp-planner-inquiry-order-status` | 2026-10-05 | มี | #7 MERGED | 100% |  |
| `codex/deadstock-reschedule-report` | 2026-10-06 | มี | #8 MERGED | 100% |  |
| `codex/formtracking-combo-order` | 2026-10-06 | - |  | 100% |  |
| `codex/admin-password-reset` | 2026-10-09 | - |  | 100% | ⚠️ admin-password-reset |
| `codex/stock-withdrawal-alert` | 2026-10-13 | มี | #9 CLOSED | 100% | ⚠️ codex-worktrees |
| `codex/formfc-planning-export-sales-remark` | 2026-10-14 | มี |  | 100% |  |
| `codex/formdp-lock-postponed-edit` | 2026-10-16 | มี |  | 100% |  |
| `codex/formfc-planning-export-supplier` | 2026-10-19 | - |  | 100% |  |
| `codex/invoice-mfg-profit` | 2026-10-26 | มี |  | 99% ของ 323 บรรทัด | ⚠️ codex-worktrees |
| `codex/grouped-working-tree-audit` | 2026-10-27 | - |  | 100% | ⚠️ grouped-change-audit |
| `codex/stock-withdrawal-10pct` | 2026-10-28 | มี |  | 100% | ⚠️ stock-withdrawal-10pct |
| `codex/deadstock-export-performance` | 2026-10-29 | มี |  | 100% | ⚠️ .worktrees |
| `codex/deadstock-sales-delivery-sheet` | 2026-10-29 | มี |  | 100% |  |
| `codex/stock-withdrawal-daily-mail` | 2026-10-29 | มี |  | 100% | ⚠️ codex-worktrees |
| `codex/group-deadstock-background-export` | 2026-10-30 | มี |  | 100% | ⚠️ worktrees |
| `codex/group-die-damage-reports` | 2026-10-30 | มี | #18 MERGED | 100% | ⚠️ worktrees |
| `codex/group-stock-withdrawal-daily-mail` | 2026-10-30 | มี |  | 100% | ⚠️ worktrees |
| `codex/group-sidebar-essential-menus` | 2026-10-30 | - |  | 100% |  |
| `codex/sidebar-essential-menus` | 2026-10-30 | มี |  | 100% | ⚠️ sidebar-essential-menus |
| `codex/group-formdp-routing-stations` | 2026-10-30 | - |  | 100% ของ 148 บรรทัด | ⚠️ worktrees |
| `codex/ops-monitor-erp` | 2026-10-31 | มี | #13 MERGED | 100% | ⚠️ ops-monitoring |
| `codex/request-form-home-link` | 2026-10-31 | มี | #12 MERGED | 100% |  |
| `codex/request-form-sso-signer` | 2026-10-31 | มี | #19 MERGED | 100% |  |
| `codex/die-damage-lookup-autocomplete` | 2026-11-02 | มี | #21 MERGED | 100% |  |
| `codex/die-damage-ui-consistency` | 2026-11-02 | มี | #20 MERGED | 100% |  |
| `codex/die-received-date` | 2026-11-02 | มี | #16 MERGED | 100% |  |
| `codex/die-tracking-unit-tests` | 2026-11-02 | มี | #17 MERGED | 100% |  |
| `codex/formpo-closed-mail-remarks-font` | 2026-11-02 | มี | #15 MERGED | 100% | ⚠️ .worktrees |
| `codex/formpo-closed-mail-remarks-sync` | 2026-11-02 | มี | #14 MERGED | 100% |  |
| `codex/restore-wr-deadstock-menu` | 2026-11-02 | มี | #22 MERGED | 100% |  |
| `codex/request-form-sso-hardening` | 2026-11-03 | มี | #25 MERGED, #24 MERGED | 100% |  |
| `codex/wirerod-weekly-reservation-rules` | 2026-11-03 | มี | #23 MERGED | 100% |  |
| `codex/backup-ai-before-formfc-so-kg-20261106` | 2026-11-06 | - |  | 100% |  |
| `codex/backup-ai-before-no-content-merges-20261106` | 2026-11-06 | - |  | 100% |  |
| `codex/backup-ai-before-stock-withdrawal-mail-20261106` | 2026-11-06 | - |  | 100% |  |
| `codex/formfc-e-piece-kg-display` | 2026-11-06 | - |  | 100% | ⚠️ worktrees |
| `codex/formfc-manager-export-sales-remark` | 2026-11-06 | - | #32 MERGED | 100% |  |
| `codex/formfc-manual-validation-integration` | 2026-11-06 | - | #30 MERGED | 100% |  |
| `codex/formpo-digest-real-step-name` | 2026-11-06 | - | #29 MERGED | 100% |  |
| `codex/formpo-step3-digest-production-controls` | 2026-11-06 | - | #28 MERGED | 100% |  |
| `codex/formpo-step3-digest-short-text` | 2026-11-06 | - | #27 MERGED | 100% |  |
| `codex/formpo-step3-pending-digest` | 2026-11-06 | - | #26 MERGED | 100% |  |
| `codex/vc-multi-year-integration` | 2026-11-06 | - | #31 MERGED | 100% |  |
| `codex/formdp-memory-grant-fix` | 2026-11-10 | มี | #33 MERGED | 100% |  |
| `codex/all-menus-page` | 2026-11-19 | มี | #36 MERGED | 100% | ⚠️ worktrees |
| `codex/db-profile-switch` | 2026-11-19 | มี | #38 MERGED | 100% |  |
| `codex/docs-deploy-patches` | 2026-11-19 | มี | #45 MERGED | 100% |  |
| `codex/fix-post-sync-tests` | 2026-11-19 | มี | #46 MERGED | 100% |  |
| `codex/prod-sync-activity-audit` | 2026-11-19 | มี | #39 MERGED | 100% |  |
| `codex/prod-sync-formdp-submission` | 2026-11-19 | มี | #40 MERGED | 100% |  |
| `codex/prod-sync-formfc` | 2026-11-19 | มี | #42 MERGED | 100% |  |
| `codex/prod-sync-misc-fixes` | 2026-11-19 | มี | #41 MERGED | 100% |  |
| `codex/prod-sync-po-approver` | 2026-11-19 | มี | #43 MERGED | 100% |  |
| `codex/prod-sync-po-features` | 2026-11-19 | มี | #48 MERGED | 100% |  |
| `codex/prod-sync-tracking` | 2026-11-19 | มี | #49 MERGED | 100% |  |
| `codex/prod-sync-wos` | 2026-11-19 | มี | #47 MERGED | 100% |  |
| `codex/sidebar-menu-groups` | 2026-11-19 | มี | #44 MERGED | 100% |  |
| `codex/wip-monitoring` | 2026-11-19 | มี | #37 MERGED | 100% |  |
| `codex/deadstock-postpone-top-sheets` **(branch ปัจจุบัน)** | 2026-11-19 | มี | #34 MERGED | 100% |  |

## Worktree ของ Codex

branch ที่ถูกใช้ใน worktree ลบไม่ได้จนกว่าจะเอา worktree ออก
**ห้าม `git worktree remove --force`** — เคยลบ `vendor` ของ repo หลักผ่าน junction (2026-11-06). ถอด junction ก่อน แล้วค่อย `git worktree remove` (ไม่ใส่ --force)

- `C:/Users/nitiphan_tur/.codex/worktrees/admin-password-reset/menam-workflow` → `codex/admin-password-reset`
- `C:/Users/nitiphan_tur/.codex/worktrees/formdp-reconfirm-all-date-changes/menam-workflow` → `codex/formdp-reconfirm-date-changes`
- `C:/Users/nitiphan_tur/.codex/worktrees/formpo-closed-mail-remarks/menam-workflow` → `codex/formpo-closed-mail-remarks`
- `C:/Users/nitiphan_tur/.codex/worktrees/grouped-change-audit/menam-workflow` → `codex/grouped-working-tree-audit`
- `C:/Users/nitiphan_tur/.codex/worktrees/ops-monitoring/menam-workflow` → `codex/ops-monitor-erp`
- `C:/Users/nitiphan_tur/.codex/worktrees/po-step3-recovery-mail/menam-workflow` → `codex/po-step3-recovery-mail`
- `C:/Users/nitiphan_tur/.codex/worktrees/pp-production-balance/menam-workflow` → `codex/pp-production-balance`
- `C:/Users/nitiphan_tur/.codex/worktrees/sidebar-essential-menus/menam-workflow` → `codex/sidebar-essential-menus`
- `C:/Users/nitiphan_tur/.codex/worktrees/stock-withdrawal-10pct/menam-workflow` → `codex/stock-withdrawal-10pct`
- `C:/Users/nitiphan_tur/AppData/Local/Temp/menam-workflow-formpo-reopen-pr` → `codex/formpo-reopen-approval-clean`
- `C:/xampp/htdocs/codex-worktrees/menam-sso-ready` → `codex/request-form-sso-ready-local`
- `C:/xampp/htdocs/menam-workflow/.claude/worktrees/distracted-shamir-da6d0b` → `claude/distracted-shamir-da6d0b`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/adminweb-password-min4` → `codex/adminweb-password-min4`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/bulk-user-import` → `codex/bulk-user-import`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/deadstock-background-export` → `codex/group-deadstock-background-export`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/department-organization` → `codex/department-organization`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/die-damage-reports` → `codex/group-die-damage-reports`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/form-vcl-logistics` → `codex/group-form-vcl-logistics`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/formdp-routing-stations` → `codex/group-formdp-routing-stations`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/formfc-approval-sales-remark` → `codex/formfc-approval-sales-remark`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/formfc-e-piece-kg-display` → `codex/formfc-e-piece-kg-display`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/formfc-period-order-balance` → `codex/group-formfc-period-order-balance`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/formfc-routing-import` → `codex/formfc-routing-import`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/formgp-latest-step-comparison` → `codex/formgp-latest-step-comparison`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/formpo-approver-pdf-tools` → `codex/group-formpo-approver-pdf-tools`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/invoice-packing-document` → `codex/group-invoice-packing-document`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/owasp-foundation-hardening` → `codex/owasp-foundation-hardening`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/sidebar-essential-menus` → `codex/all-menus-page`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/stock-withdrawal-daily-mail` → `codex/group-stock-withdrawal-daily-mail`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/tracking-weight-tolerance` → `codex/tracking-weight-tolerance`
- `C:/xampp/htdocs/menam-workflow/.codex/worktrees/wos-delivery-volume-filter` → `codex/wos-delivery-volume-filter`
- `C:/xampp/htdocs/menam-workflow/.worktrees/ai-formfc-planner-sales-merge` → `ai/menam-workflow`
- `C:/xampp/htdocs/menam-workflow/.worktrees/ai-menam-workflow-merge` → `codex/integrate-formpo-pov-mail`
- `C:/xampp/htdocs/menam-workflow/.worktrees/codex-po-pou-pdf-visibility` → `codex/po-pour-pdf-visibility`
- `C:/xampp/htdocs/menam-workflow/.worktrees/deadstock-sales-delivery-sheet` → `codex/deadstock-export-performance`
- `C:/xampp/htdocs/menam-workflow/.worktrees/error-logging` → `codex/error-logging`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formdp-planner-department-access` → `codex/formdp-planner-department-access`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formdp-tracking-quick-access` → `codex/formdp-tracking-quick-access`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formfc-division-export-detail` → `codex/formfc-division-export-detail`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formfc-four-month-everywhere` → `codex/formfc-four-month-everywhere`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formfc-planner-sales-view` → `codex/formfc-planner-sales-view`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formpkg-packaging-shortage-mail` → `codex/formpkg-packaging-shortage-mail`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formpo-closed-mail-remarks-sync` → `codex/formpo-closed-mail-remarks-font`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formpo-email-subject-prefix` → `codex/formpo-mail-sender-name`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formpo-step3-digest-bcc-itp` → `codex/formpo-step3-digest-bcc-itp`
- `C:/xampp/htdocs/menam-workflow/.worktrees/formwos-order-due-actual-delivery` → `codex/formwos-otd-dashboard`
- `C:/xampp/htdocs/menam-workflow/.worktrees/merge-vc-main` → `codex/merge-vc-main`
- `C:/xampp/htdocs/menam-workflow/clean-formgp-folder` → `codex/formgp-folder-clean`
- `C:/xampp/htdocs/menam-workflow/codex-worktrees/invoice-mfg-profit` → `codex/invoice-mfg-profit`
- `C:/xampp/htdocs/menam-workflow/codex-worktrees/stock-withdrawal-alert-feature` → `codex/stock-withdrawal-alert`
- `C:/xampp/htdocs/menam-workflow/codex-worktrees/stock-withdrawal-daily-mail` → `codex/stock-withdrawal-daily-mail`

## ลบแล้ว (2026-11-19)

ลบ branch กลุ่ม A ใน local 85 ตัว (ยกเว้น `master`, `claude/interesting-swanson-9df19c`, `claude/peaceful-cartwright-910529` ตามที่ผู้ใช้สั่ง).
commit ทั้งหมดอยู่ใน `ai/menam-workflow` แล้ว ถ้าต้องการกู้: `git branch <ชื่อ> <sha>`

<details><summary>รายชื่อ + SHA</summary>

```
claude/agitated-ptolemy-9738b0 85cb1ee0
claude/flamboyant-black-0b9952 85cb1ee0
claude/flamboyant-pare-5e3a72 85cb1ee0
claude/focused-nightingale-95f211 85cb1ee0
claude/funny-mestorf-10347b 85cb1ee0
claude/happy-liskov-8f747a 85cb1ee0
claude/intelligent-wilson-4e6fb5 85cb1ee0
claude/serene-kowalevski-7deaf6 85cb1ee0
claude/stoic-shirley-5d0ab0 85cb1ee0
claude/zealous-bhabha-13e8d6 85cb1ee0
codex-branch-debug-tool e4682f10
codex-formdp-inquiry-stock-plus e4682f10
codex-formfc-division-group-master e4682f10
codex-special-dispatch-docs e4682f10
codex-formdie-tracking-refine fc16ac98
codex-formdp-inquiry-ui-redesign fc16ac98
codex-plan-dashboard fc16ac98
codex/formdp-special-customer-free-text fc16ac98
codex/formpp-cpa30-analysis fc16ac98
codex/accounting-loss-recovery-analysis f18101bc
codex/formdp-cancel-truck-dispatch f18101bc
codex/formfc-need-to-order-negative f18101bc
codex/formpo-closed-submit-mail f18101bc
codex/formpo-submit-attachments f18101bc
codex/formpp-so-line-balance f18101bc
codex/formwos-exclude-stock-due-date f18101bc
codex/grating-performance-pcs-fieldwork f18101bc
codex/grating-performance-rough 69de4d15
codex/wlm-capacity-plan-filters f18101bc
feature/formdp-bulk-unassign f18101bc
codex/deadstock-pdf-report bd02319c
codex/grating-performance-improvements bd02319c
codex/formfc-forecast-duplicate-key ee03dd65
codex/formgp-folder a568ead6
codex/formgp-mfg-g-sod ee03dd65
codex/formgp-user-manual a568ead6
codex/formpo-plus-paper-signatures a568ead6
codex/formgp-mfg-plan-qty 41374a97
codex/integrate-fc-tracking-main fe40adef
codex/formgp-dev-latest 58d0df6b
codex/formwr-reserved-stock a2dd8f5c
codex/merge-vc-main-clean 35fa6281
codex/formdp-confirm-planner-sheet-pr 8e8986cc
codex/formdp-planner-inquiry-order-status fda53ac0
codex/deadstock-reschedule-report 47fd991b
codex/formtracking-combo-order 12ede7cf
codex/formfc-planning-export-sales-remark d6ca02e6
codex/formdp-lock-postponed-edit a6ab4f9c
codex/formfc-planning-export-supplier a69fde7e
codex/deadstock-sales-delivery-sheet 690c85c8
codex/group-sidebar-essential-menus eace9916
codex/request-form-home-link 1a17da33
codex/request-form-sso-signer 49740d77
codex/die-damage-lookup-autocomplete 76927818
codex/die-damage-ui-consistency 1bc11c84
codex/die-received-date 1466ada2
codex/die-tracking-unit-tests 022897d3
codex/formpo-closed-mail-remarks-sync 75d26686
codex/restore-wr-deadstock-menu 1a35123f
codex/request-form-sso-hardening a42f7535
codex/wirerod-weekly-reservation-rules 82010a5d
codex/backup-ai-before-formfc-so-kg-20261106 ab3cfed8
codex/backup-ai-before-no-content-merges-20261106 bf5c0632
codex/backup-ai-before-stock-withdrawal-mail-20261106 1ad10e48
codex/formfc-manager-export-sales-remark 6851fdb5
codex/formfc-manual-validation-integration 46948b57
codex/formpo-digest-real-step-name bb4f6978
codex/formpo-step3-digest-production-controls 0a7f0745
codex/formpo-step3-digest-short-text 8781ba3b
codex/formpo-step3-pending-digest 723d645e
codex/vc-multi-year-integration 85dbd509
codex/formdp-memory-grant-fix 923e66b6
codex/db-profile-switch bcac6990
codex/docs-deploy-patches 068d13de
codex/fix-post-sync-tests 81a91f6c
codex/prod-sync-activity-audit 752c606e
codex/prod-sync-formdp-submission e03cc927
codex/prod-sync-formfc 75643fc5
codex/prod-sync-misc-fixes d8c7e8f0
codex/prod-sync-po-approver b7f9edc4
codex/prod-sync-po-features 2ebfe25b
codex/prod-sync-tracking 85b54bfa
codex/prod-sync-wos b15f2684
codex/sidebar-menu-groups e3de9e0d
codex/wip-monitoring 989413fa
```
</details>
