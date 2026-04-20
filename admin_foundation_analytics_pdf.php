<?php
// admin_foundation_analytics_pdf.php — รายงานเชิงวิเคราะห์มูลนิธิ (PDF A4 / พิมพ์ HTML หากไม่มี TCPDF)
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน foundation analytics pdf
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

$fp = drawdream_foundation_analytics_profile($conn, $foundationId);
if (!$fp) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$foundationName = trim((string)($fp['foundation_name'] ?? ''));

$htmlBody = drawdream_foundation_analytics_report_html_fragment($conn, $foundationId);
if ($htmlBody === null) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$tcpdfPath = __DIR__ . '/lib/tcpdf/tcpdf.php';
$tcpdfPresent = is_file($tcpdfPath);
$useTcpdf = $tcpdfPresent && (($_GET['html'] ?? '') !== '1');

if ($useTcpdf) {
    require_once $tcpdfPath;
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('DrawDream');
    $pdf->SetAuthor('DrawDream Admin');
    $pdf->SetTitle('รายงานเชิงวิเคราะห์ — ' . $foundationName);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();
    $pdfFont = 'dejavusans';
    foreach ([
        'C:\\Windows\\Fonts\\THSarabunNew.ttf',
        'C:\\Windows\\Fonts\\LeelawUI.ttf',
        'C:\\Windows\\Fonts\\tahoma.ttf',
    ] as $winFontPath) {
        if (!is_file($winFontPath)) {
            continue;
        }
        $added = TCPDF_FONTS::addTTFfont($winFontPath, 'TrueTypeUnicode', '', 96);
        if (is_string($added) && $added !== '') {
            $pdfFont = $added;
            break;
        }
    }
    $pdf->SetFont($pdfFont, '', 10);
    $pdf->writeHTML($htmlBody, true, false, true, false, '');
    $safeName = 'foundation_analytics_' . $foundationId . '.pdf';
    $pdf->Output($safeName, 'D');
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
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
        .wrap { max-width: 900px; margin: 24px auto; padding: 0 16px 40px; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .btn { display: inline-flex; align-items: center; padding: 10px 18px; border-radius: 10px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; font-family: inherit; font-size: 14px; }
        .btn--primary { background: #4A5BA8; color: #fff; }
        .btn--primary:hover { background: #3d4d94; color: #fff; }
        .btn--ghost { background: #fff; color: #374151; border: 1px solid #e5e7eb; }
        .warn { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; padding: 12px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
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
<div class="wrap">
    <div class="toolbar no-print">
        <div>
            <a class="btn btn--ghost" href="admin_foundation_totals.php?foundation_id=<?= (int)$foundationId ?>">← กลับไปยอดมูลนิธิ</a>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($tcpdfPresent): ?>
                <a class="btn btn--ghost" href="admin_foundation_analytics_pdf.php?foundation_id=<?= (int)$foundationId ?>&amp;html=1">ดูหน้าพิมพ์ (HTML)</a>
            <?php endif; ?>
            <button type="button" class="btn btn--primary" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
        </div>
    </div>
    <?php if (!$tcpdfPresent): ?>
    <div class="warn no-print">
        ไม่พบไลบรารี <strong>TCPDF</strong> ที่ <code>lib/tcpdf/tcpdf.php</code> — ใช้ปุ่ม «พิมพ์ / บันทึกเป็น PDF» (Chrome: พิมพ์ → บันทึกเป็น PDF) หรือรัน <code>tools/install_tcpdf.ps1</code> แล้วเปิดลิงก์รายงานอีกครั้งเพื่อดาวน์โหลด PDF จากเซิร์ฟเวอร์โดยตรง
    </div>
    <?php endif; ?>
    <div class="sheet">
        <?= $htmlBody ?>
    </div>
</div>
</body>
</html>
