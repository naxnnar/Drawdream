<?php

// includes/drawdream_project_updates_schema.php — คอลัมน์อัปเดตผลลัพธ์บน foundation_project
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
        if ($chk && $chk->num_rows === 0) {
            @$conn->query($sql);
        }
    }
}
