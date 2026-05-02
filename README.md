# DrawDream

เว็บ PHP + MySQL สำหรับมูลนิธิ / บริจาค / อุปการะเด็ก

## รันเครื่องพัฒนา (local)

- Windows: `.\run_dev_server.ps1` หรือ `run_dev_server.bat`

## การตั้งค่า

- สำเนา `config/db.local.example.php` เป็น `config/db.local.php` แล้วใส่ค่า MySQL
- ตัวแปร Omise / OAuth ตามที่ใช้ใน `payment/` และ `config/`

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

## เอกสารอธิบายยาว

โฟลเดอร์ `docs/` ถูกลบออกจาก working tree เพื่อลด noise — ดูย้อนหลังได้จากประวัติ Git หากเคย commit ไว้
