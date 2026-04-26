<?php
// admin_foundation_analytics_view.php — ดูรายงานเชิงวิเคราะห์ / บันทึก PDF
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน foundation analytics view
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/foundation_analytics.php';
require_once __DIR__ . '/includes/foundation_analytics_report_html.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$foundationId = (int)($_GET['foundation_id'] ?? 0);
if ($foundationId <= 0) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$htmlBody = drawdream_foundation_analytics_report_html_fragment($conn, $foundationId);
if ($htmlBody === null) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$fp = drawdream_foundation_analytics_profile($conn, $foundationId);
$foundationName = trim((string)($fp['foundation_name'] ?? ''));

$tcpdfPath = __DIR__ . '/lib/tcpdf/tcpdf.php';
$tcpdfPresent = is_file($tcpdfPath);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานเชิงวิเคราะห์ | <?= drawdream_foundation_analytics_h($foundationName) ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&family=Sarabun:wght@400;600&display=swap');
        body { margin: 0; background: #eceef5; font-family: 'Prompt','Sarabun',sans-serif; }
        .analytics-wrap { max-width: 920px; margin: 24px auto; padding: 0 16px 48px; box-sizing: border-box; }
        .analytics-toolbar {
            display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; justify-content: flex-end;
            margin-bottom: 18px;
        }
        .analytics-toolbar__actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .btn-an {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 18px; border-radius: 10px; font-weight: 600; text-decoration: none;
            border: none; cursor: pointer; font-family: inherit; font-size: 15px;
        }
        .btn-an--ghost { background: #fff; color: #374151; border: 1px solid #e5e7eb; }
        .btn-an--ghost:hover { background: #f9fafb; }
        .btn-an--primary { background: #4A5BA8; color: #fff; }
        .btn-an--primary:hover { background: #3d4d94; color: #fff; }
        .sheet { background: #fff; border-radius: 14px; padding: 22px 24px; box-shadow: 0 4px 20px rgba(15,23,42,.08); }
        .warn { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; padding: 12px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
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
<div class="analytics-wrap">
    <div class="no-print">
        <div class="analytics-toolbar">
            <div class="analytics-toolbar__actions">
                <?php if ($tcpdfPresent): ?>
                    <a class="btn-an btn-an--primary" href="admin_foundation_analytics_pdf.php?foundation_id=<?= (int)$foundationId ?>">บันทึกเป็น PDF</a>
                <?php else: ?>
                    <button type="button" class="btn-an btn-an--primary" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$tcpdfPresent): ?>
            <div class="warn">
                ไม่พบไลบรารี <strong>TCPDF</strong> — ใช้ปุ่ม «พิมพ์ / บันทึกเป็น PDF» แล้วเลือกบันทึกเป็น PDF ในเบราว์เซอร์ หรือติดตั้ง TCPDF ที่ <code>lib/tcpdf/tcpdf.php</code>
            </div>
        <?php endif; ?>
    </div>
    <div class="sheet">
        <?= $htmlBody ?>
    </div>
</div>
</body>
</html>
