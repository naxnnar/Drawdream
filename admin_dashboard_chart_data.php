<?php
// admin_dashboard_chart_data.php — JSON ข้อมูลกราฟยอดบริจาครายวันตามช่วงวันที่ (เฉพาะแอดมิน)
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน dashboard chart data
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$fromDt = DateTime::createFromFormat('Y-m-d', $from);
$toDt = DateTime::createFromFormat('Y-m-d', $to);
if (!$fromDt || !$toDt || $fromDt->format('Y-m-d') !== $from || $toDt->format('Y-m-d') !== $to) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_date']);
    exit;
}

if ($from > $to) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'range_invalid']);
    exit;
}

$days = (int)$fromDt->diff($toDt)->days + 1;
if ($days > 370) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'range_too_large']);
    exit;
}

$sql = "
    SELECT DATE(transfer_datetime) AS donate_date, COALESCE(SUM(amount), 0) AS total
    FROM donation
    WHERE payment_status = 'completed'
      AND DATE(transfer_datetime) BETWEEN ? AND ?
    GROUP BY DATE(transfer_datetime)
    ORDER BY donate_date ASC
";
$st = $conn->prepare($sql);
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}
$st->bind_param('ss', $from, $to);
$st->execute();
$res = $st->get_result();

$map = [];
while ($row = $res->fetch_assoc()) {
    $k = (string)($row['donate_date'] ?? '');
    if ($k !== '') {
        $map[$k] = (float)($row['total'] ?? 0);
    }
}

$labels = [];
$values = [];
$dates = [];

$cur = new DateTime($from);
$end = new DateTime($to);
while ($cur <= $end) {
    $k = $cur->format('Y-m-d');
    $dates[] = $k;
    $labels[] = $cur->format('d/m');
    $values[] = $map[$k] ?? 0.0;
    $cur->modify('+1 day');
}

echo json_encode([
    'ok' => true,
    'labels' => $labels,
    'values' => $values,
    'dates' => $dates,
], JSON_UNESCAPED_UNICODE);

