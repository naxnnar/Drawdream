<?php

// includes/drawdream_project_updates_schema.php — คอลัมน์อัปเดตผลลัพธ์บน foundation_project
// สรุปสั้น: เพิ่ม/ตรวจคอลัมน์ที่ใช้เก็บข้อความและรูปผลลัพธ์โครงการ
declare(strict_types=1);

/**
 * ใช้โดย db.php — คอลัมน์ update_text, update_at, update_images ตาม schema จริง
 */
function drawdream_ensure_foundation_project_update_columns(mysqli $conn): void
{
    $cols = [
        'update_text' => 'ALTER TABLE foundation_project ADD COLUMN update_text LONGTEXT NULL DEFAULT NULL',
        'update_at' => 'ALTER TABLE foundation_project ADD COLUMN update_at DATETIME NULL DEFAULT NULL',
        'update_images' => 'ALTER TABLE foundation_project ADD COLUMN update_images LONGTEXT NULL DEFAULT NULL',
    ];
    foreach ($cols as $name => $sql) {
        $chk = @$conn->query("SHOW COLUMNS FROM foundation_project LIKE '" . $name . "'");
        // ถามฐานข้อมูลว่ามีคอลัมน์นี้ไหม — ถ้าไม่มี (num_rows = 0) ก็สร้างให้เลย
        if ($chk && $chk->num_rows === 0) {
            @$conn->query($sql);
        }
    }

    // ล้างคอลัมน์เก่าที่ไม่ใช้แล้ว
    $dropCols = ['merged_into_project_id', 'update_info'];
    foreach ($dropCols as $dropCol) {
        $chk = @$conn->query("SHOW COLUMNS FROM foundation_project LIKE '" . $dropCol . "'");
        if ($chk && $chk->num_rows > 0) {
            @$conn->query("ALTER TABLE foundation_project DROP COLUMN `{$dropCol}`");
        }
    }
}
