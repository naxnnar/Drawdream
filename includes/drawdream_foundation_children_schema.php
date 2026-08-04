<?php
// includes/drawdream_foundation_children_schema.php — คอลัมน์ foundation_children (migration cache ใน db.php)
declare(strict_types=1);

require_once __DIR__ . '/drawdream_schema_once.php';

/**
 * ใช้โดย db.php — เพิ่ม/ปรับคอลัมน์ที่ฟอร์มเด็กใช้ (เดิมรันใน foundation_add_children.php ทุก session)
 */
function drawdream_ensure_foundation_children_columns(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    $neededColumns = [
        'foundation_name' => 'ALTER TABLE foundation_children ADD COLUMN foundation_name VARCHAR(255) NULL',
        'child_name' => 'ALTER TABLE foundation_children ADD COLUMN child_name VARCHAR(255) NULL',
        'birth_date' => 'ALTER TABLE foundation_children ADD COLUMN birth_date DATE NULL',
        'age' => 'ALTER TABLE foundation_children ADD COLUMN age INT NULL',
        'education' => 'ALTER TABLE foundation_children ADD COLUMN education VARCHAR(255) NULL',
        'dream' => 'ALTER TABLE foundation_children ADD COLUMN dream VARCHAR(255) NULL',
        'likes' => 'ALTER TABLE foundation_children ADD COLUMN likes VARCHAR(100) NULL',
        'wish' => 'ALTER TABLE foundation_children ADD COLUMN wish VARCHAR(255) NULL',
        'wish_cat' => 'ALTER TABLE foundation_children ADD COLUMN wish_cat VARCHAR(100) NULL',
        'bank_name' => 'ALTER TABLE foundation_children ADD COLUMN bank_name VARCHAR(100) NULL',
        'child_bank' => 'ALTER TABLE foundation_children ADD COLUMN child_bank VARCHAR(100) NULL',
        'status' => 'ALTER TABLE foundation_children ADD COLUMN status VARCHAR(100) NULL',
        'photo_child' => 'ALTER TABLE foundation_children ADD COLUMN photo_child VARCHAR(255) NULL',
        'approve_profile' => "ALTER TABLE foundation_children ADD COLUMN approve_profile VARCHAR(50) DEFAULT 'รอดำเนินการ'",
        'approve_at' => 'ALTER TABLE foundation_children ADD COLUMN approve_at DATETIME NULL',
        'update_text' => 'ALTER TABLE foundation_children ADD COLUMN update_text LONGTEXT NULL',
        'update_at' => 'ALTER TABLE foundation_children ADD COLUMN update_at DATETIME NULL',
        'update_images' => 'ALTER TABLE foundation_children ADD COLUMN update_images LONGTEXT NULL',
        'created_at' => 'ALTER TABLE foundation_children ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ];
    foreach ($neededColumns as $col => $ddl) {
        $chk = @$conn->query("SHOW COLUMNS FROM foundation_children LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query($ddl);
        }
    }

    $childBankCol = @$conn->query("SHOW COLUMNS FROM foundation_children LIKE 'child_bank'");
    if ($childBankCol && ($childBankRow = $childBankCol->fetch_assoc())) {
        $childBankType = (string)($childBankRow['Type'] ?? '');
        if (!preg_match('/varchar\((\d+)\)/i', $childBankType, $childBankMatch) || (int)$childBankMatch[1] < 32) {
            @$conn->query('ALTER TABLE foundation_children MODIFY COLUMN child_bank VARCHAR(32) NULL');
        }
    }

    $cDropReject = @$conn->query("SHOW COLUMNS FROM foundation_children LIKE 'reject_reason'");
    if ($cDropReject && $cDropReject->num_rows > 0) {
        @$conn->query('ALTER TABLE foundation_children DROP COLUMN reject_reason');
    }

    $cCreated = @$conn->query("SHOW COLUMNS FROM foundation_children LIKE 'created_at'");
    $cApprove = @$conn->query("SHOW COLUMNS FROM foundation_children LIKE 'approve_at'");
    if ($cCreated && $cCreated->num_rows > 0 && $cApprove && $cApprove->num_rows > 0) {
        @$conn->query(
            'UPDATE foundation_children SET created_at = approve_at
             WHERE created_at IS NULL AND approve_at IS NOT NULL'
        );
    }
    $tAdmin = @$conn->query("SHOW TABLES LIKE 'admin'");
    if ($cCreated && $cCreated->num_rows > 0 && $tAdmin && $tAdmin->num_rows > 0) {
        @$conn->query(
            "UPDATE foundation_children c
             INNER JOIN `admin` a
               ON a.target_id = c.child_id
              AND LOWER(TRIM(COALESCE(a.target_entity, ''))) = 'child'
             SET c.created_at = a.action_at
             WHERE c.created_at IS NULL AND a.action_at IS NOT NULL"
        );
    }
}
