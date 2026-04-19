<?php
// includes/foundation_review_schema.php — คอลัมน์สถานะรีวิวโปรไฟล์มูลนิธิ
declare(strict_types=1);

function drawdream_foundation_review_ensure_schema(mysqli $conn): void
{
    $needCols = [
        'review_note' => "ALTER TABLE foundation_profile ADD COLUMN review_note TEXT NULL AFTER account_verified",
        'reviewed_at' => "ALTER TABLE foundation_profile ADD COLUMN reviewed_at DATETIME NULL AFTER review_note",
    ];
    foreach ($needCols as $col => $ddl) {
        $chk = @$conn->query("SHOW COLUMNS FROM foundation_profile LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query($ddl);
        }
    }
}
