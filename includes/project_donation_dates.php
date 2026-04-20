<?php

// includes/project_donation_dates.php — วันที่และข้อมูลบริจาคต่อโครงการ
// สรุปสั้น: helper กลางสำหรับคำนวณวันเริ่ม/ช่วงรับบริจาคของโครงการ
/**
 * helper เรื่องวันที่เริ่มรับบริจาคของโครงการ
 * ใช้เป็นจุดกลางเพื่อให้หลายหน้า (project/payment/admin) ตีความวันตรงกัน
 */
/**
 * วันเริ่มต้นสำหรับแสดงช่วงระดมทุนใน UI (อิงวันที่เสนอ/เริ่มโครงการในระบบ)
 */
function drawdream_project_effective_donation_start(array $p): ?string
{
    $st = trim((string)($p['start_date'] ?? ''));
    if ($st !== '') {
        try {
            // ตัดเวลาให้เหลือ Y-m-d เพื่อเทียบเงื่อนไขได้เสถียรในทุก timezone
            return (new DateTimeImmutable(substr($st, 0, 10), new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}
