<?php
// payment/check_project_payment.php — ยืนยันการชำระโครงการ (หลัง Omise)
// สรุปสั้น: ปิดธุรกรรมบริจาคโครงการและเพิ่มยอดโครงการโดยไม่ให้เกินเป้าหมาย
/**
 * ไฟล์นี้ใช้ "ปิดรายการจ่ายเงินบริจาคโครงการ" หลังจากผู้ใช้ชำระ:
 * - ยืนยันสถานะ charge
 * - เปลี่ยน donation จาก pending -> completed
 * - เพิ่มยอดโครงการโดยห้ามเกินเป้าหมาย (DB guard)
 * - ส่งแจ้งเตือนใบเสร็จอิเล็กทรอนิกส์
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';
include __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/admin_audit_migrate.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/payment_transaction_schema.php';
require_once __DIR__ . '/../includes/escrow_funds_schema.php';
require_once __DIR__ . '/../includes/donate_category_resolve.php';
require_once __DIR__ . '/../includes/e_receipt.php';
require_once __DIR__ . '/../includes/donate_type.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$charge_id  = $_GET['charge_id'] ?? '';
$project_id = (int)($_GET['project_id'] ?? 0);

if (empty($charge_id)) {
    header("Location: ../project.php");
    exit();
}

// ตรวจสอบว่าเป็น mock charge (สร้างโดย _omise_local_mock เมื่อ API ไม่ตอบสนอง)
$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge  = [];

if ($is_mock) {
    // mock charge → ถือว่าสำเร็จทันที ใช้ข้อมูลจาก session
    $charge = [
        'status'   => 'successful',
        'paid'     => true,
        'amount'   => ($_SESSION['pending_amount'] ?? 0) * 100,
        'metadata' => ['project_id' => (int)($_SESSION['pending_project_id'] ?? $project_id)],
    ];
} else {
    // เช็คสถานะ charge จาก Omise API
    $ch = curl_init(rtrim(OMISE_API_URL, '/') . '/charges/' . rawurlencode($charge_id) . '?expand[]=source');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, OMISE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $charge = json_decode($response, true) ?? [];
}

// fallback project_id จาก metadata/session หากไม่มีใน URL
if ($project_id <= 0) {
    $project_id = (int)($charge['metadata']['project_id'] ?? ($_SESSION['pending_project_id'] ?? 0));
}

$status          = $charge['status'] ?? 'unknown';
$paid            = $charge['paid'] ?? false;
$failure_code    = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at      = $charge['expires_at'] ?? '';
$is_test_mode    = (strpos(OMISE_PUBLIC_KEY, 'pkey_test_') === 0) || (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0);

$is_success = ($paid === true) || ($status === 'successful') || $is_mock;
$amount     = 0;

// รายการเดิมที่สร้างตอนเปิด QR (pending) หรือ completed แล้ว
$ptRow = null;
$dup = $conn->prepare("SELECT donate_id, transaction_status FROM donation WHERE omise_charge_id = ? LIMIT 1");
$dup->bind_param("s", $charge_id);
$dup->execute();
$ptRow = $dup->get_result()->fetch_assoc();
$already_completed = is_array($ptRow) && (($ptRow['transaction_status'] ?? '') === 'completed');
$has_pending = is_array($ptRow) && (($ptRow['transaction_status'] ?? '') === 'pending');

$donor_uid = (int)$_SESSION['user_id'];
// Omise แจ้งว่ารายการถึงที่สุดแล้ว (ไม่สำเร็จ/หมดอายุ) → อัปเดตฐานข้อมูลเป็น failed ไม่ค้าง pending
if (!$is_mock && $has_pending && !$already_completed && !$is_success
    && in_array($status, ['failed', 'expired'], true)) {
    // ถ้า Omise ตอบว่า fail/expired ให้ปิดรายการ pending ทันที
    drawdream_abandon_pending_donation_by_charge($conn, $donor_uid, $charge_id);
    drawdream_clear_pending_payment_session();
    $has_pending = false;
    if (is_array($ptRow)) {
        $ptRow['transaction_status'] = 'failed';
    }
}

function drawdream_project_bump_and_maybe_complete(mysqli $conn, int $project_id, float $amountBaht): bool
{
    // lock แถวโครงการนี้ก่อน (กันยอดเพี้ยนเมื่อมีคนจ่ายพร้อมกันหลายคน)
    $sel = $conn->prepare('SELECT goal_amount, current_donate FROM foundation_project WHERE project_id = ? AND deleted_at IS NULL FOR UPDATE');
    $sel->bind_param('i', $project_id);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }
    $g = (float)($row['goal_amount'] ?? 0);
    $r = (float)($row['current_donate'] ?? 0);
    if ($g > 0) {
        if ($r >= $g - 1e-9) {
            // ถ้าครบเป้าแล้ว ไม่ให้เพิ่มยอดต่อ
            return false;
        }
        if ($r + $amountBaht > $g + 1e-6) {
            // ถ้าเพิ่มแล้วเกินเป้า ให้หยุดทันที
            return false;
        }
    }

    $stmt = $conn->prepare('UPDATE foundation_project SET current_donate = current_donate + ? WHERE project_id = ? AND deleted_at IS NULL');
    $stmt->bind_param('di', $amountBaht, $project_id);
    if (!$stmt->execute() || $stmt->affected_rows < 1) {
        return false;
    }

    $check = $conn->prepare("
        SELECT p.project_id, p.project_name, p.current_donate, fp.user_id AS foundation_user_id, fp.foundation_name
        FROM foundation_project p
        JOIN foundation_profile fp ON p.foundation_name = fp.foundation_name
        WHERE p.project_id = ?
          AND p.project_status = 'approved'
          AND p.deleted_at IS NULL
          AND (
              p.current_donate >= p.goal_amount
              OR (p.end_date IS NOT NULL AND p.end_date <= CURDATE())
          )
    ");
    $check->bind_param('i', $project_id);
    $check->execute();
    $completed_proj = $check->get_result()->fetch_assoc();
    if ($completed_proj) {
        $upd = $conn->prepare("UPDATE foundation_project SET project_status = 'completed', completed_at = NOW() WHERE project_id = ? AND deleted_at IS NULL");
        $upd->bind_param('i', $project_id);
        $upd->execute();

        $foundation_user_id = (int)$completed_proj['foundation_user_id'];
        $proj_name = $completed_proj['project_name'];
        $total = number_format((float)$completed_proj['current_donate'], 2);

        $notif_type_th = drawdream_normalize_notif_type_to_th('project_completed');
        $notif = $conn->prepare('
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, ?, ?, ?, ?)
        ');
        $notif_title = "โครงการของคุณได้รับเงินครบแล้ว! 🎉";
        $notif_msg = "โครงการ \"$proj_name\" ได้รับเงินบริจาครวม $total บาท กรุณาโพสต์ความคืบหน้าให้ผู้บริจาคทราบภายใน 30 วัน";
        $notif_link = 'foundation_post_update.php?project_id=' . $project_id;
        $notif->bind_param('issss', $foundation_user_id, $notif_type_th, $notif_title, $notif_msg, $notif_link);
        $notif->execute();
    }

    return true;
}

/**
 * @param int $donate_id_param donate_id จาก payment_transaction (0 = ยังไม่มีแถว donation — สร้างเมื่อสำเร็จ)
 */
function drawdream_finalize_project_donation(
    mysqli $conn,
    int $project_id,
    int $donate_id_param,
    string $charge_id,
    float $amountBaht,
    int $donor_user_id
): bool {
    drawdream_payment_transaction_ensure_schema($conn);

    $pend = 'pending';
    $pt = $conn->prepare(
        'SELECT donate_id, category_id, target_id, donor_id
         FROM donation WHERE omise_charge_id = ? AND transaction_status = ? LIMIT 1'
    );
    $pt->bind_param('ss', $charge_id, $pend);
    $pt->execute();
    $ptRow = $pt->get_result()->fetch_assoc();
    if (!$ptRow) {
        return false;
    }

    $ptDonateId = isset($ptRow['donate_id']) && $ptRow['donate_id'] !== null ? (int)$ptRow['donate_id'] : 0;

    if ($donate_id_param > 0 && $ptDonateId > 0 && $donate_id_param !== $ptDonateId) {
        return false;
    }

    $pDonor = (int)($ptRow['donor_id'] ?? 0);
    $pTarget = (int)($ptRow['target_id'] ?? 0);
    $pCat = (int)($ptRow['category_id'] ?? 0);
    if ($pDonor !== $donor_user_id || $pTarget !== $project_id || $pCat <= 0 || $ptDonateId <= 0) {
        return false;
    }

    if (!$conn->begin_transaction()) {
        return false;
    }
    try {
        $completed = 'completed';
        $dtProj = DRAWDREAM_DONATE_TYPE_PROJECT;
        $planOnce = DRAWDREAM_DONATION_RECURRING_PLAN_ONE_TIME;
        $upt = $conn->prepare(
            'UPDATE donation
             SET amount = ?, payment_status = ?, transaction_status = ?, transfer_datetime = NOW(), donate_type = ?,
                 recurring_plan_code = ?
             WHERE donate_id = ? AND transaction_status = ?'
        );
        $upt->bind_param('dssssis', $amountBaht, $completed, $completed, $dtProj, $planOnce, $ptDonateId, $pend);
        $upt->execute();
        if ($upt->affected_rows < 1) {
            throw new RuntimeException('update donation');
        }

        if (!drawdream_project_bump_and_maybe_complete($conn, $project_id, $amountBaht)) {
            throw new RuntimeException('project bump');
        }

        if (!drawdream_escrow_funds_try_insert_holding($conn, $project_id, $ptDonateId, $charge_id, $amountBaht)) {
            throw new RuntimeException('escrow insert');
        }

        $conn->commit();

        return true;
    } catch (Throwable $e) {
        $conn->rollback();

        return false;
    }
}

$finalized_this_request = false;
$receiptDonateId = 0;
if ($is_success && $has_pending && !$already_completed && $project_id > 0) {
    $amount      = ($charge['amount'] ?? 0) / 100;
    if ($amount <= 0) {
        $amount = (float)($_SESSION['pending_amount'] ?? 0);
    }
    $donate_id_from_pt = (int)($ptRow['donate_id'] ?? 0);
    if (drawdream_finalize_project_donation($conn, $project_id, $donate_id_from_pt, $charge_id, (float)$amount, $donor_uid)) {
        $finalized_this_request = true;
        $receiptDonateId = $donate_id_from_pt;
        unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_project'], $_SESSION['pending_project_id'], $_SESSION['pending_donate_id'], $_SESSION['qr_image']);
    }
} elseif ($is_success && !$ptRow && $project_id > 0) {
    // เส้นทางเก่า: ยังไม่มีแถว pending (สแกนจากลิงก์เก่า)
    $amount      = ($charge['amount'] ?? 0) / 100;
    $donor_id    = $_SESSION['user_id'];
    $target_id   = $project_id;

    $category_id = drawdream_get_or_create_project_donate_category_id($conn);

    $dtProj = DRAWDREAM_DONATE_TYPE_PROJECT;
    if ($conn->begin_transaction()) {
        try {
            $planOnce = DRAWDREAM_DONATION_RECURRING_PLAN_ONE_TIME;
            $stmt = $conn->prepare("
                INSERT INTO donation (
                    category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                    omise_charge_id, transaction_status, donate_type, recurring_plan_code
                ) VALUES (?, ?, ?, ?, 'completed', NOW(), ?, 'completed', ?, ?)
            ");
            $stmt->bind_param('iiidsss', $category_id, $target_id, $donor_id, $amount, $charge_id, $dtProj, $planOnce);
            $stmt->execute();
            $receiptDonateId = (int)$conn->insert_id;

            if (!drawdream_project_bump_and_maybe_complete($conn, $project_id, (float)$amount)) {
                throw new RuntimeException('project bump');
            }
            if (!drawdream_escrow_funds_try_insert_holding($conn, $project_id, $receiptDonateId, $charge_id, (float)$amount)) {
                throw new RuntimeException('escrow insert');
            }
            $conn->commit();
            unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_project'], $_SESSION['pending_project_id'], $_SESSION['pending_donate_id'], $_SESSION['qr_image']);
            $finalized_this_request = true;
        } catch (Throwable $e) {
            $conn->rollback();
            $receiptDonateId = 0;
        }
    }
}

if ($finalized_this_request && $receiptDonateId <= 0) {
    $receiptDonateId = drawdream_receipt_completed_donation_id_by_charge($conn, $charge_id);
}
if ($finalized_this_request && $receiptDonateId > 0) {
    drawdream_send_e_receipt_notification_by_donate_id($conn, $receiptDonateId);
}

$already_processed = $finalized_this_request
    || $already_completed
    || ($is_success && is_array($ptRow) && ($ptRow['transaction_status'] ?? '') === 'completed');

// ถ้าเคยประมวลผลแล้ว ให้ดึงจำนวนเงินจาก charge
if ($already_processed) {
    $is_success = true;
    $amount     = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}
// ตั้ง amount หากยังเป็น 0 (เช่น mock + already_processed)
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
            <p>ขอบคุณที่ร่วมบริจาคให้โครงการ</p>
            <p>จำนวน <strong><?= number_format($amount, 2) ?> บาท</strong></p>
            <p class="charge-ref">อ้างอิง: <?= htmlspecialchars($charge_id) ?></p>
            <a href="../project.php" class="btn-pay">กลับหน้าโครงการ</a>

        <?php elseif ($status === 'pending'): ?>
            <div class="result-icon pending">⏳</div>
            <h2>ยังไม่พบการโอนจากธนาคาร</h2>
            <p>ถ้าคุณสแกนจ่ายแล้ว อาจต้องรอสักครู่แล้วกด «เช็คอีกครั้ง» หาก<strong>ยังไม่ได้โอนจริง</strong>กด «ยกเลิกรายการนี้» — ระบบจะไม่เก็บเป็นคำว่ารอดำเนินการ และคุณสามารถกดบริจาคใหม่ได้</p>
            <?php if ($is_test_mode): ?>
                <p style="color:#a16207;">ระบบกำลังใช้ Omise Test Key (โหมดทดสอบ) การสแกนจ่ายจริงอาจไม่เปลี่ยนสถานะเป็นสำเร็จ — ใน Dashboard ให้เปิดรายการนี้แล้วใช้ <strong>Mark as paid</strong></p>
                <?php $omise_charge_test_url = 'https://dashboard.omise.co/test/charges/' . rawurlencode($charge_id); ?>
                <p><a href="<?= htmlspecialchars($omise_charge_test_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">เปิด charge นี้ใน Omise Dashboard (test)</a></p>
            <?php endif; ?>
            <?php if (!empty($expires_at)): ?>
                <p>QR หมดอายุ: <?= htmlspecialchars($expires_at) ?></p>
            <?php endif; ?>
            <p class="charge-ref">Charge: <?= htmlspecialchars($charge_id) ?> | Status: <?= htmlspecialchars($status) ?> | Paid: <?= $paid ? 'true' : 'false' ?></p>
            <a href="check_project_payment.php?charge_id=<?= urlencode($charge_id) ?>&project_id=<?= $project_id ?>"
               class="btn-pay">เช็คอีกครั้ง</a>
            <form method="post" action="abandon_qr.php" style="margin:16px 0 0 0;">
                <input type="hidden" name="charge_id" value="<?= htmlspecialchars($charge_id, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="return_url" value="../project.php">
                <button type="submit" class="btn-back" style="width:100%;max-width:400px;border:1px solid #b91c1c;color:#b91c1c;background:#fff;cursor:pointer;padding:12px;border-radius:8px;font-weight:600;">
                    ยกเลิกรายการนี้ (ยังไม่ได้โอน)
                </button>
            </form>
            <a href="../project.php" class="btn-back">กลับหน้าโครงการ</a>

        <?php else: ?>
            <div class="result-icon error">✕</div>
            <h2>ชำระเงินไม่สำเร็จ</h2>
            <p>สถานะ: <?= htmlspecialchars($status) ?></p>
            <?php if (!empty($failure_code) || !empty($failure_message)): ?>
                <p>รหัสข้อผิดพลาด: <?= htmlspecialchars($failure_code) ?></p>
                <p>รายละเอียด: <?= htmlspecialchars($failure_message) ?></p>
            <?php endif; ?>
            <button type="button" class="btn-pay" onclick="window.location.reload()">ลองใหม่</button>
            <a href="../project.php" class="btn-back">กลับหน้าโครงการ</a>
        <?php endif; ?>

</div>
</div>

</body>
</html>