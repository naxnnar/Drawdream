<?php
// profile_foundation_finance.php — โหลดยอดบริจาคมูลนิธิแบบ lazy (HTML fragment)

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'foundation') {
    http_response_code(403);
    echo '<div class="foundation-finance-empty">ไม่มีสิทธิ์เข้าถึง</div>';
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    http_response_code(404);
    echo '<div class="foundation-finance-empty">ไม่พบข้อมูลมูลนิธิ</div>';
    exit;
}

require_once __DIR__ . '/includes/foundation_profile_finance_load.php';
require_once __DIR__ . '/includes/foundation_profile_finance_partial.php';

$foundationId = (int)($row['foundation_id'] ?? 0);
$foundationName = trim((string)($row['foundation_name'] ?? ''));
$data = foundation_profile_load_finance($conn, $foundationId, $foundationName);

echo foundation_profile_finance_render_html($data);
