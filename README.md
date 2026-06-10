# DrawDream

เว็บ PHP + MySQL สำหรับมูลนิธิ / บริจาค / อุปการะเด็ก

## รันเครื่องพัฒนา (local)

- Windows: `.\run_dev_server.ps1` หรือ `run_dev_server.bat`

## การตั้งค่า

1. สำเนา `.env.example` เป็น `.env` ที่รากโปรเจกต์ แล้วใส่ค่าจริง (ไฟล์ `.env` ถูก gitignore แล้ว)
   - **Omise:** `OMISE_PUBLIC_KEY`, `OMISE_SECRET_KEY`, `OMISE_WEBHOOK_SECRET`
   - **Cron อุปการะ (HTTP):** `DRAWDREAM_SUBSCRIPTION_CRON_SECRET` — สร้างด้วย `openssl rand -hex 32`
2. สำเนา `config/db.local.example.php` เป็น `config/db.local.php` แล้วใส่ค่า MySQL (หรือใช้ `DB_*` ใน `.env`)
3. Google OAuth: `config/google_oauth.local.php` หรือตัวแปร `GOOGLE_*` ใน `.env`

`payment/config.php` อ่านคีย์จาก environment เท่านั้น — **ไม่เก็บ secret ใน Git**

## เอกสาร Server Requirement + ติดตั้ง/Config (ตอบอาจารย์)

- [`docs/server-requirement-and-installation.md`](docs/server-requirement-and-installation.md)

## TCPDF (รายงาน PDF แอดมิน)

- ติดตั้ง/อัปเดต: `.\tools\install_tcpdf.ps1` (วางที่ `lib/tcpdf/`)

## สคริปต์ย้ายไฟล์/ข้อมูล (รันด้วยมือ ไม่ใช่ส่วนของเว็บ)

จาก root โปรเจกต์:

```bash
php tools/migrations/migrate_evidence_media.php
php tools/migrations/migrate_foundation_profile_images.php
php tools/migrations/migrate_project_album.php
```

## ฐานข้อมูลเวอร์ชันเก่า (ครั้งเดียว)

ถ้าตาราง `foundation_children` ยังมีคอลัมน์ `is_hidden` ที่ไม่ใช้แล้ว:

```sql
ALTER TABLE foundation_children DROP COLUMN is_hidden;
```

(สำรองฐานก่อนรัน)

## การแจ้งเตือน (ในแอป — ไม่ใช่อีเมลอัตโนมัติ)

DrawDream **ไม่ใช้ PHPMailer / SMTP** สำหรับแจ้งเตือนหลัก ระบบใช้ตาราง **`notifications`** + กระดิ่งใน navbar (`notifications.php`)

| สิ่งที่ทำ | ไฟล์หลัก |
|-----------|----------|
| บันทึกแจ้งเตือน | `includes/notification_audit.php` → `drawdream_send_notification()` |
| ใบเสร็จหลังชำระ | `includes/e_receipt.php` → แจ้งในแอป (ลิงก์ `donation_receipt.php`) |
| อ่านแล้ว | `mark_notif_read.php` — ตั้ง `is_read=1` (ไม่ลบแถว) |
| แอดมินประกาศ | `admin_dashboard.php` → `drawdream_admin_broadcast_notifications()` |
| อีเมลด้วยมือ | `admin_donor_email.php` — เปิด `mailto:` ในโปรแกรมอีเมลของเครื่อง |

คิวงานแอดมิน (อนุมัติเด็ก/โครงการ/สิ่งของ) อยู่ที่ `admin_notifications.php` + ตาราง `admin` (audit) แยกจากกระดิ่งผู้บริจาค/มูลนิธิ

## CSRF (ฟอร์ม POST)

- Helper: `includes/csrf.php` (โหลดผ่าน `db.php`)
- ทุกฟอร์ม POST ควรมี `<?= drawdream_csrf_field() ?>`
- Handler ตรวจด้วย `drawdream_csrf_require_valid($redirect)` หรือ `drawdream_csrf_verify()`
- ชื่อฟิลด์: `csrf` — token หนึ่งตัวต่อ session

## เอกสารอื่นใน `docs/`

- [`drawdream-flow-walkthrough.md`](docs/drawdream-flow-walkthrough.md) — Flow เด็ก / โครงการ / สิ่งของ
- [`drawdream-features-code-walkthrough.md`](docs/drawdream-features-code-walkthrough.md) — โค้ด + อธิบายฟีเจอร์
