<?php
// tools/cron_foundation_outcome_compliance.php — ตรวจงานค้างอัปเดตผลลัพธ์: เตือน 30 วัน / พักบัญชี 37 วัน
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once dirname(__DIR__) . '/payment/config.php';
    $sec = defined('DRAWDREAM_OUTCOME_COMPLIANCE_CRON_SECRET')
        ? (string)DRAWDREAM_OUTCOME_COMPLIANCE_CRON_SECRET
        : '';
    if ($sec === '' || !isset($_GET['secret']) || !hash_equals($sec, (string)$_GET['secret'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/foundation_outcome_compliance.php';

$results = drawdream_foundation_outcome_compliance_run_all($conn);
$counts = [
    'warned' => 0,
    'paused' => 0,
    'unpaused' => 0,
    'ok' => 0,
    'other' => 0,
];
foreach ($results as $r) {
    $action = (string)($r['action'] ?? '');
    if (isset($counts[$action])) {
        $counts[$action]++;
    } else {
        $counts['other']++;
    }
}

echo 'foundation_outcome_compliance ' . date('c') . "\n";
echo 'foundations_checked=' . count($results) . "\n";
echo 'warned=' . $counts['warned'] . ' paused=' . $counts['paused'] . ' unpaused=' . $counts['unpaused'] . "\n";

foreach ($results as $r) {
    if (!in_array($r['action'], ['warned', 'paused', 'unpaused'], true)) {
        continue;
    }
    echo $r['action'] . ' fid=' . (int)$r['foundation_id'] . ' days=' . (int)$r['max_days_overdue'] . ' ' . ($r['foundation_name'] ?? '') . "\n";
}
