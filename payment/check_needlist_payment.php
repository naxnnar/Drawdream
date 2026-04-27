<?php
// payment/check_needlist_payment.php — ยืนยันการชำระรายการสิ่งของ
// สรุปสั้น: ตรวจผลการจ่าย needlist แล้วอัปเดต donation/foundation_needlist ให้เป็นสถานะล่าสุด

if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/donate_category_resolve.php';
require_once __DIR__ . '/../includes/needlist_donate_window.php';
require_once __DIR__ . '/../includes/payment_transaction_schema.php';
require_once __DIR__ . '/../includes/e_receipt.php';
require_once __DIR__ . '/../includes/donate_type.php';
require_once __DIR__ . '/../includes/notification_audit.php';
require_once __DIR__ . '/omise_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
drawdream_payment_transaction_ensure_schema($conn);

$charge_id = $_GET['charge_id'] ?? '';
$fid       = (int)($_GET['fid'] ?? 0);

if (empty($charge_id)) {
    header("Location: ../foundation.php");
    exit();
}

// ตรวจสอบว่าเป็น mock charge (สร้างโดย _omise_local_mock เมื่อ API ไม่ตอบสนอง)
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
    $fetched = drawdream_omise_fetch_charge($charge_id);
    $charge = is_array($fetched) ? $fetched : [];
}

// fallback fid จาก metadata/session หากไม่มีใน URL
if ($fid <= 0) {
    $fid = (int)($charge['metadata']['foundation_id'] ?? ($_SESSION['pending_foundation_id'] ?? 0));
}

$status          = $charge['status'] ?? 'unknown';
$paid            = $charge['paid'] ?? false;
$amount          = 0;
$failure_code    = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at      = $charge['expires_at'] ?? '';
$is_test_mode    = (strpos(OMISE_PUBLIC_KEY, 'pkey_test_') === 0) || (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0);

$is_success = ($paid === true) || ($status === 'successful') || $is_mock;
$receiptDonateId = 0;
$error_kind = '';
$error_note = '';

if (!$is_mock && !$is_success && in_array($status, ['failed', 'expired'], true)) {
    drawdream_clear_pending_payment_session();
}

// กันบันทึกซ้ำ
$already_processed = false;
$dup = $conn->prepare("SELECT donate_id FROM donation WHERE omise_charge_id = ? AND transaction_status = 'completed' LIMIT 1");
$dup->bind_param("s", $charge_id);
$dup->execute();
$already_processed = (bool)$dup->get_result()->fetch_assoc();

if ($is_success && !$already_processed && $fid > 0) {
    $amount = ($charge['amount'] ?? 0) / 100;

    $category_id = drawdream_get_or_create_needitem_donate_category_id($conn);
    if ($category_id <= 0) {
        $is_success = false;
    }

    $donor_uid = (int)$_SESSION['user_id'];
    if ($is_success) {
        if ($conn->begin_transaction()) {
            try {
                $completed = 'completed';
                $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
                $planOnce = DRAWDREAM_DONATION_RECURRING_PLAN_ONE_TIME;
                $stmt = $conn->prepare("
                    INSERT INTO donation (
                        category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                        omise_charge_id, transaction_status, donate_type, recurring_plan_code
                    ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
                ");
                if (!$stmt) {
                    throw new RuntimeException('prepare_insert_donation');
                }
                $stmt->bind_param("iiidsssss", $category_id, $fid, $donor_uid, $amount, $completed, $charge_id, $completed, $dtNeed, $planOnce);
                $stmt->execute();
                $donate_id = (int)$conn->insert_id;
                if ($donate_id <= 0) {
                    throw new RuntimeException('insert_donation_failed');
                }
                $receiptDonateId = $donate_id;

                $needOpen = drawdream_needlist_sql_open_for_donation();
                $lock = $conn->prepare("SELECT item_id FROM foundation_needlist WHERE foundation_id = ? AND $needOpen FOR UPDATE");
                if ($lock) {
                    $lock->bind_param('i', $fid);
                    $lock->execute();
                    $lock->get_result()->fetch_all(MYSQLI_ASSOC);
                }
                $res = $conn->prepare("SELECT SUM(total_price) AS grand_total FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
                if (!$res) {
                    throw new RuntimeException('prepare_grand_total_failed');
                }
                $res->bind_param("i", $fid);
                $res->execute();
                $grand = $res->get_result()->fetch_assoc();
                $grand_total = (float)($grand['grand_total'] ?? 0);

                if ($grand_total > 0) {
                    $sumBefore = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS c, COALESCE(SUM(total_price), 0) AS g FROM foundation_needlist WHERE foundation_id = ? AND ($needOpen)");
                    $old_c = 0.0;
                    $old_g = 0.0;
                    if ($sumBefore) {
                        $sumBefore->bind_param('i', $fid);
                        $sumBefore->execute();
                        $rowSb = $sumBefore->get_result()->fetch_assoc();
                        $old_c = (float)($rowSb['c'] ?? 0);
                        $old_g = (float)($rowSb['g'] ?? 0);
                    }

                    $items = $conn->prepare("SELECT item_id, total_price FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
                    if (!$items) {
                        throw new RuntimeException('prepare_item_list_failed');
                    }
                    $items->bind_param("i", $fid);
                    $items->execute();
                    $item_rows = $items->get_result();
                    while ($item = $item_rows->fetch_assoc()) {
                        $ratio       = (float)$item['total_price'] / $grand_total;
                        $item_amount = round($amount * $ratio, 2);
                        $upd = $conn->prepare("UPDATE foundation_needlist SET current_donate = current_donate + ? WHERE item_id = ?");
                        if (!$upd) {
                            throw new RuntimeException('prepare_need_update_failed');
                        }
                        $upd->bind_param("di", $item_amount, $item['item_id']);
                        $upd->execute();
                    }

                    $sumAfter = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS c, COALESCE(SUM(total_price), 0) AS g FROM foundation_needlist WHERE foundation_id = ? AND ($needOpen)");
                    if ($sumAfter) {
                        $sumAfter->bind_param('i', $fid);
                        $sumAfter->execute();
                        $rowSa = $sumAfter->get_result()->fetch_assoc();
                        $new_c = (float)($rowSa['c'] ?? 0);
                        $new_g = (float)($rowSa['g'] ?? 0);
                        $eps = 1e-6;
                        if ($old_g > $eps && $old_c < $old_g - $eps && $new_c >= $new_g - $eps) {
                            $fu = $conn->prepare('SELECT user_id, foundation_name FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
                            if ($fu) {
                                $fu->bind_param('i', $fid);
                                $fu->execute();
                                $frow = $fu->get_result()->fetch_assoc();
                                if ($frow) {
                                    $foundation_uid = (int)($frow['user_id'] ?? 0);
                                    $foundation_nm = trim((string)($frow['foundation_name'] ?? ''));
                                    if ($foundation_uid > 0) {
                                        $totalFmt = number_format($new_c, 2, '.', ',');
                                        $dispName = $foundation_nm !== '' ? '"' . $foundation_nm . '"' : 'มูลนิธิของคุณ';
                                        $title = 'รายการสิ่งของได้รับเงินครบเป้าหมายแล้ว! 🎉';
                                        $msg = 'รายการสิ่งของของ ' . $dispName . ' ได้รับเงินบริจาครวม ' . $totalFmt . ' บาท กรุณาอัปเดตผลลัพธ์สิ่งของให้ผู้บริจาคทราบภายใน 30 วัน';
                                        $link = 'foundation_post_needlist_result.php';
                                        $sigStmt = $conn->prepare("SELECT GROUP_CONCAT(item_id ORDER BY item_id) AS sig FROM foundation_needlist WHERE foundation_id = ? AND ($needOpen)");
                                        $sig = '';
                                        if ($sigStmt) {
                                            $sigStmt->bind_param('i', $fid);
                                            $sigStmt->execute();
                                            $sigRow = $sigStmt->get_result()->fetch_assoc();
                                            $sig = trim((string)($sigRow['sig'] ?? ''));
                                        }
                                        $entityKey = 'needlist_goal_met:' . $fid . ':' . ($sig !== '' ? $sig : '0');
                                        drawdream_send_notification($conn, $foundation_uid, 'needlist_funded', $title, $msg, $link, $entityKey);
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $error_kind = 'business';
                    $error_note = 'needlist_goal_not_configured';
                    throw new RuntimeException('business_needlist_goal');
                }

                $conn->commit();
                unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_foundation'], $_SESSION['pending_foundation_id']);
            } catch (Throwable $e) {
                $conn->rollback();
                if ($error_kind === '') {
                    $error_kind = 'system';
                    $error_note = $e->getMessage();
                }
                $is_success = false;
                $failure_message = $error_kind === 'business'
                    ? 'ไม่สามารถปิดรายการบริจาคได้เนื่องจากข้อมูลเป้าหมายยังไม่พร้อม'
                    : 'ระบบขัดข้องชั่วคราวระหว่างบันทึกรายการ กรุณาลองใหม่อีกครั้ง';
                error_log('[drawdream_needlist_check] ' . json_encode([
                    'charge_id' => $charge_id,
                    'foundation_id' => $fid,
                    'error_kind' => $error_kind,
                    'error_note' => $error_note,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }
}

if ($receiptDonateId <= 0 && $is_success && !$already_processed) {
    $receiptDonateId = drawdream_receipt_completed_donation_id_by_charge($conn, $charge_id);
}
if ($receiptDonateId > 0) {
    drawdream_send_e_receipt_notification_by_donate_id($conn, $receiptDonateId);
}

// ถ้าเคยประมวลผลแล้ว ให้ดึงจำนวนเงินจาก charge
if ($already_processed) {
    $is_success = true;
    $amount     = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}
if ($is_success && $amount <= 0) {
    $amount = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            <p>ขอบคุณที่ร่วมบริจาครายการสิ่งของ</p>
            <p>จำนวน <strong><?= number_format($amount, 2) ?> บาท</strong></p>
            <p class="charge-ref">อ้างอิง: <?= htmlspecialchars($charge_id) ?></p>
            <a href="../foundation.php" class="btn-pay" style="background:#CC583F; border:none; width:100%; max-width:400px; margin:32px auto 0 auto; display:block; font-size:1.3rem;">กลับหน้ามูลนิธิ</a>

        <?php elseif ($status === 'pending'): ?>
            <div class="result-icon pending">⏳</div>
            <h2>ยังไม่พบการโอนจากธนาคาร</h2>
            <p>ถ้าโอนแล้วให้รอสักครู่แล้วกด «เช็คอีกครั้ง» หาก<strong>ยังไม่ได้โอน</strong>กด «ยกเลิก» เพื่อล้าง QR และกลับไปหน้าบริจาคได้ใหม่</p>
            <?php if ($is_test_mode): ?>
                <p style="color:#a16207;">ระบบกำลังใช้ Omise Test Key (โหมดทดสอบ)</p>
            <?php endif; ?>
            <?php if (!empty($expires_at)): ?>
                <p>QR หมดอายุ: <?= htmlspecialchars($expires_at) ?></p>
            <?php endif; ?>
            <p class="charge-ref">Charge: <?= htmlspecialchars($charge_id) ?> | Status: <?= htmlspecialchars($status) ?></p>
            <a href="check_needlist_payment.php?charge_id=<?= urlencode($charge_id) ?>&fid=<?= $fid ?>"
               class="btn-pay">เช็คอีกครั้ง</a>
            <form method="post" action="abandon_qr.php" style="margin:16px 0 0 0;">
                <input type="hidden" name="charge_id" value="">
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
            <?php if (!empty($failure_code)): ?>
                <p>รหัสข้อผิดพลาด: <?= htmlspecialchars($failure_code) ?></p>
                <p>รายละเอียด: <?= htmlspecialchars($failure_message) ?></p>
            <?php endif; ?>
            <button type="button" class="btn-pay" onclick="window.location.reload()">ลองใหม่</button>
            <a href="../foundation.php" class="btn-back">กลับหน้ามูลนิธิ</a>
        <?php endif; ?>

</div>
</div>

</body>
</html>