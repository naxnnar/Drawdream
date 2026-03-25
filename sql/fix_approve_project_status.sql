-- ตั้งค่า default value ให้ approve_project เป็น 'pending' และไม่อนุญาต NULL
ALTER TABLE project MODIFY approve_project VARCHAR(20) NOT NULL DEFAULT 'pending';

-- อัปเดตข้อมูลที่เป็น NULL ให้เป็น 'pending' (กรณีมีข้อมูลเก่า)
UPDATE project SET approve_project = 'pending' WHERE approve_project IS NULL;
