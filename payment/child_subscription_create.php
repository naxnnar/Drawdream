<?php
// payment/child_subscription_create.php — Omise Token→Customer→Charge Schedule (อุปการะเด็กรายรอบ)
// สรุปสั้น: สร้างแผนอุปการะเด็กรายรอบผ่าน Omise และบันทึกข้อมูลแผนลงระบบ
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
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
 * Omise ต้องการ on[days_of_month][]=N ไม่ใช่ on[days_of_month][0]=N
 *
 * @param array<string, string|int|float> $chargeFlat แบบ charge[customer], charge[amount], charge[metadata][k]
 */
function drawdream_omise_build_schedule_body(
    int $every,
    string $period,
    string $startDate,
    string $endDate,
    int $billDay,
    array $chargeFlat
): string {
    $pairs = [
        'every=' . (int)$every,
        'period=' . rawurlencode($period),
        'start_date=' . rawurlencode($startDate),
        'end_date=' . rawurlencode($endDate),
        'on[days_of_month][]=' . (int)$billDay,
    ];
    foreach ($chargeFlat as $k => $v) {
        $pairs[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
    }
    return implode('&', $pairs);
}

/** @param array<string, mixed> $charge */
function drawdream_omise_post_schedule(
    int $every,
    string $period,
    string $startDate,
    string $endDate,
    int $billDay,
    array $charge
): ?array {
    $flat = [
        'charge[customer]' => (string)($charge['customer'] ?? ''),
        'charge[amount]' => (string)(int)($charge['amount'] ?? 0),
        'charge[currency]' => strtolower((string)($charge['currency'] ?? 'thb')),
        'charge[description]' => (string)($charge['description'] ?? ''),
    ];
    $cardRef = trim((string)($charge['card'] ?? ''));
    if ($cardRef !== '') {
        $flat['charge[card]'] = $cardRef;
    }
    $meta = $charge['metadata'] ?? [];
    if (is_array($meta)) {
        foreach ($meta as $mk => $mv) {
            $flat['charge[metadata][' . $mk . ']'] = (string)$mv;
        }
    }
    $body = drawdream_omise_build_schedule_body($every, $period, $startDate, $endDate, $billDay, $flat);
    $url = rtrim(OMISE_API_URL, '/') . '/schedules';
    $ch = curl_init($url);
    drawdream_omise_curl_apply_omise_defaults($ch);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false || $response === '') {
        $m = $curlErr !== '' ? $curlErr : ('เชื่อมต่อ Omise ไม่ได้ (HTTP ' . $httpCode . ')');
        return ['object' => 'error', 'code' => 'curl', 'message' => $m];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'object' => 'error',
            'code' => 'invalid_json',
            'message' => 'คำตอบ Omise ไม่ใช่ JSON: ' . substr((string)$response, 0, 160),
        ];
    }
    return $decoded;
}
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/../includes/return_to.php';

function child_subscription_redirect(string $msg, bool $ok, int $childId): void
{
    $q = 'id=' . $childId . '&sub_msg=' . rawurlencode($msg) . '&sub_ok=' . ($ok ? '1' : '0');
    header('Location: ../children_donate.php?' . $q);
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

drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_omise_subscription_ensure_schema($conn);

$stmt = $conn->prepare(
    'SELECT c.*, COALESCE(NULLIF(c.foundation_name, \'\'), fp.foundation_name) AS display_foundation_name
     FROM foundation_children c
     LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
     WHERE c.child_id = ? LIMIT 1'
);
$stmt->bind_param('i', $childId);
$stmt->execute();
$child = $stmt->get_result()->fetch_assoc();
if (!$child) {
    child_subscription_redirect('ไม่พบข้อมูลเด็ก', false, $childId);
}

$stMail = $conn->prepare('SELECT email FROM `user` WHERE user_id = ? LIMIT 1');
$stMail->bind_param('i', $donorUid);
$stMail->execute();
$ur = $stMail->get_result()->fetch_assoc();
$email = trim((string)($ur['email'] ?? 'donor-' . $donorUid . '@drawdream.local'));

$stDn = $conn->prepare('SELECT donor_id, omise_customer_id FROM donor WHERE user_id = ? LIMIT 1');
$stDn->bind_param('i', $donorUid);
$stDn->execute();
$dnRow = $stDn->get_result()->fetch_assoc();
if (!$dnRow) {
    child_subscription_redirect('ไม่พบโปรไฟล์ผู้บริจาค', false, $childId);
}

$slotReserved = false;
$reserve = drawdream_child_subscription_reserve_slot($conn, $childId, $donorUid, $planSpec['plan_code']);
if (!($reserve['ok'] ?? false)) {
    child_subscription_redirect(
        (string)($reserve['message'] ?? 'ไม่สามารถจองสิทธิ์อุปการะได้'),
        false,
        $childId
    );
}
$slotReserved = true;
$subAbort = static function (string $msg) use ($conn, $childId, $donorUid, &$slotReserved): void {
    if ($slotReserved) {
        drawdream_child_subscription_release_slot($conn, $childId, $donorUid, $msg);
        $slotReserved = false;
    }
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

if ($custId === '') {
    $r = $create_omise_customer_with_card($token);
    if (!$r['ok']) {
        $subAbort($r['msg']);
    }
    $custId = $r['cust'];
    $cardId = $r['card'];
    $upd = $conn->prepare('UPDATE donor SET omise_customer_id = ? WHERE user_id = ?');
    $upd->bind_param('si', $custId, $donorUid);
    $upd->execute();
} else {
    $cres = drawdream_omise_post_form('/customers/' . rawurlencode($custId) . '/cards', [
        'card' => $token,
    ]);
    if (($cres['object'] ?? '') === 'error') {
        if (drawdream_omise_is_not_found_error($cres)) {
            $clr = $conn->prepare('UPDATE donor SET omise_customer_id = NULL WHERE user_id = ?');
            $clr->bind_param('i', $donorUid);
            $clr->execute();
            $r = $create_omise_customer_with_card($token);
            if (!$r['ok']) {
                $subAbort(
                    'รหัสลูกค้า Omise ในฐานข้อมูลไม่ตรงกับบัญชีปัจจุบัน ระบบสร้างลูกค้าใหม่แล้วแต่ยังไม่สำเร็จ: ' . $r['msg']
                );
            }
            $custId = $r['cust'];
            $cardId = $r['card'];
            $upd = $conn->prepare('UPDATE donor SET omise_customer_id = ? WHERE user_id = ?');
            $upd->bind_param('si', $custId, $donorUid);
            $upd->execute();
        } else {
            $m = drawdream_omise_error_message_for_user($cres, 'ผูกบัตรไม่สำเร็จ');
            $subAbort($m);
        }
    } else {
        $cardId = (string)($cres['id'] ?? '');
        if ($cardId === '') {
            $subAbort('Omise ไม่คืน card id');
        }
    }
}

$tz = new DateTimeZone('Asia/Bangkok');
$now = new DateTimeImmutable('now', $tz);
$startDate = $now->format('Y-m-d');
$endDate = $now->modify('+10 years')->format('Y-m-d');
$billDay = drawdream_subscription_safe_bill_day($now);

$childName = (string)($child['child_name'] ?? '');
$desc = 'อุปการะเด็ก ' . $childName . ' — ' . $planSpec['plan_code'] . ' (' . $planSpec['amount_thb'] . ' THB)';

$chargePayload = [
    'customer' => $custId,
    'card' => $cardId,
    'amount' => $planSpec['amount_satang'],
    'currency' => 'thb',
    'description' => $desc,
    'metadata' => [
        'child_id' => (string)$childId,
        'donor_user_id' => (string)$donorUid,
        'plan_code' => $planSpec['plan_code'],
        'app' => 'drawdream_child_subscription',
    ],
];
$sres = drawdream_omise_post_schedule(
    $planSpec['every'],
    $planSpec['period'],
    $startDate,
    $endDate,
    $billDay,
    $chargePayload
);
if (($sres['object'] ?? '') === 'error' && drawdream_omise_is_not_found_error($sres) && $cardId !== '') {
    $chargeNoCard = $chargePayload;
    unset($chargeNoCard['card']);
    $sres = drawdream_omise_post_schedule(
        $planSpec['every'],
        $planSpec['period'],
        $startDate,
        $endDate,
        $billDay,
        $chargeNoCard
    );
}
if (($sres['object'] ?? '') === 'error') {
    // Schedule ไม่พร้อม (พบบ่อยใน Test) → หักรอบแรก + local_cron แทน (ไม่ล้าง customer)
    $metaCharge = [
        'child_id' => (string)$childId,
        'donor_user_id' => (string)$donorUid,
        'plan_code' => $planSpec['plan_code'],
        'app' => 'drawdream_child_subscription',
    ];
    $fcharge = drawdream_omise_create_card_charge(
        $custId,
        $cardId,
        (int)$planSpec['amount_satang'],
        $desc,
        $metaCharge
    );
    if (($fcharge['object'] ?? '') === 'error') {
        $m = drawdream_omise_error_message_for_user(
            $fcharge,
            'หักเงินรอบแรกไม่สำเร็จ (โหมดสำรองเมื่อ Omise ไม่ให้สร้าง Charge Schedule)'
        );
        $subAbort($m);
    }
    $fcharge = child_subscription_finalize_charge_for_test($fcharge);
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
    $ins = $conn->prepare(
        'INSERT INTO donation (
            category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
            omise_charge_id, donate_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $planCode = $planSpec['plan_code'];
    $categoryId = drawdream_get_or_create_child_donate_category_id($conn);
    $recurringType = 'child_subscription_charge';
    $completed = 'completed';
    $ins->bind_param(
        'iiidssss',
        $categoryId,
        $childId,
        $donorUid,
        $firstAmountBaht,
        $completed,
        $transferNowSql,
        $firstChId,
        $recurringType
    );
    if (!$ins->execute()) {
        $subAbort(
            'หักเงินรอบแรกสำเร็จแล้ว แต่บันทึกแผนในระบบไม่สำเร็จ — กรุณาติดต่อผู้ดูแล (รหัส charge: ' . $firstChId . ')'
        );
    }
    $firstDonateId = (int)$conn->insert_id;
    if ($firstDonateId > 0) {
        $updCard = $conn->prepare('UPDATE donor SET omise_card_id = ? WHERE user_id = ?');
        if ($updCard) {
            $updCard->bind_param('si', $cardId, $donorUid);
            $updCard->execute();
        }
        drawdream_child_subscription_history_log(
            $conn,
            $childId,
            $donorUid,
            $firstDonateId,
            $localSchId,
            $firstChId !== '' ? $firstChId : null,
            'subscription_created',
            null,
            'active',
            $planCode,
            $firstAmountBaht,
            'web_create',
            'local_cron_subscription_created',
            [
                'customer_id' => $custId,
                'card_id' => $cardId,
                'next_charge_at' => $nextSql,
            ]
        );
        drawdream_send_e_receipt_notification_by_donate_id($conn, $firstDonateId);
        drawdream_child_sync_sponsorship_status($conn, $childId);
    }

    $slotReserved = false;
    $nextThai = $nextAt->format('d/m/Y') . ' เวลา 08:00 น. (เวลาไทย)';
    child_subscription_redirect(
        'สมัครอุปการะสำเร็จ รอบถัดไป ' . $nextThai,
        true,
        $childId
    );
}

$schId = (string)($sres['id'] ?? '');
if ($schId === '') {
    $subAbort('Omise ไม่คืน schedule id');
}

$seedDonateId = 0;
$planCode = $planSpec['plan_code'];
$nextScheduleSql = drawdream_subscription_next_charge_at($now, $planSpec, $billDay)->format('Y-m-d H:i:s');
drawdream_child_subscription_history_log(
    $conn,
    $childId,
    $donorUid,
    $seedDonateId > 0 ? $seedDonateId : null,
    $schId,
    null,
    'subscription_created',
    null,
    'active',
    $planCode,
    (float)$planSpec['amount_thb'],
    'web_create',
    'omise_schedule_created',
    [
        'customer_id' => $custId,
        'card_id' => $cardId,
        'schedule_id' => $schId,
        'plan_every' => (int)$planSpec['every'],
        'plan_period' => (string)$planSpec['period'],
        'bill_day' => $billDay,
        'next_charge_at' => $nextScheduleSql,
    ]
);
drawdream_child_sync_sponsorship_status($conn, $childId);

$updCard2 = $conn->prepare('UPDATE donor SET omise_card_id = ? WHERE user_id = ?');
if ($updCard2) {
    $updCard2->bind_param('si', $cardId, $donorUid);
    $updCard2->execute();
}

$slotReserved = false;
child_subscription_redirect(
    'สมัครอุปการะสำเร็จ รอบถัดไป ' . (drawdream_subscription_next_charge_at($now, $planSpec, $billDay)->format('d/m/Y') . ' เวลา 08:00 น. (เวลาไทย)'),
    true,
    $childId
);
