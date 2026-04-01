-- ============================================================
-- Donor profile + ข้อมูลใบเสร็จรับเงิน (บุคคล / นิติบุคคล)
-- รันใน phpMyAdmin หรือ mysql CLI
-- ถ้าคอลัมน์มีอยู่แล้ว MySQL จะ error — ให้ข้ามบรรทัดนั้น
-- ============================================================

-- (ถ้ายังไม่มี) เบอร์โทร & รูปโปรไฟล์
-- ALTER TABLE donor ADD COLUMN phone VARCHAR(20) NULL DEFAULT NULL AFTER last_name;
-- ALTER TABLE donor ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL AFTER phone;

-- ประเภทใบเสร็จ + ข้อมูลบุคคล (อีเมล / เบอร์มือถือสำหรับออกใบเสร็จ)
ALTER TABLE donor
  ADD COLUMN receipt_type ENUM('individual', 'juristic') NOT NULL DEFAULT 'individual'
    COMMENT 'ประเภทผู้รับใบเสร็จ'
    AFTER tax_id;

ALTER TABLE donor
  ADD COLUMN receipt_email VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'อีเมลใบเสร็จ (บุคคล)'
    AFTER receipt_type;

ALTER TABLE donor
  ADD COLUMN receipt_mobile VARCHAR(20) NULL DEFAULT NULL
    COMMENT 'เบอร์มือถือใบเสร็จ (บุคคล)'
    AFTER receipt_email;

-- ข้อมูลนิติบุคคล
ALTER TABLE donor
  ADD COLUMN receipt_company_name VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'ชื่อนิติบุคคล / บริษัท'
    AFTER receipt_mobile;

ALTER TABLE donor
  ADD COLUMN receipt_company_tax_id VARCHAR(20) NULL DEFAULT NULL
    COMMENT 'เลขทะเบียนนิติบุคคล / เลขผู้เสียภาษี'
    AFTER receipt_company_name;

ALTER TABLE donor
  ADD COLUMN receipt_company_address TEXT NULL
    COMMENT 'ที่อยู่นิติบุคคล'
    AFTER receipt_company_tax_id;

ALTER TABLE donor
  ADD COLUMN receipt_company_email VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'อีเมลติดต่อ (นิติบุคคล)'
    AFTER receipt_company_address;

ALTER TABLE donor
  ADD COLUMN receipt_company_phone VARCHAR(20) NULL DEFAULT NULL
    COMMENT 'เบอร์โทรติดต่อ (นิติบุคคล)'
    AFTER receipt_company_email;
