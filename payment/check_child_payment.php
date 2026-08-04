<?php
// payment/check_child_payment.php — ยืนยันการชำระบริจาคเด็ก
// สรุปสั้น: บันทึก donation เป็น completed เมื่อ Omise ยืนยันชำระสำเร็จ (ไม่สร้าง pending/cancelled)
/**
 * ภาพรวมแบบง่าย:
 * 1) รับ charge_id แล้วถามสถานะจาก Omise (หรือ mock ใน local)
 * 2) หา pending donation ที่ผูกกับ charge เดียวกัน
 * 3) ถ้าสำเร็จ -> เปลี่ยน pending เป็น completed (กันบันทึกซ้ำ)
 * 4) ถ้าล้มเหลว/หมดอายุ -> ปิด pending เป็น failed เพื่อล้างรายการค้าง
 * 5) ส่งแจ้งเตือนใบเสร็จอิเล็กทรอนิกส์เมื่อปิดรายการสำเร็จ
 */

include __DIR__ . '/../includes/payment_bootstrap.php';
include 'config.php';

require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/pending_child_donation.php';
require_once dirname(__DIR__) . '/includes/qr_payment_abandon.php';
require_once dirname(__DIR__) . '/includes/e_receipt.php';
require_once __DIR__ . '/omise_helpers.php';
$isPollRequest = isset($_GET['poll']) && (string)$_GET['poll'] === '1';

if (!isset($_SESSION['user_id'])) {
    if ($isPollRequest) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'reason' => 'login_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: ../login.php");
    exit();
}

$charge_id = $_GET['charge_id'] ?? '';
$child_id  = (int)($_GET['child_id'] ?? 0);

if (empty($charge_id)) {
    if ($isPollRequest) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'reason' => 'missing_charge_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: ../children_.php");
    exit();
}

$ptRow = null;
$dup = $conn->prepare('SELECT donate_id, payment_status, amount FROM donation WHERE omise_charge_id = ? LIMIT 1');
$dup->bind_param('s', $charge_id);
$dup->execute();
$ptRow = $dup->get_result()->fetch_assoc();
$already_completed = is_array($ptRow) && (($ptRow['payment_status'] ?? '') === 'completed');
$has_pending       = is_array($ptRow) && (($ptRow['payment_status'] ?? '') === 'pending');

// ตรวจสอบว่าเป็น mock charge (สร้างโดย _omise_local_mock เมื่อ local dev)
$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge  = [];

if ($already_completed && !$is_mock) {
    $amountCompleted = (float)($ptRow['amount'] ?? 0);
    $charge = [
        'status'   => 'successful',
        'paid'     => true,
        'amount'   => (int)round(max(0, $amountCompleted) * 100),
        'metadata' => ['child_id' => $child_id],
    ];
} elseif ($is_mock) {
    $charge = [
        'status'   => 'successful',
        'paid'     => true,
        'amount'   => ($_SESSION['pending_amount'] ?? 0) * 100,
        'metadata' => ['child_id' => (int)($_SESSION['pending_child_id'] ?? $child_id)],
    ];
} else {
    $fetched = drawdream_omise_fetch_charge($charge_id, true);
    $charge = is_array($fetched) ? $fetched : [];
}

// fallback child_id จาก metadata/session หากไม่มีใน URL
if ($child_id <= 0) {
    $child_id = (int)($charge['metadata']['child_id'] ?? ($_SESSION['pending_child_id'] ?? 0));
}

$status          = $charge['status'] ?? 'unknown';
$paid            = $charge['paid'] ?? false;
$failure_code    = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at      = $charge['expires_at'] ?? '';
$is_test_mode    = drawdream_omise_is_test_mode();

$is_success = ($paid === true) || ($status === 'successful') || $is_mock;
$amount     = 0;
$normalized_terminal_status = in_array($status, ['failed', 'expired', 'reversed'], true)
    ? (($status === 'reversed') ? 'cancelled' : 'failed')
    : $status;

$donor_uid = (int)$_SESSION['user_id'];

if (!$is_mock && $has_pending && !$already_completed && !$is_success
    && in_array($normalized_terminal_status, ['failed', 'cancelled'], true)) {
    // เส้นทาง "จบแบบไม่สำเร็จ" ของ QR: ปิดรายการค้างทันทีเพื่อลดความสับสนของผู้ใช้
    drawdream_abandon_pending_donation_by_charge($conn, $donor_uid, $charge_id);
    drawdream_clear_pending_payment_session();
    $has_pending = false;
    if (is_array($ptRow)) {
        $ptRow['payment_status'] = 'failed';
    }
}

$finalized_this_request = false;
$receiptDonateId = 0;

if ($is_success && !$already_completed && $child_id > 0) {
    $amount = ($charge['amount'] ?? 0) / 100;
    if ($amount <= 0) {
        $amount = (float)($_SESSION['pending_amount'] ?? 0);
    }
    $donate_id_from_pt = (int)($ptRow['donate_id'] ?? 0);
    $finalized = false;
    if ($has_pending) {
        $finalized = drawdream_finalize_child_donation($conn, $child_id, $donate_id_from_pt, $charge_id, (float)$amount, $donor_uid);
    }
    if (!$finalized) {
        $dupDone = $conn->prepare(
            "SELECT donate_id FROM donation WHERE omise_charge_id = ? AND payment_status = 'completed' LIMIT 1"
        );
        if ($dupDone) {
            $dupDone->bind_param('s', $charge_id);
            $dupDone->execute();
            $dupRow = $dupDone->get_result()->fetch_assoc();
            if (is_array($dupRow)) {
                $donate_id_from_pt = (int)($dupRow['donate_id'] ?? 0);
                $finalized = $donate_id_from_pt > 0;
            }
        }
    }
    if (!$finalized) {
        $category_id = drawdream_get_or_create_child_donate_category_id($conn);
        $completed = 'completed';
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('
                INSERT INTO donation (
                    category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                    omise_charge_id, donate_type
                ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)
            ');
            $donateType = DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME;
            $stmt->bind_param('iiidsss', $category_id, $child_id, $donor_uid, $amount, $completed, $charge_id, $donateType);
            $stmt->execute();
            $donate_id_from_pt = (int)$conn->insert_id;
            drawdream_child_sync_sponsorship_status($conn, $child_id);
            $conn->commit();
            $finalized = $donate_id_from_pt > 0;
        } catch (Exception $e) {
            $conn->rollback();
            $finalized = false;
        }
    }
    if ($finalized) {
        $chargeMeta = is_array($charge['metadata'] ?? null) ? $charge['metadata'] : [];
        $metaRecurring = trim((string)($chargeMeta['recurring_plan_code'] ?? ''));
        $metaSubId = trim((string)($chargeMeta['subscription_id'] ?? ''));
        if ($metaRecurring !== '' || $metaSubId !== '') {
            error_log('[drawdream_child_check] ' . json_encode([
                'charge_id' => $charge_id,
                'donate_id' => $donate_id_from_pt,
                'subscription_id' => $metaSubId,
                'recurring_plan_code' => $metaRecurring,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $finalized_this_request = true;
        $receiptDonateId = $donate_id_from_pt;
        unset(
            $_SESSION['pending_charge_id'],
            $_SESSION['pending_amount'],
            $_SESSION['pending_child_id'],
            $_SESSION['pending_child_name'],
            $_SESSION['qr_image']
        );
    } else {
        // กัน false success: จ่ายจริงแต่ finalize ไม่ผ่าน ให้โชว์ข้อความพิเศษเพื่อให้ติดต่อแอดมินได้
        $is_success = false;
        $failure_message = 'ชำระเงินสำเร็จแล้ว แต่ระบบบันทึกรายการไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบพร้อมอ้างอิง Charge';
    }
}

if ($finalized_this_request && $receiptDonateId <= 0) {
    $receiptDonateId = drawdream_receipt_completed_donation_id_by_charge($conn, $charge_id);
}
if ($finalized_this_request && $receiptDonateId > 0) {
    drawdream_send_e_receipt_notification_deferred($conn, $receiptDonateId);
}
if ($finalized_this_request) {
    require_once dirname(__DIR__) . '/includes/homepage_impact_stats.php';
    drawdream_homepage_impact_stats_cache_bust();
    require_once dirname(__DIR__) . '/includes/children_public_list_cache.php';
    drawdream_children_public_list_cache_bust();
}

$already_processed_display = $finalized_this_request
    || $already_completed
    || ($is_success && is_array($ptRow) && ($ptRow['payment_status'] ?? '') === 'completed');

if ($already_processed_display && !$finalized_this_request) {
    error_log('[drawdream_child_check] retry-safe duplicate callback handled for charge ' . $charge_id);
    $is_success = true;
    $amount     = ($charge['amount'] ?? 0) / 100;
    if ($amount <= 0) {
        $amount = (float)($_SESSION['pending_amount'] ?? 0);
    }
    if ($amount <= 0 && is_array($ptRow)) {
        $amount = (float)($ptRow['amount'] ?? 0);
    }
}
if ($is_success && $amount <= 0) {
    $amount = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}

// ดึงชื่อเด็กเพื่อแสดงผล / redirect ใบเสร็จ
$child_name = $_SESSION['pending_child_name'] ?? '';
if (empty($child_name) && $child_id > 0) {
    $stmtN = $conn->prepare("SELECT child_name FROM foundation_children WHERE child_id = ? LIMIT 1");
    $stmtN->bind_param("i", $child_id);
    $stmtN->execute();
    $childRow = $stmtN->get_result()->fetch_assoc();
    $child_name = $childRow['child_name'] ?? '';
}

if ($is_success) {
    if ($receiptDonateId <= 0 && is_array($ptRow)) {
        $receiptDonateId = (int)($ptRow['donate_id'] ?? 0);
    }
    if ($receiptDonateId <= 0) {
        $receiptDonateId = drawdream_receipt_completed_donation_id_by_charge($conn, $charge_id);
    }
    $successDetail = 'จำนวน ' . number_format((float)$amount, 2) . ' บาท';
    if (!empty($child_name)) {
        $successDetail = 'ขอบคุณที่ร่วมบริจาคให้ ' . $child_name . ' — ' . $successDetail;
    }
    if (!$isPollRequest) {
        drawdream_try_payment_success_receipt_redirect(
            $conn,
            $receiptDonateId,
            'ชำระเงินสำเร็จ!',
            '../children_.php',
            $successDetail
        );
    }
}

if ($isPollRequest) {
    header('Content-Type: application/json; charset=utf-8');
    if ($is_success) {
        $pollRedirect = '../children_.php';
        if ($receiptDonateId > 0 && drawdream_donation_eligible_for_e_receipt($conn, $receiptDonateId)) {
            $receiptQuery = drawdream_payment_success_receipt_query(
                $receiptDonateId,
                'ชำระเงินสำเร็จ!',
                '../children_.php',
                $successDetail ?? ''
            );
            if ($receiptQuery !== '') {
                $pollRedirect = '../' . $receiptQuery;
            }
        }
        echo json_encode([
            'ok' => true,
            'status' => 'success',
            'redirect' => $pollRedirect,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($status === 'pending') {
        echo json_encode(['ok' => true, 'status' => 'pending'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => false,
        'status' => (string)$status,
        'failure_message' => (string)$failure_message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ผลการชำระเงิน | DrawDream</title>
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
</head>
<body>

<?php include '../navbar.php'; ?>

<div class="payment-container">
    <div class="result-box">

        <?php if ($is_success): ?>
            <div class="result-icon success">✓</div>
            <h2>ชำระเงินสำเร็จ!</h2>
            <?php if (!empty($child_name)): ?>
                <p>ขอบคุณที่ร่วมบริจาคให้ <strong><?php echo htmlspecialchars($child_name); ?></strong></p>
            <?php else: ?>
                <p>ขอบคุณที่ร่วมบริจาคให้เด็กรายบุคคล</p>
            <?php endif; ?>
            <p>จำนวน <strong><?php echo number_format($amount, 2); ?> บาท</strong></p>
            <p class="charge-ref">อ้างอิง: <?php echo htmlspecialchars($charge_id); ?></p>

            <!-- ปุ่มเดียว: กลับหน้าโปรไฟล์เด็ก (สี #CC583F) -->
            <a href="../children_.php" class="btn-pay" style="background:#CC583F; border:none; width:100%; max-width:400px; margin:32px auto 0 auto; display:block; font-size:1.3rem;">กลับหน้าโปรไฟล์เด็ก</a>

        <?php elseif ($status === 'pending'): ?>
            <div class="result-icon pending">⏳</div>
            <h2>ยังไม่พบการโอนจากธนาคาร</h2>
            <p>ถ้าคุณสแกนจ่ายแล้ว อาจต้องรอสักครู่แล้วกด «เช็คอีกครั้ง» หาก<strong>ยังไม่ได้โอนจริง</strong>กด «ยกเลิกรายการนี้» — ระบบจะลบรายการค้างและปิด QR ที่ Omise (expire) เพื่อให้บริจาคใหม่ได้</p>
            <?php if ($is_test_mode): ?>
                <?php echo drawdream_omise_test_pending_help_html($charge_id); ?>
            <?php endif; ?>
            <?php if (!empty($expires_at)): ?>
                <p>QR หมดอายุ: <?php echo htmlspecialchars($expires_at); ?></p>
            <?php endif; ?>
            <p class="charge-ref">Charge: <?php echo htmlspecialchars($charge_id); ?> | Status: <?php echo htmlspecialchars($status); ?> | Paid: <?php echo $paid ? 'true' : 'false'; ?></p>
            <a href="check_child_payment.php?charge_id=<?php echo urlencode($charge_id); ?>&child_id=<?php echo $child_id; ?>"
               class="btn-pay">เช็คอีกครั้ง</a>
            <form method="post" action="abandon_qr.php" style="margin:16px 0 0 0;">
                <?= drawdream_csrf_field() ?>
                <input type="hidden" name="charge_id" value="<?php echo htmlspecialchars($charge_id, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="return_url" value="../children_.php">
                <button type="submit" class="btn-back" style="width:100%;max-width:400px;border:1px solid #b91c1c;color:#b91c1c;background:#fff;cursor:pointer;padding:12px;border-radius:8px;font-weight:600;">
                    ยกเลิกรายการนี้ (ยังไม่ได้โอน)
                </button>
            </form>
            <a href="../children_.php" class="btn-back">กลับหน้ารายชื่อเด็ก</a>

        <?php else: ?>
            <div class="result-icon error">✕</div>
            <h2>ชำระเงินไม่สำเร็จ</h2>
            <p>สถานะ: <?php echo htmlspecialchars($status); ?></p>
            <?php if (!empty($failure_code)): ?>
                <p>รหัสข้อผิดพลาด: <?php echo htmlspecialchars($failure_code); ?></p>
                <p>รายละเอียด: <?php echo htmlspecialchars($failure_message); ?></p>
            <?php elseif (!empty($failure_message)): ?>
                <p><?php echo htmlspecialchars($failure_message); ?></p>
            <?php endif; ?>
            <button type="button" class="btn-pay" onclick="window.location.reload()">ลองใหม่</button>
            <a href="../children_.php" class="btn-back">กลับหน้ารายชื่อเด็ก</a>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
