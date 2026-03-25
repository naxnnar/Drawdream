-- เปลี่ยนค่า project_status เป็นภาษาไทย
UPDATE project SET project_status = 'รอดำเนินการ' WHERE project_status = 'pending' OR project_status = '' OR project_status IS NULL;
UPDATE project SET project_status = 'อนุมัติ' WHERE project_status = 'approved';
UPDATE project SET project_status = 'ไม่อนุมัติ' WHERE project_status = 'rejected';

-- ตั้งค่า default เป็น 'รอดำเนินการ' (ถ้าต้องการ)
ALTER TABLE project MODIFY project_status VARCHAR(30) NOT NULL DEFAULT 'รอดำเนินการ';
