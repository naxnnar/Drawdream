<?php
// payment/scan_qr.php — หน้าแสดง QR หลังสร้าง charge (ร่วมทุกประเภท)
// สรุปสั้น: แสดง QR ชำระเงินและเฝ้าสถานะรายการจนผู้ใช้จ่ายสำเร็จหรือยกเลิก
/**
 * หน้าแสดง QR หลังสร้าง Omise charge (โครงการ / เด็ก / มูลนิธิ)
 *
 * ตรวจสอบ charge_id + session (pending_*) ให้ตรงกันก่อนแสดง — กันป้อน URL ข้ามคน
 * ภาพ QR ควรมาจาก Omise (session / GET charge) — ไม่ใช้ภาพจำลองในโหมดทดสอบยกเว้นชาร์จ mock (OMISE_ALLOW_LOCAL_MOCK)
 */
require_once __DIR__ . '/../includes/payment_bootstrap.php';
include 'config.php';

$type = $_GET['type'] ?? 'project';
if (!in_array($type, ['project', 'child', 'foundation'], true)) {
    $type = 'project';
}

$charge_id = trim((string)($_GET['charge_id'] ?? ($_SESSION['pending_charge_id'] ?? '')));
$amount = (int)($_SESSION['pending_amount'] ?? 0);

$qr_fail = function (string $url) use ($type): void {
    if ($type === 'child') {
        header('Location: ../children_.php');
    } elseif ($type === 'foundation') {
        header('Location: ../foundation.php');
    } else {
        header('Location: ' . $url);
    }
    exit;
};

if ($charge_id === '' || $amount < 20) {
    $qr_fail('../project.php');
}
if (!isset($_SESSION['pending_charge_id']) || $_SESSION['pending_charge_id'] !== $charge_id) {
    $qr_fail('../project.php');
}

$project_id = 0;
$child_id = 0;
$fid = 0;
$project_name = '';
$child_name = '';
$foundation_name = '';
$return_payment_page = 'payment_project.php';
$receipt_target_label = '';
$receipt_target_value = '';
$subtitle_line = '';
$page_title = 'Scan QR Code เพื่อบริจาค';
$back_aria = 'กลับไปหน้าชำระเงิน';

if ($type === 'project') {
    $project_id = (int)($_GET['project_id'] ?? ($_SESSION['pending_project_id'] ?? 0));
    if ($project_id <= 0 || (int)($_SESSION['pending_project_id'] ?? 0) !== $project_id) {
        $qr_fail('../project.php');
    }
    $project_name = trim((string)($_SESSION['pending_project'] ?? ''));
    if ($project_name === '') {
        $st = $conn->prepare('SELECT project_name FROM foundation_project WHERE project_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $project_id);
            $st->execute();
            $pn = $st->get_result()->fetch_assoc();
            if ($pn) {
                $project_name = (string)($pn['project_name'] ?? '');
            }
        }
    }
    $subtitle_line = 'บริจาคให้กับโครงการ ' . $project_name;
    $receipt_target_label = 'ชื่อโครงการ';
    $receipt_target_value = $project_name;
    $return_payment_page = 'payment_project.php?project_id=' . $project_id;
    $back_aria = 'กลับไปหน้าชำระเงินโครงการ';
} elseif ($type === 'child') {
    $child_id = (int)($_GET['child_id'] ?? ($_SESSION['pending_child_id'] ?? 0));
    if ($child_id <= 0 || (int)($_SESSION['pending_child_id'] ?? 0) !== $child_id) {
        header('Location: ../children_.php');
        exit;
    }
    $child_name = trim((string)($_SESSION['pending_child_name'] ?? ''));
    if ($child_name === '') {
        $st = $conn->prepare('SELECT child_name FROM foundation_children WHERE child_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $child_id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if ($row) {
                $child_name = (string)($row['child_name'] ?? '');
            }
        }
    }
    $subtitle_line = 'บริจาคให้เด็ก ชื่อ น้อง' . $child_name;
    $receipt_target_label = 'ชื่อเด็ก';
    $receipt_target_value = $child_name;
    $return_payment_page = '../children_donate.php?id=' . $child_id;
    $back_aria = 'กลับไปหน้าโปรไฟล์เด็ก';
} else {
    $fid = (int)($_GET['fid'] ?? ($_SESSION['pending_foundation_id'] ?? 0));
    if ($fid <= 0 || (int)($_SESSION['pending_foundation_id'] ?? 0) !== $fid) {
        header('Location: ../foundation.php');
        exit;
    }
    $foundation_name = trim((string)($_SESSION['pending_foundation'] ?? ''));
    if ($foundation_name === '') {
        $st = $conn->prepare('SELECT foundation_name FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $fid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if ($row) {
                $foundation_name = (string)($row['foundation_name'] ?? '');
            }
        }
    }
    $subtitle_line = 'บริจาคเงินเพื่อสมทบทุนจัดซื้อสิ่งของ — ' . $foundation_name;
    $receipt_target_label = 'มูลนิธิ';
    $receipt_target_value = $foundation_name;
    $return_payment_page = 'foundation_donate.php?fid=' . $fid;
    $back_aria = 'กลับไปหน้าบริจาคมูลนิธิ';
}

$qr_image = '';
$qr_missing = false;
require_once __DIR__ . '/omise_helpers.php';
$is_test_mode = drawdream_omise_is_test_mode();
if ($_SESSION['pending_charge_id'] === $charge_id) {
    $qr_image = trim((string)($_SESSION['qr_image'] ?? ''));
}
$is_mock_charge = (strpos($charge_id, 'chrg_mock_') === 0);
if ($qr_image === '' && !$is_mock_charge) {
    $fetched = drawdream_omise_fetch_charge($charge_id);
    if ($fetched) {
        $qr_image = drawdream_omise_promptpay_qr_uri_from_charge($fetched);
        if ($qr_image !== '' && $_SESSION['pending_charge_id'] === $charge_id) {
            $_SESSION['qr_image'] = $qr_image;
        }
    }
}
$qr_missing = ($qr_image === '');

$auto_test_pay = $is_test_mode && drawdream_omise_test_auto_mark_paid_enabled();
if ($auto_test_pay && !$is_mock_charge) {
    drawdream_omise_ensure_test_charge_paid($charge_id);
}

$poll_ms = $auto_test_pay ? 800 : ($type === 'foundation' ? 1200 : 2000);
$payment_poll_url = '';
$goal_slot_poll_url = '';
if ($type === 'child' && $child_id > 0) {
    $payment_poll_url = 'check_child_payment.php?poll=1&charge_id=' . rawurlencode($charge_id)
        . '&child_id=' . $child_id;
} elseif ($type === 'project' && $project_id > 0) {
    $payment_poll_url = 'check_project_payment.php?poll=1&charge_id=' . rawurlencode($charge_id)
        . '&project_id=' . $project_id;
    $goal_slot_poll_url = 'project_goal_slot_check.php?project_id=' . $project_id
        . '&amount=' . $amount
        . '&charge_id=' . rawurlencode($charge_id);
} elseif ($type === 'foundation' && $fid > 0) {
    $payment_poll_url = 'check_needlist_payment.php?poll=1&charge_id=' . rawurlencode($charge_id)
        . '&fid=' . $fid;
    $goal_slot_poll_url = 'needlist_goal_slot_check.php?fid=' . $fid
        . '&amount=' . $amount
        . '&charge_id=' . rawurlencode($charge_id);
}

$receipt_no = strtoupper(substr($charge_id, -10));
$goal_closed_message = ($type === 'foundation')
    ? 'รายการสิ่งของครบเป้าหมายแล้ว'
    : 'โครงการครบเป้าหมายแล้ว';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="../css/payment.css">
    <style>
        body { background: #f7ecde; }
        .qr-main { max-width: 520px; margin: 36px auto; background: #fff; border-radius: 18px; box-shadow: 0 2px 16px 0 rgba(0,0,0,0.07); padding: 32px 28px 28px 28px; }
        .qr-header-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 18px; }
        .qr-header-spacer { width: 44px; height: 44px; flex-shrink: 0; }
        .qr-title { flex: 1; font-size: 2em; font-weight: 700; text-align: center; margin: 0; line-height: 1.2; }
        .qr-back-icon {
            flex-shrink: 0; width: 44px; height: 44px; border-radius: 50%; background: #f0f2fa; color: #3C5099;
            display: flex; align-items: center; justify-content: center; text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .qr-back-icon:hover { background: #e2e6f5; color: #2d4580; }
        .qr-back-icon svg { display: block; }
        .qr-amount-bar { background: #f0f2fa; border-radius: 10px; padding: 16px 0 12px 0; font-size: 1.5em; color: #3C5099; font-weight: 700; text-align: center; margin-bottom: 18px; }
        .qr-project { display: flex; align-items: center; gap: 16px; margin-bottom: 12px; }
        .qr-project-info { font-size: 1.1em; }
        .qr-section { text-align: center; margin: 24px 0 18px 0; }
        .qr-section img { max-width: 260px; width: 100%; background: #fff; padding: 16px; border-radius: 16px; box-shadow: 0 2px 12px 0 rgba(0,0,0,0.08); }
        .qr-receipt { background: #f7f7f7; border-radius: 12px; padding: 18px 18px 10px 18px; margin-top: 18px; font-size: 1.08em; }
        .qr-receipt-row { margin-bottom: 8px; }
        .qr-test-auto-hint {
            margin-top: 12px;
            text-align: center;
            line-height: 1.55;
            padding: 12px 14px;
            background: #fffbeb;
            border-radius: 12px;
            border: 1px solid #fcd34d;
            color: #a16207;
            font-size: 1.02em;
        }
        .qr-goal-closed {
            display: none;
            text-align: left;
            line-height: 1.55;
            padding: 16px;
            background: #fff3e0;
            border-radius: 12px;
            border: 1px solid #fdba74;
            color: #9a3412;
            margin: 16px 0;
        }
        .qr-goal-closed.is-visible { display: block; }
        .qr-section.is-hidden { display: none; }
    </style>
</head>
<body>
    <div class="qr-main">
        <div class="qr-header-row">
            <a class="qr-back-icon"
               href="<?= htmlspecialchars($return_payment_page, ENT_QUOTES, 'UTF-8') ?>"
               title="กลับ"
               aria-label="<?= htmlspecialchars($back_aria, ENT_QUOTES, 'UTF-8') ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <div class="qr-title"><?= htmlspecialchars($page_title) ?></div>
            <div class="qr-header-spacer" aria-hidden="true"></div>
        </div>
        <div class="qr-amount-bar">ยอดบริจาค <?= number_format($amount) ?> บาท</div>
        <div class="qr-project">
            <span class="qr-project-info" style="font-size:1.1em;font-weight:600;display:inline-block;"><?= htmlspecialchars($subtitle_line) ?></span>
        </div>
        <div id="qrGoalClosed" class="qr-goal-closed" role="alert" aria-live="polite">
            <strong><?= htmlspecialchars($goal_closed_message, ENT_QUOTES, 'UTF-8') ?></strong><br>
            มีผู้บริจาคท่านอื่นปิดยอดก่อนคุณ — <strong>กรุณาอย่าชำระเงิน</strong> รายการ QR นี้ถูกยกเลิกแล้ว
        </div>
        <div class="qr-section" id="qrImageSection">
            <?php if ($qr_missing): ?>
                <p style="color:#b45309;text-align:left;line-height:1.5;padding:12px;background:#fffbeb;border-radius:12px;border:1px solid #fcd34d;">
                    ไม่สามารถโหลดภาพ QR จาก Omise ได้ (อาจเป็นเครือข่ายหรือคีย์ API)<br>
                    ลองกลับไปหน้าชำระเงินแล้วกดบริจาคใหม่
                    <?php if ($auto_test_pay): ?>
                        — โหมดทดสอบจะยืนยันอัตโนมัติ รอสักครู่แล้วระบบพาไปใบเสร็จ
                    <?php elseif ($is_test_mode): ?>
                        หรือเปิด
                        <a href="https://dashboard.omise.co/test/charges" target="_blank" rel="noopener">Omise Dashboard (test)</a>
                        ค้นหา charge <code style="word-break:break-all;"><?= htmlspecialchars($charge_id) ?></code>
                        แล้วใช้ «Mark as paid»
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <img src="<?= htmlspecialchars($qr_image, ENT_QUOTES, 'UTF-8') ?>" alt="PromptPay QR">
            <?php endif; ?>
        </div>
        <?php if ($auto_test_pay): ?>
            <p id="qrTestAutoHint" class="qr-test-auto-hint" role="status" aria-live="polite">
                โหมดทดสอบ — ระบบยืนยันอัตโนมัติ กำลังพาไปใบเสร็จ...
            </p>
        <?php elseif ($is_test_mode && !$qr_missing): ?>
            <div style="margin-top:12px;text-align:left;"><?= drawdream_omise_test_pending_help_html($charge_id) ?></div>
        <?php endif; ?>
        <div class="qr-receipt">
            <div class="qr-receipt-row"><b>จำนวนเงิน</b> <?= number_format($amount, 2) ?> บาท</div>
            <div class="qr-receipt-row"><b>เลขที่รายการบริจาค</b> <?= htmlspecialchars($receipt_no) ?></div>
            <div class="qr-receipt-row"><b><?= htmlspecialchars($receipt_target_label) ?></b> <?= htmlspecialchars($receipt_target_value) ?></div>
            <div class="qr-receipt-row"><b>วันที่</b> <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>
<?php if ($payment_poll_url !== ''): ?>
<script>
(function () {
    var pollMs = <?= (int)$poll_ms ?>;
    var paymentPollUrl = <?= json_encode($payment_poll_url, JSON_UNESCAPED_UNICODE) ?>;
    var goalSlotPollUrl = <?= json_encode($goal_slot_poll_url !== '' ? $goal_slot_poll_url : null, JSON_UNESCAPED_UNICODE) ?>;
    var closedBox = document.getElementById('qrGoalClosed');
    var imgSection = document.getElementById('qrImageSection');
    var goalClosed = false;
    var paymentStopped = false;
    var paymentTimer = null;
    var goalTimer = null;
    var paymentFailUrl = <?= json_encode(
        $type === 'foundation'
            ? ('check_needlist_payment.php?charge_id=' . rawurlencode($charge_id) . '&fid=' . $fid)
            : ($type === 'project'
                ? ('check_project_payment.php?charge_id=' . rawurlencode($charge_id) . '&project_id=' . $project_id)
                : ('check_child_payment.php?charge_id=' . rawurlencode($charge_id) . '&child_id=' . $child_id)),
        JSON_UNESCAPED_UNICODE
    ) ?>;

    function clearPaymentTimer() {
        if (paymentTimer) {
            clearTimeout(paymentTimer);
            paymentTimer = null;
        }
    }

    function clearGoalTimer() {
        if (goalTimer) {
            clearTimeout(goalTimer);
            goalTimer = null;
        }
    }

    function applyGoalClosed() {
        if (goalClosed) {
            return;
        }
        goalClosed = true;
        clearGoalTimer();
        if (closedBox) {
            closedBox.classList.add('is-visible');
        }
        if (imgSection) {
            imgSection.classList.add('is-hidden');
        }
    }

    function schedulePaymentPoll() {
        if (paymentStopped || document.hidden) {
            return;
        }
        paymentTimer = setTimeout(pollPayment, pollMs);
    }

    function scheduleGoalPoll() {
        if (goalClosed || document.hidden || !goalSlotPollUrl) {
            return;
        }
        goalTimer = setTimeout(pollGoalSlot, pollMs);
    }

    function pollPayment() {
        if (paymentStopped) {
            return;
        }
        fetch(paymentPollUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.redirect && (
                    data.status === 'success'
                    || data.status === 'paid_goal_late'
                    || data.status === 'paid_goal_refunded'
                    || data.status === 'failed'
                )) {
                    window.location.href = data.redirect;
                    return;
                }
                if (!paymentStopped && !document.hidden) {
                    schedulePaymentPoll();
                }
            })
            .catch(function () {
                if (!paymentStopped && !document.hidden) {
                    paymentTimer = setTimeout(pollPayment, Math.min(pollMs + 1000, 8000));
                }
            });
    }

    function pollGoalSlot() {
        if (goalClosed || !goalSlotPollUrl) {
            return;
        }
        fetch(goalSlotPollUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.closed) {
                    applyGoalClosed();
                    return;
                }
                if (!goalClosed && !document.hidden) {
                    scheduleGoalPoll();
                }
            })
            .catch(function () {
                if (!goalClosed && !document.hidden) {
                    goalTimer = setTimeout(pollGoalSlot, Math.min(pollMs + 1000, 6000));
                }
            });
    }

    pollPayment();
    if (goalSlotPollUrl) {
        pollGoalSlot();
    }

    document.addEventListener('visibilitychange', function () {
        clearPaymentTimer();
        clearGoalTimer();
        if (!document.hidden && !paymentStopped) {
            pollPayment();
        }
        if (!document.hidden && !goalClosed && goalSlotPollUrl) {
            pollGoalSlot();
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
