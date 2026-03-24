-- fix_children_schema.sql
-- หน้าที่: ปรับตาราง Children ให้ตรงกับชื่อคอลัมน์ที่โค้ดใช้งานจริง
-- ใช้กับ phpMyAdmin / MySQL (แนะนำ backup ก่อนรัน)

START TRANSACTION;

-- 1) เพิ่มคอลัมน์ที่โค้ดใช้งานจริง (ถ้ายังไม่มี)
ALTER TABLE Children ADD COLUMN IF NOT EXISTS foundation_name VARCHAR(255) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS child_name VARCHAR(255) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS age INT NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS education VARCHAR(255) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS dream VARCHAR(255) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS likes VARCHAR(100) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS wish VARCHAR(255) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS wish_cat VARCHAR(100) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS child_bank VARCHAR(100) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS qr_account_image VARCHAR(255) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS photo_child VARCHAR(255) NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS approve_profile VARCHAR(50) NULL DEFAULT 'รอดำเนินการ';
ALTER TABLE Children ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS reject_reason TEXT NULL;
ALTER TABLE Children ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL;

-- 2) ย้ายข้อมูลจาก schema เก่า -> schema ใหม่
-- ชื่อเด็ก: ใช้ first_name + last_name ก่อน, รองลงมา nickname
UPDATE Children
SET child_name = TRIM(CONCAT_WS(' ', first_name, last_name))
WHERE (child_name IS NULL OR child_name = '')
  AND (
    (first_name IS NOT NULL AND first_name <> '')
    OR (last_name IS NOT NULL AND last_name <> '')
  );

UPDATE Children
SET child_name = nickname
WHERE (child_name IS NULL OR child_name = '')
  AND nickname IS NOT NULL
  AND nickname <> '';

-- การศึกษา
UPDATE Children
SET education = education_level
WHERE (education IS NULL OR education = '')
  AND education_level IS NOT NULL
  AND education_level <> '';

-- รูปเด็ก
UPDATE Children
SET photo_child = profile_image
WHERE (photo_child IS NULL OR photo_child = '')
  AND profile_image IS NOT NULL
  AND profile_image <> '';

-- ความต้องการ/เงื่อนไขพิเศษ
UPDATE Children
SET wish = special_needs
WHERE (wish IS NULL OR wish = '')
  AND special_needs IS NOT NULL
  AND special_needs <> '';

UPDATE Children
SET wish = medical_condition
WHERE (wish IS NULL OR wish = '')
  AND medical_condition IS NOT NULL
  AND medical_condition <> '';

-- เติมชื่อมูลนิธิจาก foundation_profile
UPDATE Children c
LEFT JOIN foundation_profile fp ON fp.foundation_id = c.foundation_id
SET c.foundation_name = fp.foundation_name
WHERE (c.foundation_name IS NULL OR c.foundation_name = '')
  AND fp.foundation_name IS NOT NULL;

-- คำนวณอายุจากวันเกิด
UPDATE Children
SET age = TIMESTAMPDIFF(YEAR, birth_date, CURDATE())
WHERE birth_date IS NOT NULL
  AND (age IS NULL OR age = 0);

-- 3) ซิงก์ย้อนกลับจาก schema ใหม่ -> schema เก่า (สำหรับแถวเดิมที่ยังเป็น NULL)
UPDATE Children
SET first_name = child_name
WHERE (first_name IS NULL OR first_name = '')
  AND child_name IS NOT NULL
  AND child_name <> '';

UPDATE Children
SET nickname = child_name
WHERE (nickname IS NULL OR nickname = '')
  AND child_name IS NOT NULL
  AND child_name <> '';

UPDATE Children
SET education_level = education
WHERE (education_level IS NULL OR education_level = '')
  AND education IS NOT NULL
  AND education <> '';

UPDATE Children
SET profile_image = photo_child
WHERE (profile_image IS NULL OR profile_image = '')
  AND photo_child IS NOT NULL
  AND photo_child <> '';

UPDATE Children
SET special_needs = wish
WHERE (special_needs IS NULL OR special_needs = '')
  AND wish IS NOT NULL
  AND wish <> '';

UPDATE Children
SET medical_condition = wish
WHERE (medical_condition IS NULL OR medical_condition = '')
  AND wish IS NOT NULL
  AND wish <> '';

UPDATE Children c
LEFT JOIN foundation_profile fp ON fp.foundation_id = c.foundation_id
SET c.school_name = COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name)
WHERE (c.school_name IS NULL OR c.school_name = '')
  AND COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) IS NOT NULL;

-- ตั้งค่าสถานะตรวจสอบเริ่มต้น
UPDATE Children
SET approve_profile = 'รอดำเนินการ'
WHERE approve_profile IS NULL OR approve_profile = '';

COMMIT;
