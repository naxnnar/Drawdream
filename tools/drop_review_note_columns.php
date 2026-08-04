<?php
// tools/drop_review_note_columns.php — ลบคอลัมน์ review_note / legacy จาก foundation_profile และ foundation_needlist
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

define('DRAWDREAM_RUNNING_MIGRATIONS', true);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/foundation_review_schema.php';
require_once dirname(__DIR__) . '/includes/drawdream_needlist_schema.php';

drawdream_foundation_profile_drop_legacy_columns($conn);
drawdream_ensure_needlist_schema($conn);

$remaining = [];
foreach (['foundation_profile' => ['contact_person', 'line_id', 'review_note'], 'foundation_needlist' => ['review_note']] as $table => $cols) {
    foreach ($cols as $col) {
        $chk = @$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows > 0) {
            $remaining[] = "{$table}.{$col}";
        }
    }
}

if ($remaining === []) {
    echo "Dropped legacy review/contact columns from foundation_profile and foundation_needlist.\n";
    exit(0);
}

echo "Warning: columns still present: " . implode(', ', $remaining) . "\n";
exit(1);
