# สรุปไฟล์ตามฟีเจอร์ (ใบเสร็จ · อัปเดตผลลัพธ์ · แอดมินดูผู้บริจาค · รายงานวิเคราะห์)

เอกสารนี้สรุปว่าโค้ดส่วนที่เกี่ยวกับ **ใบเสร็จอิเล็กทรอนิกส์** · **การอัปเดตผลลัพธ์ฝั่งมูลนิธิ** · **แอดมินดูข้อมูลผู้บริจาค/บริจาค** และ **รายงานเชิงวิเคราะห์** กระจายอยู่ที่ไฟล์ใดบ้าง (อ้างอิงจากโครงสร้างปัจจุบัน)

---

## 1. ใบเสร็จอิเล็กทรอนิกส์ (E-Receipt)

| บทบาท | ไฟล์ |
|--------|------|
| **ฟังก์ชันหลัก** — หา `donate_id` จาก Omise charge ที่จ่ายสำเร็จ และส่งแจ้งเตือนให้ผู้บริจาคไปเปิดใบเสร็จ | `includes/e_receipt.php` (`drawdream_receipt_completed_donation_id_by_charge`, `drawdream_send_e_receipt_notification_by_donate_id`) |
| **หน้าแสดงใบเสร็จ** — บุคคลธรรมดา / นิติบุคคล, จำกัดสิทธิ์เจ้าของรายการหรือแอดมิน | `donation_receipt.php` |
| **แจ้งเตือนกลาง** — บันทึกลงตาราง `notifications` | `includes/notification_audit.php` (`drawdream_send_notification`) |

**จุดที่เรียกส่งแจ้งเตือนใบเสร็จหลังชำระสำเร็จ (ตัวอย่างหลัก):**

- `payment/check_project_payment.php` — บริจาคโครงการ  
- `payment/check_needlist_payment.php` — บริจาครายการสิ่งของ  
- `payment/check_child_payment.php` — บริจาคเด็ก (ครั้งเดียว)  
- `payment/omise_webhook.php` — webhook ยืนยันการชำระ  
- `payment/cron_child_subscription_charges.php` — หักเงินอุปการะเด็กรายรอบ  
- `payment/child_subscription_create.php` — สร้างอุปการะแบบสมัคร (มีใบเสร็จรายการแรก)

**หมายเหตุ:** ใบเสร็จเป็น HTML บนหน้า `donation_receipt.php` ไม่ได้สร้าง PDF ใน `e_receipt.php` โดยตรง (ตามคอมเมนต์ในไฟล์)

---

## 2. อัปเดตผลลัพธ์ (มูลนิธิ)

| ประเภท | ไฟล์หน้า / จุดสำคัญ |
|--------|---------------------|
| **โครงการ** — โพสต์ความคืบหน้า/ผลหลังระดมทุนครบ (ลิงก์จากแจ้งเตือนโครงการ) | `foundation_post_update.php` |
| **รายการสิ่งของ (needlist)** — โพสต์ผลหลังยอดรวมครบเป้า | `foundation_post_needlist_result.php` |
| **เด็กในอุปการะ** — บันทึกผลลัพธ์/ผลกระทบต่อเด็ก | `foundation_child_outcome.php` |

**ที่เกี่ยวข้องเสริม:**

- `needlist_result.php` — หน้าสาธารณะ/สรุปผลด้านสิ่งของ (มุมมองผู้บริจาค ตาม `foundation_id`)  
- `project_result.php` — หน้าสรุป/ผลโครงการฝั่งสาธารณะ  
- `payment/check_needlist_payment.php` — หลังชำระสำเร็จ มี logic แจ้งเตือนมูลนิธิเมื่อ **ยอดรวมสิ่งของครบเป้า** ให้ไปอัปเดตผลที่ `foundation_post_needlist_result.php` (กระดิ่งมูลนิธิ)

---

## 3. แอดมิน — ข้อมูลผู้บริจาคและการบริจาค

| หน้าที่ | ไฟล์ |
|---------|------|
| **ภาพรวมผู้บริจาค** — ยอดสะสม ความถี่ ช่องทางติดต่อ | `admin_donors.php` |
| **เตรียมส่งอีเมลถึงผู้บริจาค** (เปิดเมลไคลเอนต์) | `admin_donor_email.php` |
| **ประวัติ/ยอดบริจาคต่อเด็ก** | `admin_child_donations.php` |
| **ยอดรวมตามมูลนิธิ / โครงการ / สิ่งของ** (มุมมองแยก) | `admin_foundation_totals.php`, `admin_project_totals.php`, `admin_needlist_totals.php` |
| **งาน escrow / โอน / สถานะหลังครบยอด** | `admin_escrow.php` |

**ฝั่งผู้บริจาค (ไม่ใช่แอดมิน แต่เกี่ยวกับใบเสร็จ/โปรไฟล์):** `profile.php` (รายการบริจาค/ลิงก์ใบเสร็จ ตามที่ระบบรองรับ), `donor_update_profile.php`

---

## 4. รายงานเชิงวิเคราะห์ (Analytics)

| บทบาท | ไฟล์ |
|--------|------|
| **คำนวณข้อมูลสถิติต่อมูลนิธิ** (ยอดแยกหมวดเด็ก/โครงการ/สิ่งของ ฯลฯ) | `includes/foundation_analytics.php` |
| **สร้าง HTML รายงาน (fragment กลาง)** ใช้ทั้งแอดมินและมูลนิธิ | `includes/foundation_analytics_report_html.php` |
| **แอดมิน — ดูรายงาน + ส่งแจ้งเตือนให้มูลนิธิเปิดดู** | `admin_foundation_analytics_view.php` |
| **แอดมิน — ส่งออก PDF / พิมพ์** | `admin_foundation_analytics_pdf.php` |
| **มูลนิธิ — ดูรายงานที่แอดมินแชร์ (ผ่านกระดิ่งหรือลิงก์)** | `foundation_analytics_report.php` |
| **ดาวน์โหลด PDF ฝั่งมูลนิธิ (ถ้ามี TCPDF)** | `foundation_analytics_report_pdf.php` |

**หน้าเข้าไปยังรายงานมูลนิธิ (ภาพรวม):** มักเชื่อมจาก `admin_foundations_overview.php` หรือแจ้งเตือนใน `notifications.php`

---

## 5. ไฟล์แนบที่ควรรู้ร่วมกัน

- `includes/notification_audit.php` — ศูนย์กลางแจ้งเตือน (ใบเสร็จ, รายงาน analytics, ฯลฯ)  
- `includes/needlist_donate_window.php` — รอบรับบริจาคสิ่งของ 1 เดือน และเงื่อนไข SQL เปิดรับบริจาค  

---

*อัปเดตตามโค้ดในรีโป — ถ้ามีไฟล์ใหม่เพิ่ม ให้เติมในตารางตามบทบาทจริง*
