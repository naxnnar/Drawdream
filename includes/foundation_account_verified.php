<?php
// includes/foundation_account_verified.php — มูลนิธิต้องผ่าน account_verified ก่อนใช้งานฟีเจอร์จัดการ
// สรุปสั้น: helper เช็กและซิงก์สถานะอนุมัติบัญชีมูลนิธิจากฐานข้อมูลเข้า session
declare(strict_types=1);

/** account_verified = 3 — พักชั่วคราวเพราะไม่อัปเดตผลลัพธ์ */
const DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED = 3;

/**
 * อ่านสถานะจาก DB แล้วอัปเดตเซสชัน (กรณีแอดมินอนุมัติขณะยังล็อกอินอยู่)
 *
 * @return 0=รออนุมัติ, 1=ใช้งาน, 2=ไม่อนุมัติ, 3=พักชั่วคราว (ไม่อัปเดตผลลัพธ์)
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

/** บัญชีอนุมัติแล้วและไม่ถูกพัก (แสดงสาธารณะ / รับบริจาคใหม่ได้) */
function drawdream_foundation_account_is_active(mysqli $conn): bool
{
    if (($_SESSION['role'] ?? '') !== 'foundation') {
        return true;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);

    return drawdream_foundation_sync_account_verified_from_db($conn, $uid) === 1;
}

/** บัญชีพักชั่วคราวเพราะไม่อัปเดตผลลัพธ์ */
function drawdream_foundation_account_is_paused(mysqli $conn): bool
{
    if (($_SESSION['role'] ?? '') !== 'foundation') {
        return false;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);

    return drawdream_foundation_sync_account_verified_from_db($conn, $uid) === DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED;
}

/** ผู้ใช้ที่ไม่ใช่มูลนิธิถือว่า "ผ่าน" สำหรับการกั้นฟีเจอร์มูลนิธิ */
function drawdream_foundation_account_is_verified(mysqli $conn): bool
{
    return drawdream_foundation_account_is_active($conn);
}

/** เข้าแดชบอร์ด/อัปเดตผลลัพธ์ได้ (อนุมัติแล้ว หรือพักชั่วคราว) */
function drawdream_foundation_account_can_manage(mysqli $conn): bool
{
    if (($_SESSION['role'] ?? '') !== 'foundation') {
        return true;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $v = drawdream_foundation_sync_account_verified_from_db($conn, $uid);

    return $v === 1 || $v === DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED;
}

function drawdream_foundation_require_account_verified(mysqli $conn): void
{
    if (($_SESSION['role'] ?? '') !== 'foundation') {
        return;
    }
    if (drawdream_foundation_account_can_manage($conn)) {
        return;
    }
    header('Location: foundation.php?' . http_build_query([
        'msg' => 'บัญชีมูลนิธิของคุณยังรอการตรวจสอบจากผู้ดูแลระบบ จึงยังไม่สามารถใช้ฟีเจอร์นี้ได้',
    ], '', '&', PHP_QUERY_RFC3986));
    exit();
}

/** เพิ่มเด็ก/โครงการ/สิ่งของใหม่ — ต้องเป็นบัญชีที่ใช้งานได้ (ไม่ถูกพัก) */
function drawdream_foundation_require_active_account(mysqli $conn): void
{
    if (($_SESSION['role'] ?? '') !== 'foundation') {
        return;
    }
    if (drawdream_foundation_account_is_active($conn)) {
        return;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $v = drawdream_foundation_sync_account_verified_from_db($conn, $uid);
    $msg = $v === DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED
        ? 'บัญชีถูกพักชั่วคราวเนื่องจากยังไม่อัปเดตผลลัพธ์ (ข้อความจากเด็ก โครงการ สิ่งของ) ให้ครบ — กรุณาอัปเดตงานค้างก่อน จึงจะเพิ่มรายการใหม่ได้'
        : 'บัญชีมูลนิธิของคุณยังรอการตรวจสอบจากผู้ดูแลระบบ จึงยังไม่สามารถใช้ฟีเจอร์นี้ได้';
    header('Location: foundation.php?' . http_build_query(['msg' => $msg], '', '&', PHP_QUERY_RFC3986));
    exit();
}
