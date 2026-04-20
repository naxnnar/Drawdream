<?php
// admin_foundation_analytics_view.php — ดูรายงานเชิงวิเคราะห์ / บันทึก PDF / ส่งให้มูลนิธิผ่านแจ้งเตือน
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน foundation analytics view
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/foundation_analytics.php';
require_once __DIR__ . '/includes/foundation_analytics_report_html.php';
require_once __DIR__ . '/includes/notification_audit.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$foundationId = (int)($_GET['foundation_id'] ?? 0);
if ($foundationId <= 0) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_foundation_report') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $expected = (string)($_SESSION['csrf_foundation_analytics_send'] ?? '');
    if ($csrf === '' || $expected === '' || !hash_equals($expected, $csrf)) {
        $flashErr = 'เซสชันไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $postFid = (int)($_POST['foundation_id'] ?? 0);
        if ($postFid !== $foundationId) {
            $flashErr = 'ข้อมูลไม่ตรงกัน';
        } else {
            $st = $conn->prepare('SELECT user_id, foundation_name FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
            if ($st) {
                $st->bind_param('i', $foundationId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $foundationUserId = (int)($row['user_id'] ?? 0);
                if ($foundationUserId <= 0) {
                    $flashErr = 'ไม่พบบัญชีเจ้าของมูลนิธิ';
                } else {
                    $title = 'รายงานเชิงวิเคราะห์จากแอดมิน';
                    $msg = 'แอดมินส่งรายงานเชิงวิเคราะห์ให้คุณดู กดเพื่อเปิดเอกสาร';
                    $link = 'foundation_analytics_report.php';
                    $ok = drawdream_send_notification(
                        $conn,
                        $foundationUserId,
                        'admin_analytics_report',
                        $title,
                        $msg,
                        $link,
                        null
                    );
                    if ($ok) {
                        $flashOk = 'ส่งแจ้งเตือนไปยังมูลนิธิแล้ว — ผู้ใช้มูลนิธิจะเห็นในกระดิ่งและหน้าการแจ้งเตือน';
                        $_SESSION['csrf_foundation_analytics_send'] = bin2hex(random_bytes(16));
                    } else {
                        $flashErr = 'บันทึกการแจ้งเตือนไม่สำเร็จ';
                    }
                }
            } else {
                $flashErr = 'ไม่สามารถโหลดข้อมูลมูลนิธิได้';
            }
        }
    }
}

if (empty($_SESSION['csrf_foundation_analytics_send'])) {
    $_SESSION['csrf_foundation_analytics_send'] = bin2hex(random_bytes(16));
}
$csrfToken = (string)$_SESSION['csrf_foundation_analytics_send'];

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
        .btn-an--send { background: #fff7ed; color: #9a3412; border: 1px solid #fdba74; font-weight: 700; }
        .btn-an--send:hover { background: #ffedd5; }
        .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 15px; }
        .flash--ok { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .flash--err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .hint { font-size: 13px; color: #64748b; margin-top: 6px; max-width: 420px; line-height: 1.45; }
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
        <?php if ($flashOk !== ''): ?>
            <div class="flash flash--ok" role="status"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== ''): ?>
            <div class="flash flash--err" role="alert"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="analytics-toolbar">
            <div class="analytics-toolbar__actions">
                <?php if ($tcpdfPresent): ?>
                    <a class="btn-an btn-an--primary" href="admin_foundation_analytics_pdf.php?foundation_id=<?= (int)$foundationId ?>">บันทึกเป็น PDF</a>
                <?php else: ?>
                    <button type="button" class="btn-an btn-an--primary" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
                <?php endif; ?>
                <form method="post" action="" style="display:inline;" onsubmit="return confirm('ส่งลิงก์รายงานนี้ไปยังบัญชีมูลนิธิผ่านการแจ้งเตือน?');">
                    <input type="hidden" name="action" value="send_foundation_report">
                    <input type="hidden" name="foundation_id" value="<?= (int)$foundationId ?>">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn-an btn-an--send">ส่งเอกสารให้มูลนิธิ</button>
                </form>
            </div>
        </div>
        <?php if (!$tcpdfPresent): ?>
            <div class="warn">
                ไม่พบไลบรารี <strong>TCPDF</strong> — ใช้ปุ่ม «พิมพ์ / บันทึกเป็น PDF» แล้วเลือกบันทึกเป็น PDF ในเบราว์เซอร์ หรือติดตั้ง TCPDF ที่ <code>lib/tcpdf/tcpdf.php</code>
            </div>
        <?php endif; ?>
        <p class="hint">มูลนิธิจะได้รับการแจ้งเตือนในกระดิ่ง — กดเพื่อเปิดเอกสารรายงานเดียวกับที่คุณดูอยู่ (ข้อมูล ณ เวลาที่มูลนิธิเปิดดู)</p>
    </div>
    <div class="sheet">
        <?= $htmlBody ?>
    </div>
</div>
</body>
</html>
