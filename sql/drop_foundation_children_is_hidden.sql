-- Run once after deploying code that removes is_hidden usage.
-- MySQL/MariaDB: drops column used for "ซ่อนโปรไฟล์ก่อนส่งแอดมินอีกครั้ง" (replaced by approve_profile / deleted_at flows).
ALTER TABLE foundation_children DROP COLUMN is_hidden;
