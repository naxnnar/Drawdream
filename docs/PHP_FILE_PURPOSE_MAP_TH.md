# แผนที่ไฟล์ PHP (DrawDream)

เอกสารนี้สรุปว่าไฟล์หลักแต่ละกลุ่มใช้ทำอะไร เพื่อช่วยค้นหาโค้ดได้เร็วขึ้น  
อัปเดตล่าสุด: เพิ่มหลังรอบปรับคอมเมนต์ในไฟล์สำคัญ

## 1) Public / Donor Pages

- `homepage.php` หน้าแรก
- `children_.php` รายชื่อเด็ก (การ์ดเด็ก, แบ่งโซนยังไม่ได้อุปการะ/มีผู้อุปการะ)
- `children_donate.php` หน้าอุปการะเด็กรายบุคคล (รายวัน/เดือน/ปี)
- `project.php` รายการโครงการรับบริจาค
- `project_result.php` หน้าผลลัพธ์โครงการที่เสร็จสิ้น
- `foundation.php` หน้ามูลนิธิ/รายการสิ่งของของมูลนิธิ
- `foundation_donate_info.php` ข้อมูลมูลนิธิก่อนบริจาค
- `needlist_result.php` หน้าผลลัพธ์การจัดซื้อ/สิ่งของ
- `about.php` หน้าเกี่ยวกับเรา + FAQ

## 2) Payment Flow

- `payment/payment_project.php` เริ่มชำระเงินบริจาคโครงการ
- `payment/check_project_payment.php` ยืนยันผลหลัง Omise (อัปเดต donation + project)
- `payment/child_donate.php` เริ่มบริจาคเด็ก
- `payment/check_child_payment.php` ยืนยันผลบริจาคเด็ก
- `payment/foundation_donate.php` บริจาคให้มูลนิธิ
- `payment/check_needlist_payment.php` ยืนยันผลบริจาครายการสิ่งของ
- `payment/scan_qr.php` หน้าสแกน QR
- `payment/abandon_qr.php` ยกเลิกรายการ QR ค้าง
- `payment/omise_webhook.php` endpoint webhook จาก Omise

## 3) Receipt / Donation History

- `profile.php` โปรไฟล์ผู้ใช้ + ประวัติบริจาค
- `donation_receipt.php` ใบเสร็จอิเล็กทรอนิกส์รายรายการ
- `donation_receipts.php` รายการใบเสร็จทั้งหมด (ไฟล์ยังอยู่ในระบบ)
- `includes/e_receipt.php` helper หา donate_id และส่ง notification ใบเสร็จ

## 4) Foundation Management

- `foundation_add_children.php` เพิ่ม/แก้ไขโปรไฟล์เด็ก
- `foundation_child_outcome.php` อัปเดตผลลัพธ์เด็กที่อุปการะครบเงื่อนไข
- `foundation_add_project.php` เพิ่มโครงการ
- `foundation_project_view.php` ดูรายละเอียดโครงการ
- `foundation_post_update.php` โพสต์อัปเดตผลลัพธ์โครงการ
- `foundation_add_need.php` เพิ่มรายการสิ่งของ
- `foundation_need_view.php` ดูรายละเอียดรายการสิ่งของ
- `foundation_post_needlist_result.php` โพสต์ผลลัพธ์รายการสิ่งของ

## 5) Admin Pages

- `admin_dashboard.php` dashboard แอดมิน (การ์ดสถิติ + กราฟรายวัน)
- `admin_dashboard_chart_data.php` endpoint JSON สำหรับกราฟ dashboard
- `admin_children_overview.php` ภาพรวมเด็กในระบบ
- `admin_projects_directory.php` รายชื่อโครงการทั้งหมด
- `admin_needlist_directory.php` รายการสิ่งของทั้งหมด
- `admin_foundations_overview.php` รายชื่อมูลนิธิทั้งหมด
- `admin_approve_children.php` อนุมัติโปรไฟล์เด็ก
- `admin_approve_projects.php` อนุมัติโครงการ
- `admin_approve_needlist.php` อนุมัติรายการสิ่งของ
- `admin_approve_foundation.php` อนุมัติบัญชีมูลนิธิ
- `admin_escrow.php` บริหาร escrow และโอนเงินเข้ามูลนิธิ

## 6) Shared Includes (Core)

- `db.php` การเชื่อมต่อฐานข้อมูล
- `navbar.php` แถบนำทาง + แจ้งเตือน
- `includes/notification_audit.php` ส่ง/บันทึกแจ้งเตือน
- `includes/child_sponsorship.php` logic อุปการะเด็กและการแสดงผลผู้สนับสนุน
- `includes/donate_category_resolve.php` map ประเภท donation
- `includes/foundation_account_verified.php` ตรวจสถานะอนุมัติบัญชีมูลนิธิ
- `includes/google_oauth.php` helper Google OAuth
- `includes/project_donation_dates.php` utility วันปิดรับ/ช่วงโครงการ
- `includes/drawdream_project_status.php` utility สถานะโครงการ

## หมายเหตุการอ่านโค้ดเร็ว

- ถ้าหาจุด “หลังชำระเงินเสร็จ” ให้เริ่มที่ไฟล์ `payment/check_*.php`
- ถ้าหา “ทำไมปุ่มเปลี่ยน/เปิดไม่ได้” ให้ดูไฟล์หน้าเพจ + include status helper ที่เรียกใช้
- ถ้าหา “แจ้งเตือนมาจากไหน” ให้เริ่มที่ `includes/notification_audit.php`
- ถ้าหา “ใบเสร็จ” ให้เริ่มที่ `donation_receipt.php` และ `includes/e_receipt.php`
