# Request Form SSO: ติดตั้งและตรวจสอบ

ผู้ใช้ที่ login ใน Menam Workflow กดปุ่ม Request Form แล้วเข้า Request Form System อัตโนมัติ
ด้วย JWT RS256 ใช้ครั้งเดียว อายุไม่เกิน 120 วินาที

- Menam Workflow: เซ็น token และเก็บ private key
- Request Form System: ตรวจ token และเก็บเฉพาะ public key

เปิดใช้เมื่อตั้งค่าครบทั้งสองฝั่งแล้วเท่านั้น ค่าเริ่มต้นคือปิด

## 1. สร้าง RSA key pair

สร้างครั้งละ 1 ชุดต่อ environment และเก็บ**นอกโฟลเดอร์โปรเจกต์** ห้าม commit ไฟล์ key
ใช้ `kid` ที่บอกปีและลำดับ เช่น `menam-2026-1`

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out menam-sso-private.pem
```

```bash
openssl pkey -in menam-sso-private.pem -pubout -out menam-sso-public.pem
```

- `menam-sso-private.pem` อยู่บนเครื่อง Menam Workflow เท่านั้น จำกัดสิทธิ์ให้ account ของ web server อ่านได้
- `menam-sso-public.pem` คัดลอกไปเครื่อง Request Form System

## 2. ตั้งค่า .env

ตัวอย่างนี้ไม่มีค่าลับ path เป็นเพียงตัวอย่าง

### Menam Workflow

```dotenv
REQUEST_FORM_SSO_ENABLED=true
REQUEST_FORM_SSO_URL=https://<host>/request-form-system/sso/menam/consume
REQUEST_FORM_SSO_ISSUER=menam-workflow
REQUEST_FORM_SSO_AUDIENCE=request-form-system
REQUEST_FORM_SSO_KEY_ID=menam-2026-1
REQUEST_FORM_SSO_PRIVATE_KEY_PATH=E:/keys/menam-sso-private.pem
REQUEST_FORM_SSO_TTL_SECONDS=120
REQUEST_FORM_SSO_ALLOWED_EMAIL_DOMAINS=menamstainless.co.th
SESSION_COOKIE=menam_workflow_session
```

### Request Form System

```dotenv
MENAM_SSO_ENABLED=true
MENAM_SSO_ISSUER=menam-workflow
MENAM_SSO_AUDIENCE=request-form-system
MENAM_SSO_ALLOWED_EMAIL_DOMAINS=menamstainless.co.th
MENAM_SSO_PUBLIC_KEYS_JSON={"menam-2026-1":"E:/keys/menam-sso-public.pem"}
MENAM_SSO_CLOCK_SKEW_SECONDS=10
SESSION_COOKIE=request_form_session
```

ใช้ `/` ใน path ของ Windows เพราะ `\` ใน JSON ต้อง escape

## 3. ค่าที่ต้องตรงกัน

| Menam Workflow | Request Form System |
|---|---|
| `REQUEST_FORM_SSO_KEY_ID` | ชื่อ key ใน `MENAM_SSO_PUBLIC_KEYS_JSON` |
| `REQUEST_FORM_SSO_ISSUER` | `MENAM_SSO_ISSUER` |
| `REQUEST_FORM_SSO_AUDIENCE` | `MENAM_SSO_AUDIENCE` |
| `REQUEST_FORM_SSO_ALLOWED_EMAIL_DOMAINS` | `MENAM_SSO_ALLOWED_EMAIL_DOMAINS` |
| `REQUEST_FORM_SSO_URL` | route `sso.menam.consume` ของระบบปลายทาง |

`SESSION_COOKIE` ต้อง**ต่างกัน**ถ้าสองระบบอยู่ host เดียวกัน ไม่เช่นนั้น session จะทับกัน

## 4. ข้อกำหนดสำคัญ

- production ต้องใช้ HTTPS: ถ้า URL ปลายทางไม่ใช่ https ฝั่ง Menam จะไม่ออก token
- เวลาของสองเครื่องต้องตรงกัน (NTP): `exp` ไม่มีการผ่อนผัน clock skew ใช้กับ `iat` เท่านั้น และไม่เกิน 30 วินาที
- บัญชีทั่วไปที่มีอีเมลตรงกันจะถูกผูกครั้งแรกอัตโนมัติ และ**รหัสผ่านเดิมในระบบ Request Form จะใช้ไม่ได้อีก**
- บัญชี admin ไม่ถูกผูกอัตโนมัติ ดูขั้นตอนใน `docs/menam-sso.md` ของ request-form-system
- ผู้ใช้แก้อีเมลตัวเองใน Menam ไม่ได้ ต้องให้ผู้ดูแลแก้ที่หน้าจัดการผู้ใช้
- ถ้าเปลี่ยนอีเมลของบัญชีที่ผูกแล้ว ผู้ใช้จะเข้า SSO ไม่ได้จนกว่าจะแก้อีเมลใน Request Form ให้ตรงกัน

## 5. หมุนเวียน key

1. สร้าง key pair ใหม่ด้วย `kid` ใหม่
2. เพิ่ม public key ใหม่ใน `MENAM_SSO_PUBLIC_KEYS_JSON` โดยคง key เดิมไว้
3. เปลี่ยน `REQUEST_FORM_SSO_KEY_ID` และ `REQUEST_FORM_SSO_PRIVATE_KEY_PATH` ฝั่ง Menam
4. รออย่างน้อย 2 นาที แล้วเอา key เดิมออก

ถ้า private key รั่ว ให้เอา key เดิมออกจาก `MENAM_SSO_PUBLIC_KEYS_JSON` ทันที

## 6. ลำดับ deploy

ก่อนเริ่ม: backup ฐานข้อมูลและไฟล์ของทั้งสองระบบ แล้วเปรียบเทียบไฟล์กับ production

1. Request Form System: deploy โค้ด แล้วรัน migration 3 ไฟล์ของ SSO โดยคง `MENAM_SSO_ENABLED=false`
2. ผูกบัญชี admin ที่ต้องใช้ SSO ด้วยตนเอง
3. Menam Workflow: deploy โค้ด โดยคง `REQUEST_FORM_SSO_ENABLED=false`
4. ตั้ง `.env` ทั้งสองฝั่งและเคลียร์ cache
5. เปิดฝั่ง Request Form แล้วจึงเปิดฝั่ง Menam
6. ทดสอบด้วยบัญชีทั่วไป 1 บัญชีและดู `sso_audit_logs`

ปิดฉุกเฉิน: ตั้ง `REQUEST_FORM_SSO_ENABLED=false` แล้วเคลียร์ config cache

## 7. ตรวจสอบ

ดูเหตุการณ์ได้จากตาราง `sso_audit_logs` ของ Request Form System: คอลัมน์ `event`, `status` และ `reason`
ตารางนี้ไม่เก็บ token

เทสต์ Menam ไม่แตะฐานข้อมูลจริง:

```bash
php artisan test --filter=RequestFormSso
```
