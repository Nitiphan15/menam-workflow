# v1.10.2 — DP Inquiry: ปุ่ม "ดึงข้อมูล ERP" กดไม่ทำงาน

## อาการบน production (ตรวจแบบ read-only 2026-11-19)
หน้า Delivery Plan Inquiry เรียก `ScrollSafeSwal.fire(...)` 4 จุด แต่ production ไม่มี `public/js/scroll-safe-swal.js`
และ layout ไม่ได้โหลดไฟล์นี้ → `ReferenceError: ScrollSafeSwal is not defined`

- ปุ่ม **ดึงข้อมูล ERP** (Sale/Work Order): ฟอร์มถูก `preventDefault()` ก่อน แล้ว JS พัง → **กดแล้วไม่เกิดอะไรเลย**
- `inquiry.js` บรรทัด 989, 3040: กล่องแจ้งเตือน/ยืนยันไม่ขึ้น

## วิธีแก้
เปลี่ยนเป็น `(window.ScrollSafeSwal || window.Swal).fire(` — ถ้ามี ScrollSafeSwal ใช้ตัวนั้น ถ้าไม่มีใช้ SweetAlert ปกติ
ไม่ต้องเพิ่มไฟล์ JS ใหม่ และไม่ต้องแก้ layout

## ไฟล์ที่ต้องอัป (4 ไฟล์) — อยู่ใน `files/` ตาม path จริง
| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `public/js/formdp/inquiry.js` | 2 จุด → fallback Swal |
| `resources/views/formdp/inquiry.blade.php` | 2 จุด → fallback Swal + เปลี่ยน `inquiry.js?v=` เป็น `20261119_v1.10.2` (กัน browser cache JS เก่า) |
| `config/app.php` | default `APP_VERSION` 1.10.1 → 1.10.2 |
| `resources/views/home.blade.php` | อัปเดตล่าสุด + ประวัติเวอร์ชัน v1.10.2 |

ไฟล์ทั้ง 4 สร้างจาก**ไฟล์บน production ปัจจุบัน** แล้วแก้เฉพาะจุดด้านบน (ไม่ได้เอาไฟล์จาก local ไปทับ)
`manifest.json` มี sha256 ก่อน/หลังของแต่ละไฟล์

## ขั้นตอน deploy (PowerShell บนเครื่อง dev — ไม่ต้อง cd ไป M:)
```powershell
$src = "C:\xampp\htdocs\menam-workflow\docs\deploy-patches\v1.10.2-dp-inquiry-swal"
$prod = "M:\htdocs\menam-online"
$bk = "$prod\deploy-backups\dp-inquiry-swal-v1.10.2-$(Get-Date -Format yyyyMMdd-HHmmss)"
$files = "public\js\formdp\inquiry.js","resources\views\formdp\inquiry.blade.php","config\app.php","resources\views\home.blade.php"
$m = Get-Content "$src\manifest.json" -Raw | ConvertFrom-Json

# 1) เช็กว่าไฟล์บน prod ยังเป็นเวอร์ชันเดียวกับตอนเตรียม patch (ถ้าไม่ตรง ให้หยุด)
foreach ($f in $files) { $k = $f -replace '\\','/'; $h = (Get-FileHash "$prod\$f" -Algorithm SHA256).Hash.ToLower(); if ($h -ne $m.$k.before_sha256) { Write-Warning "STOP: $f changed on prod since patch was prepared" } else { "OK  $f" } }

# 2) backup
foreach ($f in $files) { New-Item -ItemType Directory -Force (Split-Path "$bk\$f") | Out-Null; Copy-Item "$prod\$f" "$bk\$f" }
Copy-Item "$src\manifest.json" "$bk\manifest.json"

# 3) copy
foreach ($f in $files) { Copy-Item "$src\files\$f" "$prod\$f" -Force }

# 4) verify
foreach ($f in $files) { $k = $f -replace '\\','/'; $h = (Get-FileHash "$prod\$f" -Algorithm SHA256).Hash.ToLower(); if ($h -eq $m.$k.after_sha256) { "OK  $f" } else { Write-Warning "MISMATCH $f" } }
```

## หลัง copy
1. แก้ `APP_VERSION=1.10.2` ใน `.env` ของ production (Claude ไม่แตะ .env)
2. เคลียร์ cache ผ่าน `/adminweb/cache/clear` (หรือ `config:clear` + `view:clear`) — **ห้าม** `config:cache`/`route:cache` จากเครื่อง dev
3. ทดสอบ: เปิด DP Inquiry → กด **ดึงข้อมูล ERP** → ต้องขึ้นกล่องยืนยัน → กด "ดึงข้อมูล" → ขึ้น "กำลังดึงข้อมูล" แล้วหน้ารีโหลด
4. หน้า Home แสดง Version 1.10.2

## Deploy log
- 2026-11-19 15:11 deploy โดย Claude (ตามคำสั่งผู้ใช้) — backup ที่ `deploy-backups\dp-inquiry-swal-v1.10.2-20261119-151122` (4/4 sha256 ตรง before)
- ทั้ง 4 ไฟล์ sha256 ตรง after, ตั้ง LastWriteTime = เวลา deploy เพื่อให้ Blade compile ใหม่ (Copy-Item คงเวลาไฟล์ต้นทางไว้)
- production ไม่มี `bootstrap/cache/config.php` → ค่า config ใหม่มีผลทันที
- ผู้ใช้ทดสอบบน production แล้ว: ปุ่ม "ดึงข้อมูล ERP" ใช้งานได้ (2026-11-19)

## ย้อนกลับ
copy ไฟล์จากโฟลเดอร์ backup ใน `deploy-backups\dp-inquiry-swal-v1.10.2-*` กลับไปที่เดิม แล้วเคลียร์ cache
