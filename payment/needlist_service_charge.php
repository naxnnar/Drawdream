<?php
// payment/needlist_service_charge.php — สร้าง QR ชำระค่าบริการระบบรายการสิ่งของ (มูลนิธิ)
declare(strict_types=1);

include __DIR__ . '/../db.php';
include __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/omise_helpers.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: ../foundation.php');
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stFn = $conn->prepare('SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$stFn) {
    header('Location: ../foundation.php');
    exit();
}
$stFn->bind_param('i', $uid);
$stFn->execute();
$foundationId = (int)($stFn->get_result()->fetch_assoc()['foundation_id'] ?? 0);
if ($foundationId <= 0) {
    header('Location: ../update_profile.php');
    exit();
}

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    header('Location: ../foundation.php#my-needlist-section');
    exit();
}

$st = $conn->prepare(
    'SELECT item_id, item_name, foundation_id, service_charge, service_charge_paid_at,
            COALESCE(current_donate, 0) AS current_donate, COALESCE(total_price, 0) AS total_price
     FROM foundation_needlist
     WHERE item_id = ? AND foundation_id = ?
     LIMIT 1'
);
if (!$st) {
    header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_err=1');
    exit();
}
$st->bind_param('ii', $itemId, $foundationId);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) {
    header('Location: ../foundation.php#my-needlist-section');
    exit();
}

if (!empty($row['service_charge_paid_at'])) {
    header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_paid=1');
    exit();
}

$serviceCharge = (float)($row['service_charge'] ?? 0);
$raised = (float)($row['current_donate'] ?? 0);
$goal = (float)($row['total_price'] ?? 0);
if (!drawdream_needlist_item_goal_met($raised, $goal)) {
    header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_err=not_ready');
    exit();
}
if ($serviceCharge <= 0 && $goal > 0 && $raised >= $goal) {
    drawdream_needlist_sync_service_charge_for_item($conn, $itemId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: $row;
    $serviceCharge = (float)($row['service_charge'] ?? 0);
}
if ($serviceCharge <= 0) {
    header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_err=no_amount');
    exit();
}

$amount = (int)round($serviceCharge);
if ($amount < 20) {
    header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_err=min');
    exit();
}

$itemName = trim((string)($row['item_name'] ?? ''));
$desc = 'ค่าบริการระบบรายการสิ่งของ';
if ($itemName !== '') {
    $desc .= ': ' . $itemName;
}

drawdream_clear_pending_payment_session();
drawdream_clear_pending_service_charge_session();

$amount_satang = $amount * 100;
$source_response = omise_request('POST', '/sources', [
    'type' => 'promptpay',
    'amount' => $amount_satang,
    'currency' => 'THB',
]);
if (isset($source_response['error'])) {
    header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_err=omise');
    exit();
}
if (!isset($source_response['object']) || $source_response['object'] !== 'source') {
    header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_err=omise');
    exit();
}

$charge_response = omise_request('POST', '/charges', [
    'amount' => $amount_satang,
    'currency' => 'THB',
    'source' => $source_response['id'],
    'description' => $desc,
    'metadata' => [
        'type' => 'need_service_charge',
        'item_id' => $itemId,
        'foundation_id' => $foundationId,
        'donor_id' => $uid,
    ],
]);
if (isset($charge_response['error']) || !isset($charge_response['id'])) {
    header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_err=omise');
    exit();
}

$charge_id = (string)$charge_response['id'];
$qr_image = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';

$_SESSION['pending_sc_item_id'] = $itemId;
$_SESSION['pending_sc_charge_id'] = $charge_id;
$_SESSION['pending_sc_amount'] = $amount;
$_SESSION['pending_sc_qr_image'] = $qr_image;
$_SESSION['pending_sc_foundation_id'] = $foundationId;
$_SESSION['pending_sc_item_name'] = $itemName;

if (drawdream_foundation_service_charge_skip_qr_after_pay($charge_id)) {
    drawdream_foundation_service_charge_auto_mark_on_pay($charge_id);
    header(
        'Location: check_needlist_service_charge_payment.php?item_id=' . $itemId
        . '&charge_id=' . rawurlencode($charge_id)
    );
    exit();
}

header(
    'Location: needlist_service_charge_qr.php?item_id=' . $itemId
    . '&charge_id=' . rawurlencode($charge_id)
);
exit();

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function omise_request(string $method, string $path, array $data = []): array
{
    $ch = curl_init(OMISE_API_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    if ($response === false || $response === '') {
        if (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0) {
            return _omise_local_mock($path, $data);
        }

        return ['error' => 'curl_error', 'message' => $curl_error];
    }
    $decoded = json_decode($response, true);

    return $decoded ?? ['error' => 'json_error', 'message' => 'Invalid JSON'];
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function _omise_local_mock(string $path, array $data): array
{
    if (strpos($path, '/sources') !== false) {
        return ['object' => 'source', 'id' => 'src_mock_' . bin2hex(random_bytes(6)), 'type' => 'promptpay'];
    }
    if (strpos($path, '/charges') !== false) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="420" viewBox="0 0 320 420">'
            . '<rect width="320" height="420" fill="#ffffff"/>'
            . '<text x="160" y="210" font-size="18" text-anchor="middle" font-family="Arial,sans-serif" fill="#1f4f7c">MOCK QR</text></svg>';

        return [
            'object' => 'charge',
            'id' => 'chrg_mock_' . bin2hex(random_bytes(8)),
            'status' => 'pending',
            'paid' => false,
            'amount' => $data['amount'] ?? 0,
            'currency' => 'THB',
            'source' => [
                'type' => 'promptpay',
                'scannable_code' => [
                    'image' => ['download_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg)],
                ],
            ],
        ];
    }

    return ['error' => 'mock_unknown', 'message' => 'Mock: unknown API path'];
}
