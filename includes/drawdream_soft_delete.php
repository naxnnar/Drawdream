<?php

declare(strict_types=1);

/**
 * เพิ่มคอลัมน์สำหรับ soft delete (ลบแล้วแถวอยู่ ข้อมูลอ้างอิงไม่ถูกลบ)
 */
function drawdream_ensure_soft_delete_columns(mysqli $conn): void
{
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'deleted_at'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_children ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL');
    }
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'profile_delete_reason'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_children ADD COLUMN profile_delete_reason TEXT NULL DEFAULT NULL');
    }
    $chk = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'deleted_at'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_project ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL');
    }
    $chk = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'project_delete_reason'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE foundation_project ADD COLUMN project_delete_reason TEXT NULL DEFAULT NULL');
    }
}
