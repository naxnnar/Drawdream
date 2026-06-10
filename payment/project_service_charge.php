<?php
// payment/project_service_charge.php — สร้าง QR ชำระค่าบริการระบบโครงการ (มูลนิธิ)
declare(strict_types=1);

include __DIR__ . '/../db.php';
include __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/drawdream_project_service_charge.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/omise_helpers.php';
drawdream_ensure_foundation_project_service_charge_columns($conn);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: ../project.php?view=foundation');
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stFn = $conn->prepare('SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$stFn) {
    header('Location: ../project.php?view=foundation');
    exit();
}
$stFn->bind_param('i', $uid);
$stFn->execute();
$fpRow = $stFn->get_result()->fetch_assoc();
$foundationId = (int)($fpRow['foundation_id'] ?? 0);
$foundationName = trim((string)($fpRow['foundation_name'] ?? ''));
if ($foundationId <= 0 || $foundationName === '') {
    header('Location: ../update_profile.php');
    exit();
}

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) {
    header('Location: ../project.php?view=foundation');
    exit();
}

$st = $conn->prepare(
    'SELECT p.project_id, p.project_name, p.foundation_id, p.service_charge, p.service_charge_paid_at,
            COALESCE(p.current_donate, 0) AS current_donate, COALESCE(p.goal_amount, 0) AS goal_amount,
            p.project_status
     FROM foundation_project p
     WHERE p.project_id = ? AND p.foundation_name = ?
     LIMIT 1'
);
if (!$st) {
    header('Location: ../foundation_project_view.php?id=' . $projectId . '&sc_err=1');
    exit();
}
$st->bind_param('is', $projectId, $foundationName);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) {
    header('Location: ../project.php?view=foundation');
    exit();
}

if (!empty($row['service_charge_paid_at'])) {
    header('Location: ../foundation_project_view.php?id=' . $projectId . '&sc_paid=1');
    exit();
}

$serviceCharge = (float)($row['service_charge'] ?? 0);
$raised = (float)($row['current_donate'] ?? 0);
$goal = (float)($row['goal_amount'] ?? 0);
if (!drawdream_project_goal_met($raised, $goal)) {
    header('Location: ../foundation_project_view.php?id=' . $projectId . '&sc_err=not_ready');
    exit();
}
if ($serviceCharge <= 0) {
    drawdream_project_sync_service_charge_for_project($conn, $projectId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: $row;
    $serviceCharge = (float)($row['service_charge'] ?? 0);
}
if ($serviceCharge <= 0) {
    header('Location: ../foundation_project_view.php?id=' . $projectId . '&sc_err=no_amount');
    exit();
}

$amount = (int)round($serviceCharge);
if ($amount < 20) {
    header('Location: ../foundation_project_view.php?id=' . $projectId . '&sc_err=min');
    exit();
}

$projectName = trim((string)($row['project_name'] ?? ''));
$desc = 'ค่าบริการระบบโครงการ';
if ($projectName !== '') {
    $desc .= ': ' . $projectName;
}

drawdream_clear_pending_payment_session();
drawdream_clear_pending_service_charge_session();
drawdream_clear_pending_project_service_charge_session();

$amount_satang = $amount * 100;
$source_response = omise_request('POST', '/sources', [
    'type' => 'promptpay',
    'amount' => $amount_satang,
    'currency' => 'THB',
]);
if (isset($source_response['error']) || !isset($source_response['object']) || $source_response['object'] !== 'source') {
    header('Location: ../foundation_project_view.php?id=' . $projectId . '&sc_err=omise');
    exit();
}

$charge_response = omise_request('POST', '/charges', [
    'amount' => $amount_satang,
    'currency' => 'THB',
    'source' => $source_response['id'],
    'description' => $desc,
    'metadata' => [
        'type' => 'project_service_charge',
        'project_id' => $projectId,
        'foundation_id' => $foundationId,
        'donor_id' => $uid,
    ],
]);
if (isset($charge_response['error']) || !isset($charge_response['id'])) {
    header('Location: ../foundation_project_view.php?id=' . $projectId . '&sc_err=omise');
    exit();
}

$charge_id = (string)$charge_response['id'];
$qr_image = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';

$_SESSION['pending_psc_project_id'] = $projectId;
$_SESSION['pending_psc_charge_id'] = $charge_id;
$_SESSION['pending_psc_amount'] = $amount;
$_SESSION['pending_psc_qr_image'] = $qr_image;
$_SESSION['pending_psc_project_name'] = $projectName;

if (drawdream_foundation_service_charge_skip_qr_after_pay($charge_id)) {
    drawdream_foundation_service_charge_auto_mark_on_pay($charge_id);
    header(
        'Location: check_project_service_charge_payment.php?project_id=' . $projectId
        . '&charge_id=' . rawurlencode($charge_id)
    );
    exit();
}

header(
    'Location: project_service_charge_qr.php?project_id=' . $projectId
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
