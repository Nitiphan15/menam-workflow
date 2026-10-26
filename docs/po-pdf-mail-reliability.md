# คู่มือแก้ปัญหา PDF และการส่งเมล PO

## ขอบเขตงานและสถานะการตรวจสอบ

โค้ดต้นทางอยู่ที่ `C:\xampp\htdocs\menam-workflow` บน branch `codex/po-pdf-mail-reliability`

Log จากเครื่องใช้งานจริงระบุแอปที่ `E:\xampp\htdocs\menam-online` การแก้โค้ดและทดสอบด้วย HTTP จำลอง/SQLite บนเครื่องพัฒนา ยังไม่ใช่การยืนยันว่าติดตั้งและใช้งานได้บนเครื่องจริง

### การดาวน์โหลด PDF

- เมื่อ ERP ส่ง HTML หรือข้อผิดพลาดของรายงานที่ยังไม่ทราบสาเหตุ ระบบจะเก็บ session เดิมไว้
- ระบบลบ session เฉพาะเมื่อได้ HTTP 401/403 หรือพบฟอร์ม login ของ CPA และจะระงับการใช้ session ส่วนกลางที่ตั้งไว้แทนด้วย
- เมื่อบันทึก session ใหม่หรือเชื่อมต่อ ERP อีกครั้ง ระบบจะยกเลิกการระงับข้างต้น
- หากเชื่อมต่อไม่ได้ หรือได้ HTTP 502/503/504 ระบบจะลองใหม่เพิ่มอีกหนึ่งครั้ง
- การแก้นี้ไม่ได้แก้สิทธิ์รายงาน, form ID ที่ผิด หรือข้อมูลเข้าสู่ระบบที่หมดอายุให้โดยอัตโนมัติ และไม่ได้เก็บรหัสผ่านไว้เพื่อ login ใหม่อัตโนมัติ

### การส่งเมล PO

เมลแจ้งรออนุมัติ, แจ้งส่งเอกสาร และแจ้งปิด PO รองรับคิวแยกที่เก็บงานลงฐานข้อมูล โดยเก็บผู้รับ, BCC, ข้อมูลเอกสาร ณ เวลาสร้างเมล และลิงก์แบบเต็มไว้กับงานนั้น

Worker คือโปรแกรมที่อ่านงานในคิวแล้วส่งเมลภายหลัง จึงช่วยให้ผู้อนุมัติไม่ต้องรอ SMTP ส่งครบทุกคน โดย log จะแยก “เข้าคิวแล้ว” ออกจาก “ส่งแล้ว”

ระบบพยายามส่งสูงสุด 3 ครั้ง โดยเว้นก่อนลองใหม่ 60 และ 300 วินาทีตามลำดับ งานที่ยังส่งไม่สำเร็จจะเก็บใน `po_mail_failed_jobs` เพื่อตรวจสอบและสั่งลองใหม่ การส่งผ่าน SMTP อาจซ้ำได้ หากโปรแกรมหยุดหลัง SMTP รับเมลแล้ว แต่ก่อนบันทึกว่างานเสร็จ

`PO_MAIL_QUEUE_ENABLED` มีค่าเริ่มต้นเป็น false จนกว่าจะติดตั้งตารางและ worker หากเพิ่มงานลงคิวไม่ได้ ระบบจะบันทึก error แล้วลองส่งเมลทันทีแบบเดิม เพื่อไม่ให้ทิ้งเมลไปเงียบ ๆ กรณีสำรองนี้ผู้ใช้ยังต้องรอ SMTP

## ขั้นตอนเปิดใช้งานบนเครื่องจริง

### 1. ตรวจเส้นทางและสำรองข้อมูล

ยืนยันโฟลเดอร์แอปที่ Apache ใช้จริงให้ตรงกับ log สำรองไฟล์ปลายทางที่จะเปลี่ยน, `.env` และฐานข้อมูล SQL Server ก่อนติดตั้ง

เปรียบเทียบการปรับแต่งของเครื่องปลายทางก่อนคัดลอกไฟล์ เพราะโค้ดต้นทางมีงานอื่นที่ยังไม่ได้ commit อยู่ด้วย

### 2. ติดตั้งไฟล์ที่เกี่ยวข้องพร้อมกัน

เส้นทางต่อไปนี้นับจากโฟลเดอร์หลักของแอป:

- `app/Http/Controllers/Po/PoApprovalController.php`
- `app/Http/Controllers/Po/PoExportController.php`
- `app/Services/Po/PoNotificationDispatcher.php`
- `app/Services/Po/PoErpPdfDownloader.php`
- `app/Jobs/SendPoNotification.php`
- `app/Console/Commands/WorkPoMail.php`
- `config/queue.php`
- `database/migrations/2026_09_09_000001_create_po_mail_queue_tables.php`

### 3. สร้างตารางและตรวจว่าเรียก worker ได้

เปิด PowerShell บนเครื่องจริง เมื่อยืนยันแล้วว่าเส้นทางต่อไปนี้ถูกต้อง จึงรัน:

```powershell
Set-Location -LiteralPath 'E:\xampp\htdocs\menam-online'
& 'E:\xampp\php\php.exe' artisan migrate --database=sqlsrv_menam --path=database/migrations/2026_09_09_000001_create_po_mail_queue_tables.php --force
& 'E:\xampp\php\php.exe' artisan po:mail-work
```

Migration นี้สร้างเฉพาะตารางคิว SQL Server สองตารางที่แยกไว้สำหรับ PO และสามารถรันซ้ำได้ โดยตั้งใจไม่ลบข้อมูลคิวเมื่อ rollback

### 4. ตั้ง Windows Task Scheduler

ตั้งให้ทำงานทุก 1 นาที แม้ไม่มีผู้ใช้ login เครื่อง โดยใช้บัญชีที่อ่านไฟล์แอปและเชื่อมต่อ SQL Server/SMTP ได้

| ช่องตั้งค่า | ค่าที่ใช้ |
| --- | --- |
| Program/script | `E:\xampp\php\php.exe` |
| Add arguments | `artisan po:mail-work` |
| Start in | `E:\xampp\htdocs\menam-online` |
| เมื่อมีงานเดิมกำลังทำงานอยู่ | **Do not start a new instance** — ไม่เริ่มงานซ้อน |

อย่ากำหนดเวลาบังคับหยุดงานสั้นเกินไป เพราะ Windows ไม่รองรับการบังคับ timeout ของแต่ละงานผ่าน Unix signal ของ Laravel ให้ใช้ worker เพียงตัวเดียว และกำหนด SMTP timeout ให้มีเวลาสิ้นสุดใน mailer ที่ใช้งานจริงตามความจำเป็น

ตรวจว่างานถูกเปิดใช้งานและดูผลการรันจริง การบันทึกตารางเวลาอย่างเดียวไม่ได้ยืนยันว่า worker ทำงานแล้ว

### 5. เปิดใช้คิว

ตั้ง `PO_MAIL_QUEUE_ENABLED=true` ใน `.env` ของเครื่องปลายทาง จากนั้นรัน `artisan config:cache` ด้วย PHP ของเครื่องนั้น และ restart Apache/PHP ผ่านเครื่องมือจัดการ service ที่ใช้อยู่

คงค่า `QUEUE_CONNECTION` ส่วนกลางไว้ตามเดิม หาก Composer ใช้ authoritative class map ให้สร้าง autoload ใหม่บนเครื่องปลายทางด้วย

### 6. ตรวจผลบนเว็บจริง

ทดสอบการอนุมัติจริงหนึ่งรายการที่ได้รับอนุญาต และตรวจว่า:

- เว็บตอบกลับหลังเพิ่มงานเข้าคิว โดยไม่รอส่งเมลครบทุกคน
- พบ `PO notification queued` ตามด้วย `PO notification sent`
- ผู้รับและ BCC ถูกต้อง และจำนวนงานค้างกลับเป็นศูนย์
- อาจต้องรอสูงสุดประมาณหนึ่งนาทีก่อนถึงรอบเริ่ม worker
- ดาวน์โหลด PDF ของทั้ง Wire และ Plus ได้เมื่อ ERP session ใช้งานได้
- เมื่อ ERP session ออกจากระบบแล้ว เว็บแสดงข้อความให้เชื่อมต่อใหม่อย่างชัดเจน

**ใช้ `po:mail-work` ไม่ใช้ `queue:work po-mail` แทน** เพราะคำสั่งเฉพาะนี้ตั้งค่าที่เก็บงานส่งไม่สำเร็จแยกสำหรับ PO

## ตรวจคิวและสั่งส่งใหม่

เปิด SSMS แล้วเลือกฐานข้อมูลที่แอปใช้ผ่าน connection `sqlsrv_menam` คำสั่งต่อไปนี้อ่านข้อมูลเท่านั้น:

```sql
SELECT COUNT(*) AS pending_jobs, MIN(created_at) AS oldest_created_unix
FROM dbo.po_mail_jobs;
SELECT TOP (20) uuid, failed_at
FROM dbo.po_mail_failed_jobs
ORDER BY failed_at DESC;
```

`pending_jobs` คือจำนวนงานที่ยังอยู่ในคิว และ `oldest_created_unix` คือเวลาสร้างงานเก่าสุดในรูปแบบ Unix timestamp ส่วนรายการจาก `po_mail_failed_jobs` คืองานที่ส่งไม่สำเร็จหลังลองครบแล้ว

หลังแก้ปัญหา SMTP ให้ตรวจงานที่ต้องการส่งใหม่ แล้วแทน `FAILED_JOB_UUID` ด้วย UUID ของงานนั้นก่อนรันบนเครื่องจริง **คำสั่งนี้จะส่งเมล และ worker อาจประมวลผลงานอื่นที่พร้อมส่งในคิวด้วย**:

```powershell
& 'E:\xampp\php\php.exe' artisan po:mail-work --retry=FAILED_JOB_UUID
```

## ย้อนกลับไปส่งเมลแบบเดิม

ตั้ง `PO_MAIL_QUEUE_ENABLED=false` แล้วสร้าง config cache ใหม่และ restart Apache

ให้ worker รุ่นที่รองรับคิวนี้ทำงานต่อจนส่งงานค้างหมด อย่าลบตารางคิวหรือย้อนโค้ด worker เป็นรุ่นเก่าขณะยังมีงานค้าง

## แนวทางแก้ข้อ 1: SQL Server ใช้ชื่อ login เป็น forge

User model ในโค้ดต้นทางใช้ connection `sqlsrv_menam` และอ่านชื่อผู้ใช้จาก `env('SQLSRV_USERNAME', 'forge')` หมายความว่าหากไม่มีค่า `SQLSRV_USERNAME` จะใช้ `forge` แทน

ตรวจการจับคู่ตัวแปรใน **โค้ดที่ติดตั้งจริง** ก่อนแก้ `.env` โดยตรวจรายการต่อไปนี้บนเครื่องจริง:

- `SQLSRV_HOST`, `SQLSRV_PORT`, `SQLSRV_DATABASE`, `SQLSRV_USERNAME` และ `SQLSRV_PASSWORD`
- config cache ที่กำลังใช้อยู่
- environment ของ service Apache
- มีแอปอีกชุดหรืออีก instance ที่ใช้ค่าต่างกันหรือไม่

อย่าพิมพ์รหัสผ่านหรือ config cache ทั้งชุดออกมา และอย่าสร้าง SQL login ชื่อ forge เพียงเพื่อกลบปัญหาค่าตั้งค่าหาย

หลังแก้ให้ใช้บัญชีและรหัสผ่านที่ตั้งใจใช้อยู่เดิมแล้ว ให้สร้าง config ใหม่ด้วย PHP ของเครื่องจริง:

```powershell
Set-Location -LiteralPath 'E:\xampp\htdocs\menam-online'
& 'E:\xampp\php\php.exe' artisan config:clear
& 'E:\xampp\php\php.exe' artisan config:cache
```

Restart Apache/PHP ผ่านเครื่องมือจัดการ service ที่ใช้อยู่ จากนั้นทดสอบเปิดเว็บขณะ login หลายครั้งและตรวจ log ล่าสุด อย่าอาศัยผลจาก CLI อย่างเดียว เพราะอาจใช้ environment ต่างจากเว็บ

Log ที่แนบมายังไม่ยืนยันว่าต้นเหตุคือ environment หาย, cache เก่า หรือแอปอีก instance และ **ไม่ต้องสร้าง APP_KEY ใหม่**

## แนวทางแก้ข้อ 2: หน้า error พังซ้ำขณะโหลด sidebar

เปลี่ยน `resources/views/errors/_base.blade.php` ให้เป็น HTML แยก ใช้ CSS ภายในไฟล์หรือไฟล์ในเครื่อง โดยไม่โหลด `layouts.layout`, sidebar, `auth()->user()`, การค้นหาเมนู/สิทธิ์ หรือ helper ที่พึ่งฐานข้อมูล

คงข้อความเฉพาะแต่ละสถานะ, ข้อผิดพลาดจากการตรวจข้อมูล, HTTP status และลิงก์กลับหน้าแรกที่ใช้งานได้ไว้ จากนั้นทดสอบหน้า 404/419/422/500 ขณะเชื่อมต่อฐานข้อมูลไม่ได้

หลังติดตั้งให้ล้าง view ที่ compile ไว้ด้วย `artisan view:clear`

ข้อ 1–2 เป็นแนวทางแก้ไข ในงานแก้ข้อ 3 นี้ยังไม่ได้เปลี่ยน config บนเครื่องจริงหรือแก้ layout ของหน้า error ร่วม
