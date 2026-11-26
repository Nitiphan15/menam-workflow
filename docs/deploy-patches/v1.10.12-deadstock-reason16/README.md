# v1.10.12 — Deadstock reason code 16

## การเปลี่ยนแปลง

- เปลี่ยนคำอธิบาย `SS16`, `SS16-1`, `SS16-2` เป็น “รอคิวจัดรถส่ง/ขอน้ำหนักก่อนส่ง”
- เพิ่ม Factory code `FF16-1`, `FF16-2` โดยแยกส่งมอบภายใน/เกิน 30 วัน
- อัปเดต default version และหน้า Home เป็น `1.10.12`

ไฟล์ใน `files/` สร้างจากไฟล์ Production ปัจจุบัน แล้วแก้เฉพาะรายการข้างต้น
ตรวจ `before_sha256` ทุกไฟล์ก่อน copy และหยุดทันทีถ้าไม่ตรง

## ไฟล์ที่ deploy

- `app/Support/FormWOS/DeadstockReasonMap.php`
- `config/app.php`
- `resources/views/home.blade.php`

## หลัง deploy

1. ตั้ง LastWriteTime ของทั้ง 3 ไฟล์เป็นเวลา deploy
2. ผู้ใช้ตั้ง Production `.env` เป็น `APP_VERSION=1.10.12`
3. เปิด `/adminweb/cache/clear`
4. ตรวจหน้า Deadstock ว่ามี `SS16`, `FF16-1`, `FF16-2` และคำอธิบายใหม่
5. ตรวจหน้า Home ว่าแสดง Version 1.10.12

## Rollback

คัดลอกไฟล์ทั้ง 3 จากโฟลเดอร์ `deploy-backups/deadstock-reason16-v1.10.12-*` กลับตำแหน่งเดิม แล้วเปิด `/adminweb/cache/clear`
