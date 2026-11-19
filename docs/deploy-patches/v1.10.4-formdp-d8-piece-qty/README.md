# v1.10.4 — FormDP D8 piece quantity

## ปัญหา

Sales D8 ขายเป็นชิ้น แต่เมื่อเลือก MFG ระบบนำ `workorder.qty` ซึ่งเป็นปริมาณฝั่งผลิตคนละหน่วยมาเขียนทับจำนวนชิ้นจาก SO เช่น SO = 3 ชิ้น แต่ MFG = 1.5 แล้วหน้าจอปัดเป็น 2

## การแก้ไข

- D8 / งานขายเป็นชิ้นคง Qty และเพดานจาก `saleorder.ordered_qty` แม้เลือก MFG
- ยังตรวจว่า MFG มีจริงและ Part ตรง
- Sales อื่นที่ขายเป็น KG ยังใช้ `workorder.qty` ตามเดิม
- อัปเดตเวอร์ชัน 1.10.3 เป็น 1.10.4 และหน้า Home

ไฟล์ใน `files/` สร้างจากไฟล์ Production ปัจจุบัน แล้ว patch เฉพาะรายการด้านบน

## Deploy

```powershell
$src = 'C:\xampp\htdocs\menam-workflow\docs\deploy-patches\v1.10.4-formdp-d8-piece-qty'
$prod = 'M:\htdocs\menam-online'
$backup = "$prod\deploy-backups\formdp-d8-piece-qty-v1.10.4-$(Get-Date -Format yyyyMMdd-HHmmss)"
$files = @(
    'app\Http\Controllers\FormDP\DeliveryPlanController.php',
    'resources\views\formdp\index.blade.php',
    'config\app.php',
    'resources\views\home.blade.php'
)
$manifest = Get-Content "$src\manifest.json" -Raw | ConvertFrom-Json

foreach ($file in $files) {
    $key = $file -replace '\\','/'
    $hash = (Get-FileHash "$prod\$file" -Algorithm SHA256).Hash.ToLower()
    if ($hash -ne $manifest.$key.before_sha256) {
        throw "STOP: Production changed: $file"
    }
}

foreach ($file in $files) {
    New-Item -ItemType Directory -Force (Split-Path "$backup\$file") | Out-Null
    Copy-Item "$prod\$file" "$backup\$file"
}
Copy-Item "$src\manifest.json" "$backup\manifest.json"

foreach ($file in $files) {
    Copy-Item "$src\files\$file" "$prod\$file" -Force
    (Get-Item "$prod\$file").LastWriteTime = Get-Date
}

foreach ($file in $files) {
    $key = $file -replace '\\','/'
    $hash = (Get-FileHash "$prod\$file" -Algorithm SHA256).Hash.ToLower()
    if ($hash -ne $manifest.$key.after_sha256) {
        throw "MISMATCH: $file"
    }
}
```

## หลัง Deploy

1. ผู้ใช้แก้ `APP_VERSION=1.10.4` ใน Production `.env`
2. เคลียร์ cache ผ่าน `/adminweb/cache/clear`
3. ทดสอบด้วย user D8: เลือก SO Ordered 3, เลือก MFG qty 1.5 แล้ว Qty ต้องยังเป็น 3 ชิ้น
4. กรอก 4 ชิ้น ต้องแจ้งว่าเกิน 3 ชิ้น
5. ทดสอบ user Sales อื่นว่าเลือก MFG แล้วยังใช้ Qty แบบ KG ตามเดิม

## Rollback

คัดลอก 4 ไฟล์จาก `deploy-backups\formdp-d8-piece-qty-v1.10.4-*` กลับที่เดิม ตั้ง LastWriteTime เป็นเวลาปัจจุบัน แล้วเคลียร์ cache

## Deploy log

- รอ deploy
