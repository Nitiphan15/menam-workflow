# v1.10.3 — จัดหมวดเมนู sidebar ใหม่

| เมนู | จาก | ไป |
|---|---|---|
| Critical Spare Parts | Workflows | **Planning** |
| DIE Tracking | Workflows | **Die** (หมวดใหม่) |
| Grating Performance | Workflows | **Grating** (หมวดใหม่) |
| Accounting Reports, พิมพ์หน้าซองจดหมาย | Workflows | **Accounting** |

ลำดับหมวด: Main → Sales → Accounting → Planning → **Die → Grating** → Purchasing → QA → Operations → Management → Workflows

## ไฟล์ (สร้างจากไฟล์ production ปัจจุบัน แล้ว patch เฉพาะจุด)
| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `resources/views/layouts/_sidebar.blade.php` | `$menuGroupFor` + `$groupOrder` |
| `config/app.php` | default `APP_VERSION` 1.10.2 → 1.10.3 |
| `resources/views/home.blade.php` | อัปเดตล่าสุด + ประวัติเวอร์ชัน v1.10.3 |

ตรวจแล้ว: sidebar ที่ patch compile เป็น PHP ได้ (`php -l` ผ่าน); ทดสอบ logic เดียวกันบน dev แล้วหมวดแสดงถูก

## Deploy (PowerShell บนเครื่อง dev — ไม่ต้อง cd ไป M:)
```powershell
$src = "C:\xampp\htdocs\menam-workflow\docs\deploy-patches\v1.10.3-sidebar-groups"; $prod = "M:\htdocs\menam-online"
$files = "resources\views\layouts\_sidebar.blade.php","config\app.php","resources\views\home.blade.php"
$m = Get-Content "$src\manifest.json" -Raw | ConvertFrom-Json
foreach ($f in $files) { $k = $f -replace '\\','/'; if ((Get-FileHash "$prod\$f" -Algorithm SHA256).Hash.ToLower() -ne $m.$k.before_sha256) { throw "STOP: $f changed on prod" } }
$bk = "$prod\deploy-backups\sidebar-groups-v1.10.3-$(Get-Date -Format yyyyMMdd-HHmmss)"
foreach ($f in $files) { New-Item -ItemType Directory -Force (Split-Path "$bk\$f") | Out-Null; Copy-Item "$prod\$f" "$bk\$f" }; Copy-Item "$src\manifest.json" "$bk\"
$now = Get-Date; foreach ($f in $files) { Copy-Item "$src\files\$f" "$prod\$f" -Force; (Get-Item "$prod\$f").LastWriteTime = $now }
foreach ($f in $files) { $k = $f -replace '\\','/'; if ((Get-FileHash "$prod\$f" -Algorithm SHA256).Hash.ToLower() -eq $m.$k.after_sha256) { "OK $f" } else { Write-Warning "MISMATCH $f" } }
```
หลัง copy: แก้ `APP_VERSION=1.10.3` ใน `.env` ของ production แล้วเคลียร์ cache ผ่าน `/adminweb/cache/clear`

## Deploy log
- 2026-11-19 15:19 deploy โดย Claude (ผู้ใช้ยืนยัน) — backup `deploy-backups\sidebar-groups-v1.10.3-20261119-151941` (ตรวจ sha256 ก่อน/หลังครบ 3/3)
- ผู้ใช้ตรวจ sidebar บน production แล้ว: หมวดถูกต้อง (2026-11-19)

## ย้อนกลับ
copy ไฟล์จาก `deploy-backups\sidebar-groups-v1.10.3-*` กลับที่เดิม แล้วเคลียร์ cache
