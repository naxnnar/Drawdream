<?php
// foundation_analytics_report.php — มูลนิธิดูรายงานเชิงวิเคราะห์ (จากแจ้งเตือนหรือลิงก์ตรง)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/foundation_analytics.php';
require_once __DIR__ . '/includes/foundation_analytics_report_html.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'foundation') {
    header('Location: index.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];
$st = $conn->prepare('SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$st) {
    header('Location: foundation.php');
    exit();
}
$st->bind_param('i', $uid);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$foundationId = (int)($row['foundation_id'] ?? 0);
$foundationNameSession = trim((string)($row['foundation_name'] ?? ''));

if ($foundationId <= 0) {
    header('Location: update_profile.php');
    exit();
}

$htmlBody = drawdream_foundation_analytics_report_html_fragment($conn, $foundationId);
if ($htmlBody === null) {
    header('Location: foundation.php');
    exit();
}

$tcpdfPath = __DIR__ . '/lib/tcpdf/tcpdf.php';
$tcpdfPresent = is_file($tcpdfPath);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานเชิงวิเคราะห์ | <?= drawdream_foundation_analytics_h($foundationNameSession) ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&family=Sarabun:wght@400;600&display=swap');
        body { margin: 0; background: #F7ECDE; font-family: 'Prompt','Sarabun',sans-serif; }
        .fan-wrap { max-width: 920px; margin: 28px auto; padding: 0 16px 48px; box-sizing: border-box; }
        .fan-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .fan-btn { display: inline-flex; align-items: center; padding: 10px 18px; border-radius: 10px; font-weight: 600; text-decoration: none; border: 1px solid #e5e7eb; background: #fff; color: #374151; font-size: 15px; }
        .fan-btn:hover { background: #f9fafb; }
        .fan-btn--pri { background: #4A5BA8; color: #fff; border-color: #4A5BA8; }
        .fan-btn--pri:hover { background: #3d4d94; color: #fff; }
        .fan-note { font-size: 14px; color: #64748b; margin: 0 0 12px; line-height: 1.5; }
        .sheet { background: #fff; border-radius: 14px; padding: 22px 24px; box-shadow: 0 4px 20px rgba(15,23,42,.08); }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { box-shadow: none; border-radius: 0; padding: 0; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="fan-wrap">
    <div class="no-print">
        <div class="fan-toolbar">
            <a class="fan-btn" href="foundation.php">← กลับ</a>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <?php if ($tcpdfPresent): ?>
                    <a class="fan-btn fan-btn--pri" href="foundation_analytics_report_pdf.php">ดาวน์โหลด PDF</a>
                <?php else: ?>
                    <button type="button" class="fan-btn fan-btn--pri" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
                <?php endif; ?>
            </div>
        </div>
        <p class="fan-note">เอกสารนี้สรุปข้อมูลของมูลนิธิคุณ ณ เวลาที่เปิดดู</p>
    </div>
    <div class="sheet">
        <?= $htmlBody ?>
    </div>
</div>
</body>
</html>
