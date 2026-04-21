# คู่มือโครงสร้างโปรเจกต์ (อ่านง่าย)

เอกสารนี้สรุปว่าแต่ละโฟลเดอร์/ไฟล์มีหน้าที่อะไร เพื่อให้เปิดโค้ดแล้วหาไฟล์ถูกเร็วขึ้น  
เน้นภาษาง่าย เหมาะใช้เป็นแผนที่เวลา debug หรือเพิ่มฟีเจอร์

## โฟลเดอร์หลัก (ภาพรวม)

- `.env/` เก็บค่าคอนฟิกเฉพาะเครื่อง (ไม่ควร push จริง)
- `auth/` จุดเริ่มต้น/ปลายทางของ Google Login
- `config/` ไฟล์คอนฟิก local (DB / OAuth)
- `css/` สไตล์แต่ละหน้า
- `data/` ไฟล์ข้อมูลประกอบระบบ (ถ้ามี)
- `docs/` เอกสารอธิบายระบบ
- `img/` รูป static ที่ใช้ในหน้าเว็บ
- `includes/` helper กลาง / utility / schema guard
- `js/` JavaScript รวม (เช่น address selector)
- `lib/` ไลบรารีภายนอก (เช่น TCPDF)
- `payment/` flow การชำระเงินทั้งหมด
- `sql/` สคริปต์ SQL สำหรับปรับโครงสร้างข้อมูล
- `tools/` สคริปต์เครื่องมือดูแลระบบ
- `uploads/` ไฟล์ที่ผู้ใช้/มูลนิธิอัปโหลดระหว่างใช้งานจริง

---

## ไฟล์ระดับ root (หน้าหลักของระบบ)

- `.gitignore` กำหนดไฟล์/โฟลเดอร์ที่ git ไม่ติดตาม
- `db.php` เชื่อมต่อฐานข้อมูล และ bootstrap schema บางส่วน
- `navbar.php` แถบนำทาง + เมนู + แจ้งเตือน
- `homepage.php` หน้าแรกของเว็บ
- `about.php` หน้าเกี่ยวกับเรา + FAQ
- `login.php` หน้าเข้าสู่ระบบ/สมัครสมาชิก
- `logout.php` ออกจากระบบ
- `welcome.php` หน้าต้อนรับหลัง login บาง flow
- `account.php` หน้าจัดการบัญชีผู้ใช้แบบย่อ
- `policy_consent.php` หน้ายอมรับนโยบาย
- `update_profile.php` หน้าอัปเดตโปรไฟล์ผู้ใช้ (เวอร์ชันหลัก)
- `updateprofile.php` ไฟล์เก่าที่ยังคงอยู่เพื่อรองรับการเรียกเดิม

### Donor/Public Flows
- `children_.php` หน้ารวมรายชื่อเด็ก (การ์ดเด็ก)
- `children_donate.php` หน้าอุปการะเด็กรายบุคคล
- `project.php` หน้ารวมโครงการรับบริจาค
- `project_result.php` หน้าผลลัพธ์ของโครงการที่เสร็จสิ้น
- `foundation.php` หน้ารวมมูลนิธิและรายการสิ่งของ
- `foundation_donate_info.php` หน้าแสดงข้อมูลมูลนิธิก่อนบริจาค
- `needlist_result.php` หน้าผลลัพธ์รายการสิ่งของ
- `payment.php` หน้า payment flow เก่า/ตัวกลางบางกรณี
- `profile.php` หน้าโปรไฟล์ + ประวัติบริจาค + ปุ่มใบเสร็จ
- `donation_receipt.php` หน้าใบเสร็จรายรายการ
- `donation_receipts.php` หน้ารวมใบเสร็จหลายรายการ
- `notifications.php` หน้าแจ้งเตือนของผู้ใช้
- `mark_notif_read.php` endpoint ทำเครื่องหมายแจ้งเตือนว่าอ่านแล้ว

### Foundation Flows
- `foundation_public_profile.php` หน้าแสดงโปรไฟล์มูลนิธิแบบสาธารณะ
- `foundation_edit_profile.php` หน้าแก้ไขโปรไฟล์มูลนิธิ
- `foundation_notifications.php` หน้าแจ้งเตือนฝั่งมูลนิธิ
- `foundation_add_children.php` ฟอร์มเพิ่ม/แก้ไขข้อมูลเด็ก
- `foundation_child_outcome.php` อัปเดตผลลัพธ์ของเด็ก
- `foundation_add_project.php` เพิ่มโครงการ
- `foundation_project_view.php` ดูรายละเอียดโครงการ (มูลนิธิ)
- `foundation_post_update.php` โพสต์อัปเดตผลลัพธ์โครงการ
- `foundation_merge_project.php` รวม/สมทบยอดโครงการตามเงื่อนไข
- `foundation_add_need.php` เพิ่มรายการสิ่งของ
- `foundation_need_view.php` ดูรายละเอียดรายการสิ่งของ
- `foundation_post_needlist_result.php` โพสต์ผลลัพธ์รายการสิ่งของ
- `foundation_analytics_report.php` หน้า report analytics ของมูลนิธิ
- `foundation_analytics_report_pdf.php` endpoint ดาวน์โหลด report PDF ของมูลนิธิ

### Admin Flows
- `admin_dashboard.php` dashboard แอดมิน (การ์ด/กราฟ)
- `admin_dashboard_chart_data.php` endpoint JSON สำหรับกราฟ dashboard
- `admin_notifications.php` หน้าแจ้งเตือน/คิวงานแอดมิน
- `admin_children.php` จัดการข้อมูลเด็กแบบรวม
- `admin_children_overview.php` หน้าภาพรวมเด็ก (legacy/redirect use)
- `admin_view_child.php` ดูรายละเอียดเด็กรายคน
- `admin_child_donations.php` ดูยอดบริจาคของเด็กรายคน
- `admin_projects.php` หน้ารวมงานโครงการ (legacy)
- `admin_projects_directory.php` directory โครงการทั้งหมด
- `admin_view_project.php` ดูรายละเอียดโครงการรายรายการ
- `admin_project_totals.php` สรุปยอดฝั่งโครงการ
- `admin_needlist_directory.php` directory รายการสิ่งของทั้งหมด
- `admin_view_needlist.php` ดูรายละเอียด needlist รายรายการ
- `admin_needlist_totals.php` สรุปยอดฝั่ง needlist
- `admin_foundations_overview.php` หน้ารวมมูลนิธิทั้งหมด
- `admin_view_foundation.php` ดูรายละเอียดมูลนิธิรายรายการ
- `admin_foundation_totals.php` หน้าสรุปยอดมูลนิธิ
- `admin_foundation_analytics_view.php` หน้าดูรายงาน analytics มูลนิธิ
- `admin_foundation_analytics_pdf.php` endpoint PDF analytics ฝั่งแอดมิน
- `admin_donors.php` หน้ารวมผู้บริจาค
- `admin_donor_email.php` ส่งอีเมล/ติดต่อผู้บริจาค
- `admin_escrow.php` จัดการ escrow และขั้นตอนโอน
- `admin_approve_children.php` อนุมัติโปรไฟล์เด็ก
- `admin_approve_projects.php` อนุมัติโครงการ
- `admin_approve_needlist.php` อนุมัติรายการสิ่งของ
- `admin_approve_foundation.php` อนุมัติบัญชีมูลนิธิ

### Story/Content Pages
- `detail_alin.php` หน้าสตอรี่ Alin
- `detail_pin.php` หน้าสตอรี่ Pin
- `detail_san.php` หน้าสตอรี่ San

### Migration / Dev Scripts
- `migrate_project_album.php` ย้าย/ปรับข้อมูลภาพอัลบั้มโครงการ
- `migrate_foundation_profile_images.php` ย้าย/ปรับภาพโปรไฟล์มูลนิธิ
- `migrate_evidence_media.php` ย้าย/ปรับไฟล์หลักฐานผลลัพธ์
- `run_dev_server.bat` รัน dev server บน Windows (batch)
- `run_dev_server.ps1` รัน dev server บน PowerShell

---

## โฟลเดอร์ `auth/`

- `google_start.php` เริ่ม Google OAuth (redirect ไป Google)
- `google_callback.php` รับ callback จาก Google แล้วล็อกอิน/สมัคร

## โฟลเดอร์ `config/`

- `db.local.php` ค่าฐานข้อมูลเฉพาะเครื่อง local
- `google_oauth.local.php` client id/secret สำหรับ Google OAuth

## โฟลเดอร์ `css/` (ไฟล์สไตล์หลัก)

- `about.css` สไตล์หน้า About
- `admin.css` สไตล์หน้าตาราง/ปุ่มแอดมินทั่วไป
- `admin_dashboard.css` สไตล์ dashboard แอดมิน
- `admin_directory.css` สไตล์หน้า directory ของแอดมิน
- `admin_donors_insights.css` สไตล์หน้า insight ผู้บริจาค
- `admin_escrow.css` สไตล์หน้า escrow
- `admin_foundation.css` สไตล์หน้า foundation admin
- `admin_record_view.css` สไตล์หน้าดูรายละเอียด record
- `auth.css` สไตล์หน้า login/auth
- `children.css` สไตล์หน้าเด็ก/อุปการะ
- `donor_update_profile.css` สไตล์หน้าแก้โปรไฟล์ผู้บริจาค
- `foundation.css` สไตล์หน้ามูลนิธิ
- `foundation_donate_info.css` สไตล์หน้าข้อมูลมูลนิธิก่อนบริจาค
- `foundation_public_profile.css` สไตล์หน้าโปรไฟล์มูลนิธิสาธารณะ
- `homepage.css` สไตล์หน้าแรก
- `navbar.css` สไตล์ navbar และ sidebar
- `notif.css` สไตล์กล่อง/รายการแจ้งเตือน
- `payment.css` สไตล์หน้าชำระเงินหลัก
- `payment_qr.css` สไตล์หน้าสแกน QR
- `policy_consent.css` สไตล์หน้านโยบาย
- `profile.css` สไตล์หน้าโปรไฟล์
- `project.css` สไตล์หน้าโครงการ
- `system_donate.css` สไตล์หน้าบริจาคผ่านระบบบาง flow
- `thai_address.css` สไตล์คอมโพเนนต์ที่อยู่ไทย
- `welcome.css` สไตล์หน้าต้อนรับ

## โฟลเดอร์ `docs/`

- `README.md` คู่มือเริ่มต้นเอกสาร
- `SYSTEM_PRESENTATION_GUIDE.md` สรุปภาพรวมระบบ
- `PRESENTATION_CODE_WALKTHROUGH_SCRIPT_TH.md` สคริปต์พรีเซนต์โค้ดภาษาไทย
- `CODEBASE_FILE_INDEX.md` ดัชนีไฟล์โค้ดหลัก
- `PHP_FILE_PURPOSE_MAP_TH.md` แผนที่ไฟล์ PHP แบบย่อ
- `PROJECT_STRUCTURE_GUIDE_TH.md` (ไฟล์นี้) แผนที่โฟลเดอร์/ไฟล์แบบอ่านง่าย

## โฟลเดอร์ `includes/` (core helpers)

- `address_helpers.php` แปลง/ประกอบที่อยู่ไทย
- `admin_audit_migrate.php` ensure schema ฝั่ง audit admin
- `child_omise_subscription.php` logic subscription เด็กกับ Omise
- `child_sponsorship.php` logic สถานะอุปการะเด็ก
- `donate_category_resolve.php` map/get/create category_id การบริจาค
- `donate_type.php` constant ประเภทการบริจาค
- `donation_stats_panel.php` helper panel สถิติบริจาค
- `drawdream_needlist_schema.php` ensure schema needlist
- `drawdream_project_status.php` normalize/mapping สถานะโครงการ
- `drawdream_project_updates_schema.php` ensure schema อัปเดตโครงการ
- `drawdream_soft_delete.php` helper soft delete กลาง
- `escrow_funds_schema.php` ensure schema escrow
- `e_receipt.php` helper ใบเสร็จและแจ้งเตือนใบเสร็จ
- `favicon_meta.php` meta/favicon include กลาง
- `foundation_account_verified.php` ตรวจบัญชีมูลนิธิอนุมัติแล้วหรือยัง
- `foundation_analytics.php` logic analytics มูลนิธิ
- `foundation_analytics_report_html.php` render HTML fragment รายงาน analytics
- `foundation_banks.php` helper ข้อมูลบัญชีธนาคารมูลนิธิ
- `foundation_review_schema.php` ensure schema review มูลนิธิ
- `google_oauth.php` helper Google OAuth
- `needlist_donate_window.php` helper เงื่อนไขช่วงเวลา needlist
- `notification_audit.php` ส่งแจ้งเตือน + บันทึก audit
- `omise_api_client.php` client เรียก Omise API
- `omise_user_messages.php` ข้อความแสดงผลจากผลลัพธ์ Omise
- `payment_transaction_schema.php` ensure schema ตารางธุรกรรม payment
- `pending_child_donation.php` lifecycle pending->completed ของบริจาคเด็ก
- `policy_consent_content.php` เนื้อหานโยบายที่ใช้ซ้ำ
- `project_donation_dates.php` helper วันเริ่ม/สิ้นสุดบริจาคโครงการ
- `qr_payment_abandon.php` helper ยกเลิกรายการ QR ค้าง
- `site_footer.php` footer include กลาง
- `thai_address_fields.php` component ฟิลด์ที่อยู่ไทย
- `utf8_helpers.php` helper string UTF-8

## โฟลเดอร์ `js/`

- `thai_address_select.js` JS สำหรับเลือกจังหวัด/อำเภอ/ตำบล

## โฟลเดอร์ `payment/` (ทุกไฟล์สำคัญของการจ่ายเงิน)

- `config.php` คีย์/คอนฟิก Omise และค่า payment กลาง
- `omise_helpers.php` helper network เรียก Omise + fallback
- `omise_webhook.php` รับ webhook จาก Omise
- `donate_qr.php` สร้าง QR payment flow กลาง
- `scan_qr.php` หน้าแสดง QR และสถานะ
- `abandon_qr.php` ปิดรายการที่ผู้ใช้ยกเลิก/ค้าง
- `system_donate.php` flow บริจาคผ่านระบบแบบรวม
- `payment_project.php` หน้าเริ่มจ่ายเงินโครงการ
- `check_project_payment.php` ปิดธุรกรรมโครงการหลังชำระ
- `child_donate.php` หน้าเริ่มจ่ายเงินเด็ก
- `check_child_payment.php` ปิดธุรกรรมเด็กหลังชำระ
- `foundation_donate.php` หน้าเริ่มจ่ายเงินมูลนิธิ
- `check_needlist_payment.php` ปิดธุรกรรม needlist หลังชำระ
- `child_subscription_create.php` สร้าง subscription เด็ก
- `child_subscription_cancel.php` ยกเลิก subscription เด็ก
- `cron_child_subscription_charges.php` รันเก็บเงินรอบ subscription
- `run_subscription_cron.bat` batch เรียก cron subscription (Windows)
- `cacert.pem` CA bundle สำหรับ verify SSL ตอนเรียก API

## โฟลเดอร์ `sql/`

- `drop_foundation_children_is_hidden.sql` สคริปต์ปรับคอลัมน์/โครงสร้างเกี่ยวกับเด็ก

## โฟลเดอร์ `tools/`

- `cleanup_donation_subscription_duplicates.py` ล้างข้อมูลซ้ำของ donation/subscription
- `install_tcpdf.ps1` ติดตั้ง/วาง TCPDF อัตโนมัติ

---

## โฟลเดอร์ asset/runtime (อธิบายแบบใช้งานจริง)

### `img/`
- ความหมาย: รูป static ที่ออกแบบไว้ล่วงหน้า (ไม่เปลี่ยนตามผู้ใช้)
- ตัวอย่างกลุ่มไฟล์:
  - `logo-*.png`, `logodrawdream.png`, `logobanner.png` โลโก้แบรนด์
  - `partner*.png`, `project_run.png`, `foundation*.png` รูปมูลนิธิ/พาร์ทเนอร์
  - `about*.png` รูปประกอบหน้า About
  - `sdg/sdg1..sdg16` ไอคอน SDG สำหรับหน้าโครงการ

### `uploads/`
- ความหมาย: ไฟล์ที่ระบบสร้างเพิ่มตอนใช้งานจริง (ข้อมูลเปลี่ยนตลอด)
- โครงสร้าง:
  - `uploads/childern/` รูปเด็กที่อัปโหลด
  - `uploads/project/` รูปโครงการที่อัปโหลด
  - `uploads/needs/` รูปรายการสิ่งของ
  - `uploads/evidence/` รูปผลลัพธ์/หลักฐานการดำเนินงาน
  - `uploads/profiles/` รูปโปรไฟล์ผู้ใช้/มูลนิธิ
- หมายเหตุ: ชื่อไฟล์มักมี timestamp/hash จึงไม่ควร hardcode ในโค้ด

### `lib/`
- ความหมาย: ไลบรารีภายนอก (vendor)
- ตอนนี้มีหลักๆ:
  - `lib/tcpdf/` ไลบรารีสร้าง PDF
  - ไฟล์สำคัญที่เรียกบ่อย: `lib/tcpdf/tcpdf.php`
  - ไฟล์อื่นๆ ใต้ `fonts/`, `include/`, `tools/` เป็นไฟล์ประกอบของ TCPDF

---

## วิธีใช้เอกสารนี้เวลาอ่านโค้ด (เร็วที่สุด)

- หา flow หน้าเว็บ -> เริ่มจากไฟล์ root page (`children_donate.php`, `project.php`, `profile.php`)
- หา logic กลาง -> เปิด `includes/` ชื่อใกล้เคียงฟีเจอร์
- หา bug payment -> ไล่ `payment/payment_*.php` แล้วต่อ `payment/check_*.php`
- หาเรื่องแจ้งเตือน -> `includes/notification_audit.php`
- หาเรื่องใบเสร็จ -> `donation_receipt.php` + `includes/e_receipt.php`
