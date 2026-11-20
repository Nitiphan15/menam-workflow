# v1.10.5 — Delivery Plan: ข้อความ 403 ของ Export Planner เป็นภาษาไทยเพี้ยน

## อาการบน production (ตรวจแบบ read-only 2026-11-20)
`DeliveryPlanInquiryController::exportPlannerConfirmed()` บรรทัด 277 มีข้อความ 403 ที่ encoding เสีย
(`'Export เธเธตเน...'`) ผู้ใช้ที่ไม่ใช่แผนก Planner กด **Export รายการที่ Planner ยืนยัน** จะเห็นข้อความที่อ่านไม่ออก

ตรรกะสิทธิ์บน production ถูกต้องอยู่แล้ว (`User::isPlannerDepartment()` = user id 2 หรือแผนกรหัส `PN`) จึงแก้เฉพาะข้อความ

## วิธีแก้
เปลี่ยนเป็น `abort(403, 'Export นี้สำหรับแผนก Planner เท่านั้น');` (UTF-8 ถูกต้อง) ไม่แตะโค้ดส่วนอื่น

## ไฟล์ที่ต้องอัป (3 ไฟล์) — อยู่ใน `files/` ตาม path จริง
| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `app/Http/Controllers/FormDP/DeliveryPlanInquiryController.php` | บรรทัด 277 ข้อความ 403 เท่านั้น |
| `config/app.php` | default `APP_VERSION` 1.10.4 → 1.10.5 |
| `resources/views/home.blade.php` | อัปเดตล่าสุด + ประวัติเวอร์ชัน v1.10.5 |

ไฟล์ทั้ง 3 สร้างจาก**ไฟล์บน production ปัจจุบัน** (2026-11-20) แล้วแก้เฉพาะจุดด้านบน ไม่ได้เอาไฟล์จาก local ไปทับ
encoding UTF-8 และ line ending เดิม (CRLF/LF) คงไว้ทุกไฟล์ · `manifest.json` มี sha256 ก่อน/หลัง

## ขั้นตอน deploy (PowerShell บนเครื่อง dev — ไม่ต้อง cd ไป M:)
```powershell
$src = "C:\xampp\htdocs\menam-workflow\docs\deploy-patches\v1.10.5-dp-planner-export-message"
$prod = "M:\htdocs\menam-online"
$bk = "$prod\deploy-backups\dp-planner-export-message-v1.10.5-$(Get-Date -Format yyyyMMdd-HHmmss)"
$files = "app\Http\Controllers\FormDP\DeliveryPlanInquiryController.php","config\app.php","resources\views\home.blade.php"
$m = Get-Content "$src\manifest.json" -Raw | ConvertFrom-Json

# 1) เช็กว่าไฟล์บน prod ยังเป็นเวอร์ชันเดียวกับตอนเตรียม patch (ถ้าไม่ตรง ให้หยุด)
foreach ($f in $files) { $k = $f -replace '\\','/'; $h = (Get-FileHash "$prod\$f" -Algorithm SHA256).Hash.ToLower(); if ($h -ne $m.$k.before_sha256) { throw "STOP: $f changed on prod since patch was prepared" } else { "OK  $f" } }

# 2) backup
foreach ($f in $files) { New-Item -ItemType Directory -Force (Split-Path "$bk\$f") | Out-Null; Copy-Item "$prod\$f" "$bk\$f" }
Copy-Item "$src\manifest.json" "$bk\manifest.json"

# 3) copy + ตั้งเวลาไฟล์เป็นตอนนี้ (Copy-Item คงเวลาเดิม → Blade จะไม่ compile ใหม่)
foreach ($f in $files) { Copy-Item "$src\files\$f" "$prod\$f" -Force; (Get-Item "$prod\$f").LastWriteTime = Get-Date }

# 4) verify
foreach ($f in $files) { $k = $f -replace '\\','/'; $h = (Get-FileHash "$prod\$f" -Algorithm SHA256).Hash.ToLower(); if ($h -eq $m.$k.after_sha256) { "OK  $f" } else { Write-Warning "MISMATCH $f" } }
```

## หลัง copy
1. ผู้ใช้แก้ `APP_VERSION=1.10.5` ใน `.env` ของ production (Claude ไม่แตะ .env)
2. เคลียร์ cache ผ่าน `/adminweb/cache/clear` — **ห้าม** `config:cache`/`route:cache` จากเครื่อง dev
3. ทดสอบ: ล็อกอินด้วย user ที่**ไม่ใช่** Planner → DP Inquiry → Export รายการที่ Planner ยืนยัน → ต้องขึ้น 403 "Export นี้สำหรับแผนก Planner เท่านั้น" อ่านออก
4. ทดสอบ user Planner (แผนก PN) → Export ได้ไฟล์ `Inquiry_Confirm_By_Planner.xlsx` ตามเดิม
5. หน้า Home แสดง Version 1.10.5 และรายการ v1.10.5 ในประวัติเวอร์ชัน

## ย้อนกลับ
copy 3 ไฟล์จาก `deploy-backups\dp-planner-export-message-v1.10.5-*` กลับที่เดิม ตั้ง LastWriteTime เป็นเวลาปัจจุบัน แล้วเคลียร์ cache

## หมายเหตุ
- พบข้อความไทยเพี้ยนอีก 2 ไฟล์ (ทั้งใน git และ prod) แต่ไม่กระทบผู้ใช้จึงไม่รวมใน patch นี้:
  `FormWOS/SalesUnitSummaryController.php` (`$groupLabelMap` ไม่ถูกใช้) และ
  `FormWOS/DeadstockExcelBackfillService.php` (alias หัวคอลัมน์ไทยของ Excel เก่า; alias อังกฤษยังใช้ได้)
- ตามกติกา AGENTS.md: deploy แล้วต้องมี PR วันเดียวกัน

## Deploy log
- 2026-11-20 13:51 deploy โดย Claude (ตามคำสั่งผู้ใช้) — backup ที่ `deploy-backups\dp-planner-export-message-v1.10.5-20261120-135138` (3/3 sha256 ตรง before, backup ตรวจ sha256 แล้ว)
- ทั้ง 3 ไฟล์ sha256 ตรง after, ตั้ง LastWriteTime = เวลา deploy (13:51:40) เพื่อให้ Blade compile ใหม่
- production ไม่มี `bootstrap/cache/config.php` → ค่า config ใหม่มีผลทันที
- รอผู้ใช้: แก้ `APP_VERSION=1.10.5` ใน prod `.env`, เคลียร์ cache, ทดสอบ Export ด้วย user ที่ไม่ใช่ Planner
