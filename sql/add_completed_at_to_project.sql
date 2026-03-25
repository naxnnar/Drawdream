-- เพิ่ม column completed_at ให้ตาราง project
ALTER TABLE project ADD COLUMN completed_at DATETIME DEFAULT NULL;