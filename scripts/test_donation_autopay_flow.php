<?php
// scripts/test_donation_autopay_flow.php — ทดสอบ Omise test auto mark_as_paid + finalize (เด็ก / โครงการ / สิ่งของ)
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/payment/config.php';
require_once $root . '/payment/omise_helpers.php';
require_once $root . '/includes/qr_payment_abandon.php';
require_once $root . '/includes/pending_child_donation.php';
require_once $root . '/includes/donate_category_resolve.php';
require_once $root . '/includes/donate_type.php';
require_once $root . '/includes/drawdream_project_payment_finalize.php';
require_once $root . '/includes/drawdream_needlist_payment_finalize.php';
require_once $root . '/includes/payment_transaction_schema.php';

$passed = 0;
$failed = 0;
$baseUrl = getenv('DRAWDREAM_DEV_URL') ?: 'http://127.0.0.1:8080';

function ok(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label\n";
        $failed++;
    }
}

function fail_msg(string $label, string $detail): void
{
    global $failed;
    echo "[FAIL] $label — $detail\n";
    $failed++;
}

/**
 * @param array<string, mixed> $metadata
 * @return array{charge_id:string,charge:array}|null
 */
function test_create_promptpay_charge(int $amountBaht, array $metadata, string $description): ?array
{
    $satang = $amountBaht * 100;
    $srcHttp = drawdream_omise_http_raw('POST', '/sources', json_encode([
        'type' => 'promptpay',
        'amount' => $satang,
        'currency' => 'THB',
    ], JSON_UNESCAPED_UNICODE));
    if (!$srcHttp['ok']) {
        return null;
    }
    $source = json_decode($srcHttp['body'], true);
    if (!is_array($source) || ($source['object'] ?? '') !== 'source' || empty($source['id'])) {
        return null;
    }

    $chgHttp = drawdream_omise_http_raw('POST', '/charges', json_encode([
        'amount' => $satang,
        'currency' => 'THB',
        'source' => $source['id'],
        'description' => $description,
        'metadata' => $metadata,
    ], JSON_UNESCAPED_UNICODE));
    if (!$chgHttp['ok']) {
        return null;
    }
    $charge = json_decode($chgHttp['body'], true);
    if (!is_array($charge) || empty($charge['id'])) {
        return null;
    }

    return ['charge_id' => (string)$charge['id'], 'charge' => $charge];
}

echo "=== DrawDream donation autopay flow tests ===\n";
echo "Dev URL: $baseUrl\n\n";

ok(drawdream_omise_is_test_mode(), 'Omise keys are test mode');
ok(drawdream_omise_test_auto_mark_paid_enabled(), 'OMISE_TEST_AUTO_MARK_PAID is enabled');

$devOk = false;
if (function_exists('curl_init')) {
    $ch = curl_init($baseUrl . '/homepage.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $home = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $devOk = ($home !== false && $httpCode >= 200 && $httpCode < 400 && strlen((string)$home) > 200);
}
if ($devOk) {
    ok(true, "Dev server responds at $baseUrl");
} else {
    fail_msg("Dev server at $baseUrl", 'homepage not reachable — start .\\run_dev_server.ps1');
}

if (!extension_loaded('mysqli')) {
    echo "[SKIP] mysqli not loaded\n";
    exit($failed > 0 ? 1 : 0);
}

require_once $root . '/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    fail_msg('DB', 'no connection');
    exit(1);
}

$donorRow = $conn->query(
    "SELECT u.user_id, u.email FROM `user` u WHERE u.role = 'donor' ORDER BY u.user_id DESC LIMIT 1"
)?->fetch_assoc();
if (!$donorRow) {
    fail_msg('donor', 'no donor user in DB');
    exit(1);
}
$donorId = (int)$donorRow['user_id'];
echo "Using donor user_id=$donorId ({$donorRow['email']})\n\n";

$testAmount = 20.0;

// --- Child ---
$child = $conn->query(
    "SELECT child_id, child_name FROM foundation_children
     WHERE approve_profile IN ('อนุมัติ', 'กำลังดำเนินการ')
     ORDER BY child_id DESC LIMIT 1"
)?->fetch_assoc();
if ($child) {
    $childId = (int)$child['child_id'];
    drawdream_abandon_all_pending_qr_for_donor($conn, $donorId);
    $created = test_create_promptpay_charge((int)$testAmount, [
        'child_id' => $childId,
        'donor_id' => $donorId,
        'type' => 'child',
    ], 'TEST autopay child');
    if ($created) {
        $chargeId = $created['charge_id'];
        ok(strtolower((string)($created['charge']['status'] ?? '')) === 'pending', "child charge $chargeId starts pending");
        $donateId = drawdream_insert_pending_child_donation($conn, $childId, $donorId, $testAmount, $chargeId);
        ok($donateId > 0, "child pending donation donate_id=$donateId");
        $after = drawdream_omise_fetch_charge($chargeId, true);
        $st = strtolower((string)($after['status'] ?? ''));
        $paid = $after['paid'] ?? false;
        ok($st === 'successful' || $paid === true, 'child auto mark_as_paid → successful');
        if ($after && ($paid === true || $st === 'successful')) {
            $fin = drawdream_finalize_child_donation($conn, $childId, $donateId, $chargeId, $testAmount, $donorId);
            ok($fin, 'child drawdream_finalize_child_donation');
            $chk = $conn->prepare('SELECT payment_status FROM donation WHERE donate_id = ?');
            $chk->bind_param('i', $donateId);
            $chk->execute();
            $ps = (string)($chk->get_result()->fetch_assoc()['payment_status'] ?? '');
            ok($ps === 'completed', "child donation status=$ps");
        }
    } else {
        fail_msg('child Omise', 'could not create charge (network/API)');
    }
} else {
    echo "[SKIP] no approved child\n";
}

// --- Project ---
$project = $conn->query(
    "SELECT project_id, project_name, goal_amount, current_donate FROM foundation_project
     WHERE project_status = 'approved'
       AND (goal_amount IS NULL OR goal_amount <= 0 OR current_donate < goal_amount - 20)
     ORDER BY project_id DESC LIMIT 1"
)?->fetch_assoc();
if ($project) {
    $projectId = (int)$project['project_id'];
    drawdream_abandon_all_pending_qr_for_donor($conn, $donorId);
    $catId = drawdream_get_or_create_project_donate_category_id($conn);
    $created = test_create_promptpay_charge((int)$testAmount, [
        'project_id' => $projectId,
        'donor_id' => $donorId,
        'type' => 'project',
    ], 'TEST autopay project');
    if ($created && $catId > 0) {
        $chargeId = $created['charge_id'];
        drawdream_payment_transaction_ensure_schema($conn);
        $pending = 'pending';
        $dtProj = DRAWDREAM_DONATE_TYPE_PROJECT;
        $insP = $conn->prepare(
            'INSERT INTO donation (category_id, target_id, donor_id, amount, payment_status, transfer_datetime, omise_charge_id, donate_type)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
        );
        $insP->bind_param('iiidsss', $catId, $projectId, $donorId, $testAmount, $pending, $chargeId, $dtProj);
        $donateId = ($insP && $insP->execute()) ? (int)$conn->insert_id : 0;
        ok($donateId > 0, "project pending donate_id=$donateId");
        $after = drawdream_omise_fetch_charge($chargeId, true);
        ok(($after['paid'] ?? false) === true || ($after['status'] ?? '') === 'successful', 'project auto mark_as_paid');
        $codeFin = drawdream_finalize_project_donation($conn, $projectId, $donateId, $chargeId, $testAmount, $donorId);
        ok($codeFin === DRAWDREAM_PROJECT_FINALIZE_OK, "project finalize code=$codeFin");
        $chk = $conn->prepare('SELECT payment_status FROM donation WHERE donate_id = ?');
        $chk->bind_param('i', $donateId);
        $chk->execute();
        $ps = (string)($chk->get_result()->fetch_assoc()['payment_status'] ?? '');
        ok($ps === 'completed', "project donation status=$ps");
        echo "       charge: $chargeId | check: $baseUrl/payment/check_project_payment.php?charge_id="
            . rawurlencode($chargeId) . "&project_id=$projectId\n";
    } else {
        fail_msg('project', 'charge or category failed');
    }
} else {
    echo "[SKIP] no open project\n";
}

// --- Needlist (foundation) ---
$needOpen = drawdream_needlist_sql_open_for_donation();
$fidRow = $conn->query(
    "SELECT fp.foundation_id FROM foundation_profile fp
     JOIN foundation_needlist n ON n.foundation_id = fp.foundation_id AND $needOpen
     GROUP BY fp.foundation_id
     HAVING COALESCE(SUM(n.total_price),0) > COALESCE(SUM(n.current_donate),0) + 20
     LIMIT 1"
)?->fetch_assoc();
if ($fidRow) {
    $fid = (int)$fidRow['foundation_id'];
    drawdream_abandon_all_pending_qr_for_donor($conn, $donorId);
    $created = test_create_promptpay_charge((int)$testAmount, [
        'foundation_id' => $fid,
        'donor_id' => $donorId,
        'type' => 'foundation',
    ], 'TEST autopay needlist');
    if ($created) {
        $chargeId = $created['charge_id'];
        $donateId = drawdream_insert_pending_needlist_donation($conn, $fid, $donorId, $testAmount, $chargeId);
        ok($donateId > 0, "needlist pending donate_id=$donateId");
        $after = drawdream_omise_fetch_charge($chargeId, true);
        ok(($after['paid'] ?? false) === true || ($after['status'] ?? '') === 'successful', 'needlist auto mark_as_paid');
        $codeFin = drawdream_finalize_needlist_donation($conn, $fid, $donateId, $chargeId, $testAmount, $donorId);
        ok($codeFin === DRAWDREAM_PROJECT_FINALIZE_OK, "needlist finalize code=$codeFin");
        $chk = $conn->prepare('SELECT payment_status FROM donation WHERE donate_id = ?');
        $chk->bind_param('i', $donateId);
        $chk->execute();
        $ps = (string)($chk->get_result()->fetch_assoc()['payment_status'] ?? '');
        ok($ps === 'completed', "needlist donation status=$ps");
        echo "       charge: $chargeId | check: $baseUrl/payment/check_needlist_payment.php?charge_id="
            . rawurlencode($chargeId) . "&fid=$fid\n";
    } else {
        fail_msg('needlist Omise', 'could not create charge');
    }
} else {
    echo "[SKIP] no foundation with needlist headroom\n";
}

echo "\n=== Summary: $passed passed, $failed failed ===\n";
echo "\nManual UI test (ล็อกอินผู้บริจาคแล้ว):\n";
echo "  1) บริจาค → หน้า QR → กด «ยืนยันการชำระ»\n";
echo "  2) ควรเห็น «ชำระเงินสำเร็จ» โดยไม่เข้า Omise Dashboard\n";
if ($child ?? false) {
    echo "  เด็ก: $baseUrl/children_donate.php?id=" . (int)$child['child_id'] . "\n";
}
if ($project ?? false) {
    echo "  โครงการ: $baseUrl/payment/payment_project.php?project_id=" . (int)$project['project_id'] . "\n";
}
if ($fidRow ?? false) {
    echo "  สิ่งของ: $baseUrl/payment/foundation_donate.php?fid=" . (int)$fidRow['foundation_id'] . "\n";
}

exit($failed > 0 ? 1 : 0);
