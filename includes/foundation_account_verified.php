<?php
// includes/foundation_account_verified.php — มูลนิธิต้องผ่าน account_verified ก่อนใช้งานฟีเจอร์จัดการ
// สรุปสั้น: helper เช็กและซิงก์สถานะอนุมัติบัญชีมูลนิธิจากฐานข้อมูลเข้า session
declare(strict_types=1);

/**
 * อ่านสถานะจาก DB แล้วอัปเดตเซสชัน (กรณีแอดมินอนุมัติขณะยังล็อกอินอยู่)
 *
 * @return 0 หรือ 1
 */
function drawdream_foundation_sync_account_verified_from_db(mysqli $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $st = $conn->prepare('SELECT account_verified FROM foundation_profile WHERE user_id = ? LIMIT 1');
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $v = (int)($row['account_verified'] ?? 0);
    $_SESSION['account_verified'] = $v;

    return $v;
}

/** ผู้ใช้ที่ไม่ใช่มูลนิธิถือว่า "ผ่าน" สำหรับการกั้นฟีเจอร์มูลนิธิ */
function drawdream_foundation_account_is_verified(mysqli $conn): bool
{
    if (($_SESSION['role'] ?? '') !== 'foundation') {
        return true;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);

    return drawdream_foundation_sync_account_verified_from_db($conn, $uid) === 1;
}

function drawdream_foundation_require_account_verified(mysqli $conn): void
{
    if (($_SESSION['role'] ?? '') !== 'foundation') {
        return;
    }
    if (drawdream_foundation_account_is_verified($conn)) {
        return;
    }
    header('Location: homepage.php?' . http_build_query(['msg' => 'บัญชีมูลนิธิของคุณยังรอการตรวจสอบจากผู้ดูแลระบบ จึงยังไม่สามารถใช้ฟีเจอร์นี้ได้']));
    exit();
}
