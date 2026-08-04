<?php
// tools/drop_foundation_profile_legacy_columns.php — ลบคอลัมน์ contact_person, line_id, review_note จาก foundation_profile
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

define('DRAWDREAM_RUNNING_MIGRATIONS', true);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/foundation_review_schema.php';

drawdream_foundation_profile_drop_legacy_columns($conn);

$remaining = [];
foreach (['contact_person', 'line_id', 'review_note'] as $col) {
    $chk = @$conn->query("SHOW COLUMNS FROM foundation_profile LIKE '" . $conn->real_escape_string($col) . "'");
    if ($chk && $chk->num_rows > 0) {
        $remaining[] = $col;
    }
}

if ($remaining === []) {
    echo "Dropped legacy foundation_profile columns (contact_person, line_id, review_note).\n";
    exit(0);
}

echo "Warning: columns still present: " . implode(', ', $remaining) . "\n";
exit(1);
