# คู่มือ Merge ไฟล์เข้า Project ที่มีอยู่

> วิธีเอาไฟล์ ERP Reporting มา integrate กับ Laravel project ที่มี CLAUDE.md อยู่แล้ว
> โดยไม่ทับของเดิม

---

## 🎯 Strategy: 3-Layer Approach

```
your-laravel-project/
├── CLAUDE.md                              ← root (มีอยู่แล้ว)
│                                            └─ append section "ERP Reporting Module" สั้นๆ
└── docs/
    └── erp-reporting/
        ├── CLAUDE.md                      ← module-level (รายละเอียดของ ERP)
        ├── PRIVACY_POLICY.md
        ├── erp_data_dictionary.xlsx
        ├── 03_expense_by_department.sql
        └── 04_ap_queries_safe.sql
```

**ทำไมต้อง 2 ชั้น?**

- ✅ Root CLAUDE.md ของเดิมยังทำงานครบถ้วน (Workflow rules, DB rules, etc.)
- ✅ ERP module มี doc ของตัวเอง — ไม่ปนกับ project rules
- ✅ Claude Code อ่าน root อัตโนมัติ → เจอ pointer ไปอ่าน module CLAUDE.md เมื่อทำ ERP task
- ✅ Module CLAUDE.md มี cross-reference กลับไป root → consistent

---

## ขั้นตอน Merge

### Step 1: Backup ก่อน
```bash
cd C:\xampp\htdocs\your-project
git status                                    # ต้อง clean
git add . && git commit -m "chore: snapshot before ERP integration"
cp CLAUDE.md CLAUDE.md.backup                # backup เพิ่มอีกชั้น
```

### Step 2: Append Section ใหม่เข้า CLAUDE.md เดิม

เปิด `CLAUDE.md` ของเดิมที่ root → เลื่อนไปท้ายไฟล์ → **paste เนื้อหาจาก `CLAUDE_MD_APPEND.md`** ต่อท้าย

หรือถ้าใช้ command line:
```bash
# Windows PowerShell
Get-Content CLAUDE_MD_APPEND.md | Add-Content -Path CLAUDE.md

# หรือ Git Bash / WSL
cat CLAUDE_MD_APPEND.md >> CLAUDE.md
```

### Step 3: วางไฟล์ module ใน docs/erp-reporting/
```bash
mkdir docs/erp-reporting

# Copy ไฟล์ทั้งหมดเข้าไป
copy CLAUDE_MODULE.md         docs\erp-reporting\CLAUDE.md
copy PRIVACY_POLICY.md        docs\erp-reporting\PRIVACY_POLICY.md
copy erp_data_dictionary.xlsx docs\erp-reporting\erp_data_dictionary.xlsx
copy 03_expense_by_department.sql docs\erp-reporting\03_expense_by_department.sql
copy 04_ap_queries_safe.sql   docs\erp-reporting\04_ap_queries_safe.sql
```

⚠️ สังเกต: `CLAUDE_MODULE.md` ตอนวางใน docs/erp-reporting/ ให้**เปลี่ยนชื่อเป็น `CLAUDE.md`**

### Step 4: ตรวจสอบ
```bash
# โครงสร้างที่ถูกต้อง
your-project/
├── CLAUDE.md                     ← ของเดิม + section ใหม่ท้ายไฟล์
└── docs/
    └── erp-reporting/
        ├── CLAUDE.md             ← module guide (ที่เปลี่ยนชื่อจาก CLAUDE_MODULE.md)
        ├── PRIVACY_POLICY.md
        ├── erp_data_dictionary.xlsx
        ├── 03_expense_by_department.sql
        └── 04_ap_queries_safe.sql
```

### Step 5: Commit
```bash
git add .
git commit -m "docs(erp): add ERP reporting module documentation

- Append ERP Reporting section to root CLAUDE.md
- Add docs/erp-reporting/ with module guide and references
- Privacy policy: no vendor/customer name display
- Schema: 199 tables data dictionary
- SQL templates: privacy-safe query patterns"
```

---

## ทดสอบว่า Claude Code เข้าใจ

หลัง integrate เสร็จ ลองรัน Claude Code แล้ว paste:

```
อ่าน CLAUDE.md ที่ root แล้วบอกฉัน:

1. โปรเจคนี้คืออะไร? (จาก root CLAUDE.md)
2. มี module อะไรบ้าง? (รวม module ใหม่)
3. ถ้าฉันขอให้ทำ "AP expense report" คุณจะอ่านไฟล์ไหนเพิ่ม?
4. กฎ privacy ที่ต้องระวังคืออะไร?

ห้ามเขียน code — แค่อ่านและตอบ
```

ผลที่ควรได้:
- รู้ว่ามี Workflow approval, Delivery Plan, ฯลฯ (จาก root)
- รู้ว่ามี ERP module ใหม่ (จาก section ที่ append)
- บอกว่าจะอ่าน `docs/erp-reporting/CLAUDE.md` + `PRIVACY_POLICY.md`
- ระบุกฎ privacy ห้าม vendor.name / customer.name

---

## ⚠️ ข้อควรระวัง

### 1. Connection name อาจชนกัน

Root CLAUDE.md ของคุณระบุ connections อยู่แล้ว:
```
sqlsrv, pgsqlw, pgsqlp, pgsqlmfgw, pgsqlmfgp
```

**คำถาม:** มี connection ไหนที่ชี้ไป ERP database (192.168.1.40) อยู่แล้วหรือเปล่า?

- ถ้า **มี** → ใช้ connection เดิม (เช่น `pgsqlp` ถ้า P คือ production ERP) แทนที่จะสร้าง `erp` ใหม่
- ถ้า **ไม่มี** → สร้าง `erp` connection ใหม่ตาม guide

ให้ Claude Code ตรวจ `config/database.php` ก่อน:
```
ดู config/database.php — มี connection ไหนชี้ไป PostgreSQL ที่ 192.168.1.40 อยู่แล้วไหม?
ถ้ามีบอกฉัน จะได้ใช้ connection เดิม ถ้าไม่มีค่อยสร้าง 'erp' ใหม่
```

### 2. User authorization ต้อง check

Root CLAUDE.md เน้นเรื่อง:
- `department_roles`
- Sales division permission
- Department-based approval

**สำหรับ ERP reports** อาจต้องคิดว่า
- ใครดู expense report ได้บ้าง? (ทุกคน vs เฉพาะ accounting/management?)
- ต้อง filter ตาม division ไหม?

ก่อนทำ feature ถาม user ก่อน:
```
สำหรับ ERP expense dashboard:
- ใครเห็นได้บ้าง? (auth role ไหน?)
- ต้อง filter ตาม department ไหม? (เห็นแค่แผนกตัวเอง vs ทั้งหมด)
- เก็บ access log ไหม?
```

### 3. ห้ามชน feature เดิม

Root CLAUDE.md เตือนเรื่อง:
- Preserve existing route names
- Don't redesign whole pages
- Avoid unrelated refactoring

ทุก ERP feature ต้องอยู่ใน `/erp/*` prefix และไม่แตะ feature อื่น

---

## 💡 ตัวอย่างการใช้งานจริง

หลัง integrate เสร็จ พอจะสั่งงาน Claude Code:

### ✅ พูดสั้นๆ ได้
```
สร้าง ERP expense dashboard ที่หน้า /erp/expense
```

Claude Code จะ:
1. อ่าน root CLAUDE.md (project rules)
2. เห็น section "ERP Reporting Module" → รู้ว่าเป็น ERP task
3. อ่าน `docs/erp-reporting/CLAUDE.md` (module patterns)
4. อ่าน `docs/erp-reporting/PRIVACY_POLICY.md` (privacy rules)
5. Plan → ขอ confirm → ทำ

### ✅ ระบุ scope ชัดๆ ก็ได้
```
[ERP Module] เพิ่ม drill-down report ที่ /erp/expense/dept/{name}
อ่าน docs/erp-reporting/CLAUDE.md ก่อน
```

---

## 📋 Checklist หลัง Integration

- [ ] CLAUDE.md ที่ root มี section "ERP Reporting Module" ต่อท้าย
- [ ] `docs/erp-reporting/CLAUDE.md` มีอยู่ (module-level guide)
- [ ] `docs/erp-reporting/PRIVACY_POLICY.md` มีอยู่
- [ ] `docs/erp-reporting/erp_data_dictionary.xlsx` มีอยู่
- [ ] SQL files ใน docs/erp-reporting/ ครบ
- [ ] git commit เรียบร้อย
- [ ] ทดสอบ Claude Code อ่านได้ครบ (รัน prompt ทดสอบข้างบน)
- [ ] ตรวจ connection ใน `config/database.php` — ใช้เดิมหรือสร้างใหม่?

หลัง 8 ข้อนี้ครบ → พร้อมเริ่ม build feature!

---

## 🆘 ถ้าทำพลาดทับ CLAUDE.md เดิมไป

```bash
# ใช้ backup ที่สร้างไว้ใน Step 1
cp CLAUDE.md.backup CLAUDE.md

# หรือใช้ git revert
git checkout HEAD~1 -- CLAUDE.md

# หรือ git reset (อันตรายถ้ามี changes อื่น)
git checkout -- CLAUDE.md
```
