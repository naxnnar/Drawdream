<?php
// includes/foundation_review_schema.php — คอลัมน์สถานะรีวิวโปรไฟล์มูลนิธิ
// สรุปสั้น: ตรวจ/เพิ่มคอลัมน์ที่ใช้เก็บผลการรีวิวโปรไฟล์มูลนิธิ
declare(strict_types=1);

require_once __DIR__ . '/drawdream_schema_once.php';

function drawdream_foundation_review_ensure_schema(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    drawdream_foundation_profile_ensure_register_columns($conn);
    drawdream_foundation_profile_drop_legacy_columns($conn);
}

/** ลบคอลัมน์ที่ไม่ใช้แล้ว (contact_person, line_id, review_note) */
function drawdream_foundation_profile_drop_legacy_columns(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach (['contact_person', 'line_id', 'review_note'] as $col) {
        $chk = @$conn->query("SHOW COLUMNS FROM foundation_profile LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows > 0) {
            @$conn->query('ALTER TABLE foundation_profile DROP COLUMN `' . $conn->real_escape_string($col) . '`');
        }
    }
}

/** คอลัมน์ที่ฟอร์มสมัครมูลนิธิต้องใช้ — เรียกก่อน INSERT ตอน register (db light ไม่รัน migration เต็ม) */
function drawdream_foundation_profile_ensure_register_columns(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $needCols = [
        'registration_number' => "ALTER TABLE foundation_profile ADD COLUMN registration_number VARCHAR(20) NULL DEFAULT NULL AFTER foundation_name",
        'bank_name' => "ALTER TABLE foundation_profile ADD COLUMN bank_name VARCHAR(100) NULL DEFAULT NULL",
        'bank_account_number' => "ALTER TABLE foundation_profile ADD COLUMN bank_account_number VARCHAR(20) NULL DEFAULT NULL",
        'bank_account_name' => "ALTER TABLE foundation_profile ADD COLUMN bank_account_name VARCHAR(200) NULL DEFAULT NULL",
    ];
    foreach ($needCols as $col => $ddl) {
        $chk = @$conn->query("SHOW COLUMNS FROM foundation_profile LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query($ddl);
        }
    }
}
