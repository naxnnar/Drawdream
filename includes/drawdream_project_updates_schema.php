<?php

// includes/drawdream_project_updates_schema.php — คอลัมน์อัปเดตผลลัพธ์บน foundation_project
// สรุปสั้น: เพิ่ม/ตรวจคอลัมน์ที่ใช้เก็บข้อความและรูปผลลัพธ์โครงการ
declare(strict_types=1);

require_once __DIR__ . '/drawdream_schema_once.php';

/**
 * ใช้โดย tools/run_migrations.php — คอลัมน์ update_text, update_at, update_images ตาม schema จริง
 */
function drawdream_ensure_foundation_project_update_columns(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
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

/**
 * คอลัมน์ฟอร์มสร้าง/แก้โครงการ (เดิมรันใน foundation_add_project.php ทุกครั้ง)
 */
function drawdream_ensure_foundation_project_form_columns(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    $chkStart = @$conn->query("SHOW COLUMNS FROM foundation_project LIKE 'start_date'");
    if ($chkStart && $chkStart->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_project ADD COLUMN start_date DATE NULL DEFAULT NULL');
    }

    $chkDonStart = @$conn->query("SHOW COLUMNS FROM foundation_project LIKE 'donation_start_date'");
    if ($chkDonStart && $chkDonStart->num_rows > 0) {
        @$conn->query('UPDATE foundation_project SET start_date = DATE(donation_start_date) WHERE start_date IS NULL AND donation_start_date IS NOT NULL');
        @$conn->query('ALTER TABLE foundation_project DROP COLUMN donation_start_date');
    }

    $chkFid = @$conn->query("SHOW COLUMNS FROM foundation_project LIKE 'foundation_id'");
    if ($chkFid && $chkFid->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_project ADD COLUMN foundation_id INT UNSIGNED NULL DEFAULT NULL AFTER foundation_name');
    }

    $chkPe = @$conn->query("SHOW COLUMNS FROM foundation_project WHERE Field = 'pending_edit_json'");
    if ($chkPe && $chkPe->num_rows > 0) {
        @$conn->query('ALTER TABLE foundation_project DROP COLUMN pending_edit_json');
    }

    $chkNeed = @$conn->query("SHOW COLUMNS FROM foundation_project LIKE 'need_info'");
    if ($chkNeed && $chkNeed->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_project ADD COLUMN need_info TEXT NULL DEFAULT NULL');
    }

    $chkProjLoc = @$conn->query("SHOW COLUMNS FROM foundation_project WHERE Field = 'location'");
    if ($chkProjLoc && $chkProjLoc->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_project ADD COLUMN location TEXT NULL DEFAULT NULL AFTER need_info');
    }

    @$conn->query(
        'UPDATE foundation_project p
         INNER JOIN foundation_profile f ON f.foundation_name = p.foundation_name AND f.foundation_id IS NOT NULL
         SET p.foundation_id = f.foundation_id
         WHERE p.foundation_id IS NULL OR p.foundation_id = 0'
    );
}
