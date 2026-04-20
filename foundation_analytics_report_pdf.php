<?php
// foundation_analytics_report_pdf.php — ดาวน์โหลด PDF รายงานเชิงวิเคราะห์ (มูลนิธิ — เฉพาะของตนเอง)
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
$foundationName = trim((string)($row['foundation_name'] ?? ''));

if ($foundationId <= 0) {
    header('Location: update_profile.php');
    exit();
}

$htmlBody = drawdream_foundation_analytics_report_html_fragment($conn, $foundationId);
if ($htmlBody === null) {
    header('Location: foundation_analytics_report.php');
    exit();
}

$tcpdfPath = __DIR__ . '/lib/tcpdf/tcpdf.php';
if (!is_file($tcpdfPath)) {
    header('Location: foundation_analytics_report.php');
    exit();
}

require_once $tcpdfPath;
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('DrawDream');
$pdf->SetAuthor('DrawDream');
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
