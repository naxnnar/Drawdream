<?php
// admin_bulk_approve.php — อนุมัติคำขอหลายรายการจากศูนย์แจ้งเตือน

declare(strict_types=1);

include 'db.php';
require_once __DIR__ . '/includes/admin_bulk_approve.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_notifications.php');
    exit();
}

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: login.php');
    exit();
}

drawdream_csrf_require_valid('admin_bulk_approve.php');

$adminUid = (int)$_SESSION['user_id'];
$scope = trim((string)($_POST['bulk_scope'] ?? 'all'));

$groups = [
    'foundation' => array_map('intval', (array)($_POST['foundation_ids'] ?? [])),
    'child' => array_map('intval', (array)($_POST['child_ids'] ?? [])),
    'project' => array_map('intval', (array)($_POST['project_ids'] ?? [])),
    'need' => array_map('intval', (array)($_POST['need_ids'] ?? [])),
];

if ($scope !== 'all') {
    foreach (array_keys($groups) as $key) {
        if ($key !== $scope) {
            $groups[$key] = [];
        }
    }
}

$hasAny = false;
foreach ($groups as $ids) {
    if ($ids !== []) {
        $hasAny = true;
        break;
    }
}

if (!$hasAny) {
    header('Location: admin_notifications.php?err=1&msg=' . urlencode('ยังไม่ได้เลือกรายการ'));
    exit();
}

$result = drawdream_admin_bulk_approve_all($conn, $adminUid, $groups);

$approved = (int)($result['approved'] ?? 0);
$skipped = (int)($result['skipped'] ?? 0);
$messages = $result['messages'] ?? [];

$parts = [];
if ($approved > 0) {
    $parts[] = 'อนุมัติสำเร็จ ' . $approved . ' รายการ';
}
if ($skipped > 0) {
    $parts[] = 'ข้าม ' . $skipped . ' รายการ';
}
if ($parts === []) {
    $parts[] = 'ไม่มีรายการที่อนุมัติได้';
}
if ($messages !== []) {
    $parts[] = implode('; ', array_slice($messages, 0, 3));
    if (count($messages) > 3) {
        $parts[] = '…และอีก ' . (count($messages) - 3) . ' รายการ';
    }
}

$flash = implode(' · ', $parts);
$qs = 'bulk=1&approved=' . $approved . '&skipped=' . $skipped . '&msg=' . urlencode($flash);
if ($approved > 0) {
    $qs .= '&done=1';
} else {
    $qs .= '&err=1';
}

$hash = '#admin-pending-foundations';
if ($scope === 'child') {
    $hash = '#admin-pending-children';
} elseif ($scope === 'project') {
    $hash = '#admin-pending-projects';
} elseif ($scope === 'need') {
    $hash = '#admin-pending-needs';
}

header('Location: admin_notifications.php?' . $qs . $hash);
exit();
