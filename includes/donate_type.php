<?php
// includes/donate_type.php — รหัสประเภทบริจาค (donation.donate_type) + ป้ายกำกับภาษาไทยสำหรับแสดงผล
// สรุปสั้น: รวม constant ประเภทการบริจาคและชื่อแสดงผลที่ใช้ทั้งระบบ
declare(strict_types=1);

/** อุปการะเด็กแบบหักรายรอบ (แถวหลักของแผน / subscription row) */
const DRAWDREAM_DONATE_TYPE_CHILD_SUBSCRIPTION = 'child_subscription';

/** รายการหักแต่ละรอบจากแผนอุปการะ (แยกจากแถวแผนหลัก) */
const DRAWDREAM_DONATE_TYPE_CHILD_SUBSCRIPTION_CHARGE = 'child_subscription_charge';

/** บริจาคให้เด็กครั้งเดียว (เช่น PromptPay / QR รายวันครั้งเดียว) */
const DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME = 'child_one_time';

/** บริจาคโครงการ (ครั้งเดียว) */
const DRAWDREAM_DONATE_TYPE_PROJECT = 'project';

/** บริจาคสิ่งของมูลนิธิ (ครั้งเดียว) */
const DRAWDREAM_DONATE_TYPE_NEED_ITEM = 'need_item';

/** ค่า donation.recurring_plan_code — บริจาคครั้งเดียว (โครงการ / สิ่งของ / เด็กแบบยืนยันโอน) */
const DRAWDREAM_DONATION_RECURRING_PLAN_ONE_TIME = 'one_time';

/** บริจาคเด็กแบบรายวัน (PromptPay QR ตามจำนวน) — ไม่ใช่แผนอุปการะบัตร */
const DRAWDREAM_DONATION_RECURRING_PLAN_DAILY = 'daily';

/**
 * ป้ายกำกับภาษาไทยสำหรับแสดงใน UI / รายงาน (ค่าในฐานข้อมูลยังเป็นรหัสภาษาอังกฤษเพื่อให้ logic ทำงานถูกต้อง)
 */
function drawdream_donate_type_label_thai(?string $code): string
{
    $k = strtolower(trim((string) $code));

    return match ($k) {
        'child_subscription' => 'อุปการะเด็ก (รายรอบ)',
        'child_subscription_charge' => 'อุปการะเด็ก (หักรายรอบ)',
        'child_one_time' => 'บริจาคเด็กครั้งเดียว',
        'project' => 'บริจาคโครงการ',
        'need_item' => 'บริจาคสิ่งของ',
        '' => '-',
        default => $k,
    };
}
