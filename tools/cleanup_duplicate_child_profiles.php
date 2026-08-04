<?php
/**
 * CLI: ลบโปรไฟล์เด็กซ้ำในฐานข้อมูล (คง child_id น้อยสุดต่อชุดข้อมูล)
 * Usage: php tools/cleanup_duplicate_child_profiles.php [foundation_id]
 *        ไม่ระบุ foundation_id = ทุกมูลนิธิ
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/child_sponsorship.php';

$foundationId = isset($argv[1]) ? (int)$argv[1] : 0;

if ($foundationId > 0) {
    $removed = drawdream_cleanup_duplicate_child_profiles_for_foundation($conn, $foundationId);
    echo "foundation_id={$foundationId} removed={$removed}\n";
    exit(0);
}

$rs = $conn->query('SELECT foundation_id FROM foundations ORDER BY foundation_id ASC');
if (!$rs) {
    fwrite(STDERR, "query failed\n");
    exit(1);
}

$total = 0;
while ($row = $rs->fetch_assoc()) {
    $fid = (int)($row['foundation_id'] ?? 0);
    if ($fid <= 0) {
        continue;
    }
    $removed = drawdream_cleanup_duplicate_child_profiles_for_foundation($conn, $fid);
    if ($removed > 0) {
        echo "foundation_id={$fid} removed={$removed}\n";
        $total += $removed;
    }
}
echo "total_removed={$total}\n";
