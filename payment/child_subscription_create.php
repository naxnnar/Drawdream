<?php
// payment/child_subscription_create.php — Omise Token→Customer→Charge รอบแรก (อุปการะเด็กรายรอบ)
// สรุปสั้น: หักรอบแรกทันที + บันทึกแผน local_cron (รอบถัดไปผ่าน cron)
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/payment_bootstrap.php';
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/omise_api_client.php';
require_once dirname(__DIR__) . '/includes/omise_user_messages.php';
require_once dirname(__DIR__) . '/includes/donate_category_resolve.php';
require_once dirname(__DIR__) . '/includes/e_receipt.php';
require_once dirname(__DIR__) . '/includes/child_subscription_history.php';
require_once __DIR__ . '/omise_helpers.php';

/** @param array<string, mixed> $charge */
function child_subscription_charge_is_paid(array $charge): bool
{
    return ($charge['paid'] ?? false) === true
        || (string)($charge['status'] ?? '') === 'successful';
}

/**
 * โหมดทดสอบ Omise: mark_as_paid ให้บัตร 4242... ผ่านโดยไม่ต้อง 3-D Secure
 *
 * @param array<string, mixed> $charge
 * @return array<string, mixed>
 */
function child_subscription_finalize_charge_for_test(array $charge): array
{
    if (child_subscription_charge_is_paid($charge)) {
        return $charge;
    }
    $sourceType = strtolower(trim((string)($charge['source']['type'] ?? '')));
    if ($sourceType === 'card') {
        return $charge;
    }
    if (!drawdream_omise_test_auto_mark_paid_enabled()) {
        return $charge;
    }
    $chargeId = trim((string)($charge['id'] ?? ''));
    if ($chargeId === '') {
        return $charge;
    }
    $marked = drawdream_omise_mark_charge_as_paid_for_test($chargeId, false);

    return is_array($marked) ? $marked : $charge;
}

/**
 * หักรอบแรกด้วยบัตร — ถ้า customer ใน DB หมดอายุบน Omise จะสร้างใหม่แล้วลองอีกครั้ง
 *
 * @param array<string, string> $metaCharge
 * @param callable(string): void $subAbort
 * @param callable(string): array{ok: bool, msg: string, cust: string, card: string} $createCustomerWithCard
 * @return array{charge: array<string, mixed>, card_id: string}
 */
function child_subscription_run_first_paid_charge(
    mysqli $conn,
    int $donorUid,
    string &$custId,
    string $token,
    string $cardId,
    int $amountSatang,
    string $desc,
    array $metaCharge,
    callable $subAbort,
    callable $createCustomerWithCard
): array {
    unset($conn, $donorUid, $custId, $cardId, $createCustomerWithCard);
    $fcharge = drawdream_omise_create_token_charge($token, $amountSatang, $desc, $metaCharge);
    if (($fcharge['object'] ?? '') === 'error') {
        $m = drawdream_omise_error_message_for_user($fcharge, 'หักเงินรอบแรกไม่สำเร็จ');
        $subAbort($m);
    }
    $fcharge = child_subscription_finalize_charge_for_test($fcharge);
    $cardId = drawdream_omise_charge_card_id($fcharge);

    return ['charge' => $fcharge, 'card_id' => $cardId];
}

require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/../includes/return_to.php';

function child_subscription_public_redirect(string $relativeFromPayment): string
{
    $path = ltrim($relativeFromPayment, '/');
    if (str_starts_with($path, '../')) {
        $path = ltrim(substr($path, 3), '/');
    }

    return $path;
}

function child_subscription_redirect(string $msg, bool $ok, int $childId, int $donateId = 0): void
{
    global $conn;
    $wantJson = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && !empty($_POST['ajax']);

    $respondJson = static function (bool $jsonOk, string $redirect, string $message, int $donateIdOut = 0) use ($wantJson): void {
        if (!$wantJson) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        $payload = [
            'ok' => $jsonOk,
            'status' => $jsonOk ? 'success' : 'error',
            'redirect' => $redirect,
            'message' => $message,
        ];
        if ($donateIdOut > 0) {
            $payload['donate_id'] = $donateIdOut;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            @flush();
        }
        exit;
    };

    $deferPostSuccess = static function (int $donateIdForReceipt, int $childIdForSync) use ($conn, $wantJson): void {
        $run = static function () use ($conn, $donateIdForReceipt, $childIdForSync): void {
            if ($donateIdForReceipt > 0) {
                drawdream_send_e_receipt_notification_deferred($conn, $donateIdForReceipt);
            }
            if ($childIdForSync > 0 && function_exists('drawdream_child_sync_sponsorship_status')) {
                try {
                    drawdream_child_sync_sponsorship_status($conn, $childIdForSync, false);
                } catch (Throwable $e) {
                    error_log('[child_subscription_post_redirect] ' . $e->getMessage());
                }
            }
            if ($childIdForSync > 0 && function_exists('drawdream_child_subscription_history_cleanup_stale_reservations')) {
                drawdream_child_subscription_history_cleanup_stale_reservations($conn, $childIdForSync);
            }
        };
        if ($wantJson) {
            register_shutdown_function($run);
            return;
        }
        $run();
    };

    if ($ok && $donateId > 0) {
        $successTitle = 'อุปการะสำเร็จ';
        $q = drawdream_payment_success_receipt_query(
            $donateId,
            $successTitle,
            '../children_donate.php?id=' . $childId,
            ''
        );
        if ($q !== '' && ($wantJson || drawdream_donation_eligible_for_e_receipt($conn, $donateId))) {
            $target = '../' . $q;
            $deferPostSuccess($donateId, $childId);
            $respondJson(true, child_subscription_public_redirect($target), $msg, $donateId);
            drawdream_payment_flush_redirect($target);
            exit;
        }
        $fallback = '../children_donate.php?id=' . $childId . '&sub_msg=' . rawurlencode($msg) . '&sub_ok=1';
        $deferPostSuccess($donateId, $childId);
        $respondJson(true, child_subscription_public_redirect($fallback), $msg, $donateId);
        drawdream_payment_flush_redirect($fallback);
        exit;
    }
    if ($ok) {
        $target = '../children_donate.php?id=' . $childId . '&sub_msg=' . rawurlencode($msg) . '&sub_ok=1';
        $deferPostSuccess(0, $childId);
        $respondJson(true, child_subscription_public_redirect($target), $msg);
        drawdream_payment_flush_redirect($target);
        exit;
    }
    $failTarget = '../children_donate.php?id=' . $childId . '&sub_msg=' . rawurlencode($msg) . '&sub_ok=0';
    $respondJson(false, child_subscription_public_redirect($failTarget), $msg);
    header('Location: ' . $failTarget);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    $msg = 'กรุณาเข้าสู่ระบบก่อนจึงจะบริจาคได้';
    header('Location: ' . drawdream_login_url('login', $msg, drawdream_return_to_current_request()));
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'], true)) {
    header('Location: ../children_.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../children_.php');
    exit;
}

drawdream_csrf_require_valid('../children_.php');

$donorUid = (int)$_SESSION['user_id'];
$childId = (int)($_POST['child_id'] ?? 0);
$planRaw = (string)($_POST['plan'] ?? '');
$token = trim((string)($_POST['omiseToken'] ?? ''));

if ($childId <= 0) {
    child_subscription_redirect('ข้อมูลเด็กไม่ถูกต้อง', false, 0);
}
if ($token === '' || strpos($token, 'tokn_') !== 0) {
    child_subscription_redirect('ไม่ได้รับโทเค็นบัตรจาก Omise กรุณาลองใหม่', false, $childId);
}

$planSpec = drawdream_child_subscription_plan($planRaw);
if ($planSpec === null) {
    child_subscription_redirect('แพ็กเกอุปการะไม่ถูกต้อง', false, $childId);
}

$categoryId = drawdream_get_or_create_child_donate_category_id($conn);

$stmt = $conn->prepare(
    'SELECT c.child_id, c.child_name, c.approve_profile,
            d.omise_customer_id, u.email
     FROM foundation_children c
     CROSS JOIN donor d
     JOIN `user` u ON u.user_id = d.user_id
     WHERE c.child_id = ? AND d.user_id = ?
     LIMIT 1'
);
$stmt->bind_param('ii', $childId, $donorUid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    child_subscription_redirect('ไม่พบข้อมูลเด็กหรือผู้บริจาค', false, $childId);
}
$child = $row;
$dnRow = $row;
$email = trim((string)($dnRow['email'] ?? 'donor-' . $donorUid . '@drawdream.local'));

$preflight = drawdream_child_subscription_preflight_payment($conn, $childId, $donorUid, $child);
if (!($preflight['ok'] ?? false)) {
    child_subscription_redirect(
        (string)($preflight['message'] ?? 'ไม่สามารถสมัครอุปการะได้'),
        false,
        $childId
    );
}

$subAbort = static function (string $msg) use ($childId): void {
    child_subscription_redirect($msg, false, $childId);
};

$custId = trim((string)($dnRow['omise_customer_id'] ?? ''));
$cardId = '';


$create_omise_customer_with_card = function (string $tok) use ($email, $donorUid): array {
    $cres = drawdream_omise_post_form('/customers', [
        'email' => $email,
        'description' => 'DrawDream donor user_id=' . $donorUid,
        'card' => $tok,
    ]);
    if (($cres['object'] ?? '') === 'error') {
        $m = drawdream_omise_error_message_for_user($cres, 'สร้างลูกค้า Omise ไม่สำเร็จ');
        return ['ok' => false, 'msg' => $m, 'cust' => '', 'card' => ''];
    }
    $cid = (string)($cres['id'] ?? '');
    $def = $cres['default_card'] ?? null;
    $cr = '';
    if (is_array($def)) {
        $cr = (string)($def['id'] ?? '');
    } elseif (is_string($def) && $def !== '') {
        $cr = $def;
    }
    if ($cid === '' || $cr === '') {
        return ['ok' => false, 'msg' => 'Omise ไม่คืน customer/card', 'cust' => '', 'card' => ''];
    }
    return ['ok' => true, 'msg' => '', 'cust' => $cid, 'card' => $cr];
};

// ลูกค้า Omise ที่มีอยู่: ชาร์จด้วย token โดยตรง — ไม่เรียก POST /customers/{id}/cards
// ลูกค้าใหม่: ชาร์จด้วย token ก่อน แล้วสร้าง customer หลังตอบ JSON (defer)

$tz = new DateTimeZone('Asia/Bangkok');
$now = new DateTimeImmutable('now', $tz);
$billDay = drawdream_subscription_safe_bill_day($now);

$childName = (string)($child['child_name'] ?? '');
$desc = 'อุปการะเด็ก ' . $childName . ' — ' . $planSpec['plan_code'] . ' (' . $planSpec['amount_thb'] . ' THB)';

$metaCharge = [
    'child_id' => (string)$childId,
    'donor_user_id' => (string)$donorUid,
    'plan_code' => $planSpec['plan_code'],
    'app' => 'drawdream_child_subscription',
];

$paidPack = child_subscription_run_first_paid_charge(
    $conn,
    $donorUid,
    $custId,
    $token,
    $cardId,
    (int)$planSpec['amount_satang'],
    $desc,
    $metaCharge,
    $subAbort,
    $create_omise_customer_with_card
);
$fcharge = $paidPack['charge'];
$cardId = $paidPack['card_id'];

$firstChId = (string)($fcharge['id'] ?? '');
$firstPaid = child_subscription_charge_is_paid($fcharge);
$authUri = trim((string)($fcharge['authorize_uri'] ?? ''));
if (!$firstPaid) {
    if ($authUri !== '' && !drawdream_omise_test_auto_mark_paid_enabled()) {
        header('Location: ' . $authUri);
        exit;
    }
    $subAbort(
        'การชำระรอบแรกยังไม่สำเร็จ (สถานะ: ' . (string)($fcharge['status'] ?? '') . ') กรุณาลองอีกครั้งหรือใช้บัตรอื่น'
    );
}
$amtSat = (int)($fcharge['amount'] ?? $planSpec['amount_satang']);
$firstAmountBaht = $amtSat / 100.0;

$localSchId = 'local_cron_' . bin2hex(random_bytes(12));
$nextAt = drawdream_subscription_next_charge_at($now, $planSpec, $billDay);
$nextSql = $nextAt->format('Y-m-d H:i:s');
$transferNowSql = drawdream_subscription_now_bangkok_sql();
$planCode = $planSpec['plan_code'];

$commit = drawdream_child_subscription_commit_paid_first_charge(
    $conn,
    $childId,
    $donorUid,
    $categoryId,
    $firstAmountBaht,
    $firstChId,
    $localSchId,
    $nextSql,
    $transferNowSql,
    $planCode,
    $cardId
);
if (!($commit['ok'] ?? false)) {
    $reason = (string)($commit['reason'] ?? '');
    if (in_array($reason, ['taken', 'reserving'], true)) {
        $subAbort(
            (string)($commit['message'] ?? 'ไม่สามารถบันทึกการอุปการะได้')
            . ' (ระบบหักเงินแล้ว กรุณาติดต่อผู้ดูแลพร้อมรหัส charge: ' . $firstChId . ')'
        );
    }
    $subAbort((string)($commit['message'] ?? 'บันทึกการอุปการะไม่สำเร็จ'));
}
$firstDonateId = (int)($commit['donate_id'] ?? 0);

if ($custId === '' && $cardId !== '') {
    $deferEmail = $email;
    $deferCardId = $cardId;
    register_shutdown_function(static function () use (
        $conn,
        $donorUid,
        $deferEmail,
        $deferCardId
    ): void {
        $st = $conn->prepare('SELECT omise_customer_id FROM donor WHERE user_id = ? LIMIT 1');
        if (!$st) {
            return;
        }
        $st->bind_param('i', $donorUid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (is_array($row) && trim((string)($row['omise_customer_id'] ?? '')) !== '') {
            return;
        }
        $cres = drawdream_omise_post_form('/customers', [
            'email' => $deferEmail,
            'description' => 'DrawDream donor user_id=' . $donorUid,
            'card' => $deferCardId,
        ]);
        if (($cres['object'] ?? '') === 'error') {
            error_log('[child_subscription_create] defer customer: ' . (string)($cres['message'] ?? ''));
            return;
        }
        $newCust = trim((string)($cres['id'] ?? ''));
        if ($newCust === '') {
            return;
        }
        $upd = $conn->prepare('UPDATE donor SET omise_customer_id = ? WHERE user_id = ?');
        if ($upd) {
            $upd->bind_param('si', $newCust, $donorUid);
            $upd->execute();
        }
    });
}

$nextThai = $nextAt->format('d/m/Y') . ' เวลา 08:00 น. (เวลาไทย)';
$planLabelMap = ['monthly' => 'รายเดือน', 'semiannual' => 'ราย 6 เดือน', 'yearly' => 'รายปี'];
$planLabel = $planLabelMap[$planCode] ?? $planCode;
$successDetail = 'อุปการะ ' . $childName . ' แบบ' . $planLabel
    . ' — ' . number_format($firstAmountBaht, 0) . ' บาท · รอบถัดไป ' . $nextThai;
child_subscription_redirect($successDetail, true, $childId, $firstDonateId);
