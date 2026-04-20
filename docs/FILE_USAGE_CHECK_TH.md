# สรุปการใช้งานไฟล์ตามภาพ (ใช้อยู่ไหม)

เอกสารนี้ตอบตรงตามคำถามว่าไฟล์ในภาพ “ใช้อยู่ไหม” และ “ใช้ทำอะไร”

## 1) กลุ่ม `sql/` และ `tools/` (จากภาพที่ 1)

### `sql/drop_foundation_children_is_hidden.sql`
- สถานะ: **ไม่ถูกเรียกอัตโนมัติใน runtime**
- หน้าที่: สคริปต์ SQL สำหรับปรับ schema
- ใช้เมื่อ: รัน migration/ซ่อมโครงสร้างข้อมูลแบบ manual
- ลบได้ไหม: **ควรเก็บ** (เป็นประวัติ/เครื่องมือซ่อม)

### `tools/cleanup_donation_subscription_duplicates.py`
- สถานะ: **ไม่ถูกเรียกอัตโนมัติในเว็บ**
- หน้าที่: ล้างข้อมูล donation/subscription ที่ซ้ำ
- ใช้เมื่อ: แอดมิน/ทีม dev เรียกเองตอน maintenance
- ลบได้ไหม: **ควรเก็บ** (เครื่องมือแก้ข้อมูล)

### `tools/install_tcpdf.ps1`
- สถานะ: **ไม่ถูกเรียกอัตโนมัติใน runtime**
- หน้าที่: ดาวน์โหลดและติดตั้ง TCPDF ไป `lib/tcpdf`
- ใช้เมื่อ: เครื่องใหม่ที่ยังไม่มี TCPDF
- ลบได้ไหม: **ควรเก็บ** (ช่วยติดตั้ง dependency ง่าย)

---

## 2) กลุ่ม `lib/` และ `payment/cacert.pem` (จากภาพที่ 2)

### `lib/tcpdf/`
- สถานะ: **ใช้อยู่**
- หลักฐานในโค้ด:
  - `admin_foundation_analytics_pdf.php`
  - `foundation_analytics_report_pdf.php`
  - `admin_foundation_analytics_view.php` และ `foundation_analytics_report.php` เช็กการมีอยู่ของ `lib/tcpdf/tcpdf.php`
- หน้าที่: ไลบรารีสร้าง PDF ฝั่งเซิร์ฟเวอร์

### `lib/tcpdf_extract/`
- สถานะ: **ไม่ใช่ runtime โดยตรง**
- หน้าที่: โฟลเดอร์ชั่วคราวจากขั้นตอนแตก zip
- ลบได้ไหม: **ลบได้ปลอดภัย** ถ้า `lib/tcpdf/` พร้อมใช้งานแล้ว

### `lib/tcpdf.zip`
- สถานะ: **ไม่ใช่ runtime โดยตรง**
- หน้าที่: ไฟล์ zip ต้นทางไว้สำรองตอนติดตั้ง
- ลบได้ไหม: **ลบได้ปลอดภัย** (ถ้าไม่ต้องการเก็บสำรอง)

### `lib/tcpdf_copy.zip`
- สถานะ: **ไม่ใช่ runtime โดยตรง**
- หน้าที่: สำเนา zip สำรอง
- ลบได้ไหม: **ลบได้ปลอดภัย**

### `payment/cacert.pem`
- สถานะ: **ใช้อยู่**
- หลักฐานในโค้ด:
  - `payment/config.php` พยายามตั้ง `OMISE_CURL_CAINFO` ไปไฟล์นี้
  - `includes/omise_api_client.php` ใช้ค่า CA bundle ไปตั้ง `CURLOPT_CAINFO`
  - `payment/omise_helpers.php` ใช้ไฟล์นี้ตอนเชื่อม HTTPS กับ Omise
- หน้าที่: CA bundle สำหรับตรวจสอบ SSL ตอนเรียก Omise API
- ลบได้ไหม: **ไม่ควรลบ** (อาจทำให้เครื่องบางเครื่องเรียก Omise ผ่าน SSL ไม่ผ่าน)

---

## 3) สรุปสั้นแบบตัดสินใจเร็ว

- **ใช้อยู่แน่ๆ (ห้ามลบ):**
  - `lib/tcpdf/`
  - `payment/cacert.pem`

- **ไม่ใช่ runtime โดยตรง แต่ควรเก็บ:**
  - `sql/drop_foundation_children_is_hidden.sql`
  - `tools/cleanup_donation_subscription_duplicates.py`
  - `tools/install_tcpdf.ps1`

- **ลบได้ปลอดภัย (ไฟล์ติดตั้ง/สำรอง):**
  - `lib/tcpdf_extract/`
  - `lib/tcpdf.zip`
  - `lib/tcpdf_copy.zip`
