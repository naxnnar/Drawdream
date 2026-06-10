<?php
// payment/check_needlist_payment.php — ยืนยันการชำระรายการสิ่งของ
declare(strict_types=1);

/**
 * ปิดรายการบริจาค needlist หลัง Omise:
 * - pending -> completed + แบ่งยอดตามสัดส่วนรายการเปิดรับ
 * - race ครบเป้า -> คืนเงินอัตโนมัติ + UI แจ้งชัดเจน
 */

include __DIR__ . '/../db.php';
include __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/e_receipt.php';
require_once __DIR__ . '/../includes/drawdream_needlist_payment_finalize.php';
require_once __DIR__ . '/omise_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

drawdream_payment_transaction_ensure_schema($conn);

$charge_id = $_GET['charge_id'] ?? '';
$fid       = (int)($_GET['fid'] ?? 0);

if ($charge_id === '') {
    header('Location: ../foundation.php');
    exit();
}

$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge  = [];

if ($is_mock) {
    $charge = [
        'status'   => 'successful',
        'paid'     => true,
        'amount'   => ($_SESSION['pending_amount'] ?? 0) * 100,
        'metadata' => ['foundation_id' => (int)($_SESSION['pending_foundation_id'] ?? $fid)],
    ];
} else {
    $fetched = drawdream_omise_fetch_charge($charge_id, true);
    $charge = is_array($fetched) ? $fetched : [];
}

if ($fid <= 0) {
    $fid = (int)($charge['metadata']['foundation_id'] ?? ($_SESSION['pending_foundation_id'] ?? 0));
}

$status          = $charge['status'] ?? 'unknown';
$paid            = $charge['paid'] ?? false;
$failure_code    = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at      = $charge['expires_at'] ?? '';
$is_test_mode    = drawdream_omise_is_test_mode();

$is_success = ($paid === true) || ($status === 'successful') || $is_mock;
$amount     = 0.0;

$ptRow = null;
$dup = $conn->prepare('SELECT donate_id, payment_status, amount FROM donation WHERE omise_charge_id = ? LIMIT 1');
$dup->bind_param('s', $charge_id);
$dup->execute();
$ptRow = $dup->get_result()->fetch_assoc();

$already_completed = is_array($ptRow) && (($ptRow['payment_status'] ?? '') === 'completed');
$already_unallocated = is_array($ptRow) && (($ptRow['payment_status'] ?? '') === DRAWDREAM_DONATION_STATUS_PAID_UNALLOCATED);
$already_refunded = is_array($ptRow) && (($ptRow['payment_status'] ?? '') === DRAWDREAM_DONATION_STATUS_REFUNDED);
$has_pending = is_array($ptRow) && (($ptRow['payment_status'] ?? '') === 'pending');

$donor_uid = (int)$_SESSION['user_id'];

if (!$is_mock && $has_pending && !$already_completed && !$already_unallocated && !$already_refunded && !$is_success
    && in_array($status, ['failed', 'expired'], true)) {
    drawdream_abandon_pending_donation_by_charge($conn, $donor_uid, $charge_id);
    drawdream_clear_pending_payment_session();
    $has_pending = false;
    if (is_array($ptRow)) {
        $ptRow['payment_status'] = 'failed';
    }
}

/** @var 'success'|'paid_goal_late'|'paid_goal_refunded'|'pending'|'failed' $payment_ui */
$payment_ui = 'failed';
$finalized_this_request = false;
$receiptDonateId = 0;

if ($already_refunded) {
    $payment_ui = 'paid_goal_refunded';
    $amount = (float)($ptRow['amount'] ?? 0);
    if ($amount <= 0) {
        $amount = ($charge['amount'] ?? 0) / 100;
    }
} elseif ($already_unallocated) {
    $payment_ui = 'paid_goal_late';
    $amount = (float)($ptRow['amount'] ?? 0);
    if ($amount <= 0) {
        $amount = ($charge['amount'] ?? 0) / 100;
    }
} elseif ($already_completed) {
    $payment_ui = 'success';
    $amount = (float)($ptRow['amount'] ?? 0);
    if ($amount <= 0) {
        $amount = ($charge['amount'] ?? 0) / 100;
    }
} elseif ($status === 'pending' && !$is_success) {
    $payment_ui = 'pending';
} elseif ($is_success && $fid > 0) {
    $amount = ($charge['amount'] ?? 0) / 100;
    if ($amount <= 0) {
        $amount = (float)($_SESSION['pending_amount'] ?? 0);
    }

    if ($has_pending) {
        $donate_id_from_pt = (int)($ptRow['donate_id'] ?? 0);
        $finalize = drawdream_finalize_needlist_donation(
            $conn,
            $fid,
            $donate_id_from_pt,
            $charge_id,
            (float)$amount,
            $donor_uid
        );

        if ($finalize === DRAWDREAM_PROJECT_FINALIZE_OK) {
            $payment_ui = 'success';
            $finalized_this_request = true;
            $receiptDonateId = $donate_id_from_pt;
            unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_foundation'], $_SESSION['pending_foundation_id'], $_SESSION['pending_donate_id'], $_SESSION['qr_image']);
        } elseif (drawdream_needlist_finalize_is_goal_race($finalize)) {
            $late = drawdream_handle_needlist_late_payment(
                $conn,
                $donate_id_from_pt,
                (float)$amount,
                $fid,
                $charge_id,
                $donor_uid,
                $finalize
            );
            $payment_ui = $late['refunded'] ? 'paid_goal_refunded' : 'paid_goal_late';
            unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_foundation'], $_SESSION['pending_foundation_id'], $_SESSION['pending_donate_id'], $_SESSION['qr_image']);
        } else {
            $payment_ui = 'failed';
            $failure_message = 'ชำระเงินสำเร็จแล้ว แต่ระบบบันทึกรายการไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบพร้อมอ้างอิง Charge';
        }
    } else {
        $category_id = drawdream_get_or_create_needitem_donate_category_id($conn);
        $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
        if ($conn->begin_transaction()) {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO donation (
                        category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                        omise_charge_id, donate_type
                    ) VALUES (?, ?, ?, ?, 'completed', NOW(), ?, ?)
                ");
                $stmt->bind_param('iiidss', $category_id, $fid, $donor_uid, $amount, $charge_id, $dtNeed);
                $stmt->execute();
                $receiptDonateId = (int)$conn->insert_id;

                $bump = drawdream_needlist_bump_open_items($conn, $fid, (float)$amount, $receiptDonateId, $charge_id);
                if ($bump !== DRAWDREAM_PROJECT_FINALIZE_OK) {
                    throw new RuntimeException('needlist bump:' . $bump);
                }
                $conn->commit();
                $payment_ui = 'success';
                $finalized_this_request = true;
                unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_foundation'], $_SESSION['pending_foundation_id'], $_SESSION['pending_donate_id'], $_SESSION['qr_image']);
            } catch (Throwable $e) {
                $conn->rollback();
                $receiptDonateId = 0;
                $msg = $e->getMessage();
                if (str_starts_with($msg, 'needlist bump:') && drawdream_needlist_finalize_is_goal_race(substr($msg, strlen('needlist bump:')))) {
                    $reason = substr($msg, strlen('needlist bump:'));
                    $pendIns = 'pending';
                    $insLate = $conn->prepare(
                        'INSERT INTO donation (
                            category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                            omise_charge_id, donate_type
                        ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
                    );
                    $lateId = 0;
                    if ($insLate) {
                        $insLate->bind_param('iiidsss', $category_id, $fid, $donor_uid, $amount, $pendIns, $charge_id, $dtNeed);
                        if ($insLate->execute()) {
                            $lateId = (int)$conn->insert_id;
                        }
                    }
                    if ($lateId > 0) {
                        $late = drawdream_handle_needlist_late_payment($conn, $lateId, (float)$amount, $fid, $charge_id, $donor_uid, $reason);
                        $payment_ui = $late['refunded'] ? 'paid_goal_refunded' : 'paid_goal_late';
                        unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_foundation'], $_SESSION['pending_foundation_id'], $_SESSION['pending_donate_id'], $_SESSION['qr_image']);
                    } else {
                        $payment_ui = 'paid_goal_late';
                        $failure_message = 'ชำระเงินแล้ว แต่รายการครบเป้าก่อนหน้านี้ — กรุณาติดต่อผู้ดูแลพร้อมอ้างอิง Charge';
                    }
                } else {
                    $payment_ui = 'failed';
                    $failure_message = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาติดต่อผู้ดูแลระบบ';
                }
            }
        } else {
            $payment_ui = 'failed';
            $failure_message = 'ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง';
        }
    }
} else {
    $payment_ui = 'failed';
}

if ($finalized_this_request && $receiptDonateId <= 0) {
    $receiptDonateId = drawdream_receipt_completed_donation_id_by_charge($conn, $charge_id);
}
if ($finalized_this_request && $receiptDonateId > 0) {
    drawdream_send_e_receipt_notification_by_donate_id($conn, $receiptDonateId);
}

if ($payment_ui === 'success' && $amount <= 0) {
    $amount = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}
if (($payment_ui === 'paid_goal_late' || $payment_ui === 'paid_goal_refunded') && $amount <= 0) {
    $amount = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}

$foundation_name = trim((string)($_SESSION['pending_foundation'] ?? ''));
if ($foundation_name === '' && $fid > 0) {
    $stFn = $conn->prepare('SELECT foundation_name FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
    if ($stFn) {
        $stFn->bind_param('i', $fid);
        $stFn->execute();
        $foundation_name = trim((string)($stFn->get_result()->fetch_assoc()['foundation_name'] ?? ''));
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ผลการชำระเงิน | DrawDream</title>
    <link rel="stylesheet" href="../css/navbar.css?v=7">
    <link rel="stylesheet" href="../css/payment.css?v=2">
</head>
<body>

<?php include '../navbar.php'; ?>

<div class="payment-container">
    <div class="result-box">

        <?php if ($payment_ui === 'success'): ?>
            <div class="result-icon success">✓</div>
            <h2>ชำระเงินสำเร็จ!</h2>
            <p>ขอบคุณที่ร่วมบริจาคเงินเพื่อสมทบทุนจัดซื้อสิ่งของ<?php if ($foundation_name !== ''): ?><br><strong><?= htmlspecialchars($foundation_name, ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?></p>
            <p>จำนวน <strong><?= number_format($amount, 2) ?> บาท</strong></p>
            <p class="charge-ref">อ้างอิง: <?= htmlspecialchars($charge_id) ?></p>
            <a href="../foundation.php" class="btn-pay" style="background:#CC583F;border:none;width:100%;max-width:400px;margin:32px auto 0 auto;display:block;font-size:1.3rem;">กลับหน้ามูลนิธิ</a>

        <?php elseif ($payment_ui === 'paid_goal_refunded'): ?>
            <div class="result-icon success">↩</div>
            <h2>รายการครบเป้าก่อนหน้านี้ — ระบบคืนเงินให้แล้ว</h2>
            <p class="result-notice">มีผู้บริจาคท่านอื่นปิดยอดรายการสิ่งของครบเป้าหมายก่อนคุณ จึง<strong>ไม่สามารถนับยอดเข้ารายการนี้ได้</strong> ระบบได้ส่งคำขอคืนเงินไปยัง Omise อัตโนมัติแล้ว</p>
            <p>จำนวนที่คืน <strong><?= number_format($amount, 2) ?> บาท</strong></p>
            <?php if ($foundation_name !== ''): ?>
                <p>มูลนิธิ: <strong><?= htmlspecialchars($foundation_name, ENT_QUOTES, 'UTF-8') ?></strong></p>
            <?php endif; ?>
            <p class="result-notice">เงินจะกลับเข้าบัญชีตามระยะเวลาของธนาคาร (โดยทั่วไป 3–7 วันทำการ)</p>
            <p class="charge-ref">อ้างอิง Charge: <?= htmlspecialchars($charge_id) ?></p>
            <a href="../foundation.php" class="btn-pay" style="background:#CC583F;border:none;width:100%;max-width:400px;margin:32px auto 0 auto;display:block;font-size:1.3rem;">กลับหน้ามูลนิธิ</a>

        <?php elseif ($payment_ui === 'paid_goal_late'): ?>
            <div class="result-icon warning">!</div>
            <h2>ชำระเงินแล้ว แต่รายการครบเป้าก่อนหน้านี้</h2>
            <p class="result-notice">ระบบได้รับเงินจากธนาคารแล้ว แต่มีผู้บริจาคท่านอื่นปิดยอดครบเป้าหมายก่อนคุณ จึง<strong>ไม่สามารถนับยอดเข้ารายการนี้ได้</strong></p>
            <p>จำนวนที่โอน <strong><?= number_format($amount, 2) ?> บาท</strong></p>
            <?php if ($foundation_name !== ''): ?>
                <p>มูลนิธิ: <strong><?= htmlspecialchars($foundation_name, ENT_QUOTES, 'UTF-8') ?></strong></p>
            <?php endif; ?>
            <p class="result-notice result-notice--action">กรุณาติดต่อผู้ดูแลระบบพร้อมแจ้งรหัสอ้างอิงด้านล่าง เพื่อดำเนินการคืนเงิน</p>
            <p class="charge-ref">อ้างอิง Charge: <?= htmlspecialchars($charge_id) ?></p>
            <a href="../foundation.php" class="btn-pay" style="background:#CC583F;border:none;width:100%;max-width:400px;margin:32px auto 0 auto;display:block;font-size:1.3rem;">กลับหน้ามูลนิธิ</a>

        <?php elseif ($payment_ui === 'pending'): ?>
            <div class="result-icon pending">⏳</div>
            <h2>ยังไม่พบการโอนจากธนาคาร</h2>
            <p>ถ้าโอนแล้วให้รอสักครู่แล้วกด «เช็คอีกครั้ง» หาก<strong>ยังไม่ได้โอน</strong>กด «ยกเลิก» — ลบรายการค้างและปิด QR ที่ Omise (expire) แล้วกลับไปบริจาคใหม่ได้</p>
            <?php if ($is_test_mode): ?>
                <?php echo drawdream_omise_test_pending_help_html($charge_id); ?>
            <?php endif; ?>
            <?php if ($expires_at !== ''): ?>
                <p>QR หมดอายุ: <?= htmlspecialchars($expires_at) ?></p>
            <?php endif; ?>
            <p class="charge-ref">Charge: <?= htmlspecialchars($charge_id) ?> | Status: <?= htmlspecialchars($status) ?></p>
            <a href="check_needlist_payment.php?charge_id=<?= urlencode($charge_id) ?>&fid=<?= $fid ?>"
               class="btn-pay">เช็คอีกครั้ง</a>
            <form method="post" action="abandon_qr.php" style="margin:16px 0 0 0;">
                <?= drawdream_csrf_field() ?>
                <input type="hidden" name="charge_id" value="<?= htmlspecialchars($charge_id, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="return_url" value="foundation_donate.php?fid=<?= (int)$fid ?>">
                <button type="submit" class="btn-back" style="width:100%;max-width:400px;border:1px solid #b91c1c;color:#b91c1c;background:#fff;cursor:pointer;padding:12px;border-radius:8px;font-weight:600;">
                    ยกเลิก (ยังไม่ได้โอน)
                </button>
            </form>
            <a href="../foundation.php" class="btn-back">กลับหน้ามูลนิธิ</a>

        <?php else: ?>
            <div class="result-icon error">✕</div>
            <h2>ชำระเงินไม่สำเร็จ</h2>
            <p>สถานะ: <?= htmlspecialchars($status) ?></p>
            <?php if ($failure_code !== '' || $failure_message !== ''): ?>
                <?php if ($failure_code !== ''): ?>
                    <p>รหัสข้อผิดพลาด: <?= htmlspecialchars($failure_code) ?></p>
                <?php endif; ?>
                <?php if ($failure_message !== ''): ?>
                    <p><?= htmlspecialchars($failure_message) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <p class="charge-ref">อ้างอิง: <?= htmlspecialchars($charge_id) ?></p>
            <button type="button" class="btn-pay" onclick="window.location.reload()">ลองใหม่</button>
            <a href="../foundation.php" class="btn-back">กลับหน้ามูลนิธิ</a>
        <?php endif; ?>

</div>
</div>

</body>
</html>
