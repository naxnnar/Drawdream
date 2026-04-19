<?php
// includes/payment_transaction_schema.php — คอลัมน์ donation ตามสคีมาปัจจุบัน (ไม่เพิ่มคอลัมน์ที่ลบออกจากตารางแล้ว)
declare(strict_types=1);

require_once __DIR__ . '/donate_type.php';

/**
 * ย้าย recurring_type → donate_type (หรือเพิ่ม donate_type)
 */
function drawdream_donation_migrate_recurring_type_to_donate_type(mysqli $conn): void
{
    $r = @$conn->query('SHOW COLUMNS FROM donation');
    if (!$r) {
        return;
    }
    $fields = [];
    while ($row = $r->fetch_assoc()) {
        $fields[$row['Field']] = true;
    }
    if (!isset($fields['donate_type']) && isset($fields['recurring_type'])) {
        @$conn->query('ALTER TABLE donation CHANGE COLUMN `recurring_type` `donate_type` VARCHAR(40) NULL DEFAULT NULL');
    } elseif (!isset($fields['donate_type'])) {
        @$conn->query('ALTER TABLE donation ADD COLUMN `donate_type` VARCHAR(40) NULL DEFAULT NULL');
    } elseif (isset($fields['recurring_type'])) {
        @$conn->query(
            "UPDATE donation SET donate_type = COALESCE(NULLIF(TRIM(donate_type), ''), recurring_type) WHERE recurring_type IS NOT NULL AND (donate_type IS NULL OR donate_type = '')"
        );
    }
}

/**
 * เติม donate_type ให้แถวเก่าที่ยังว่าง
 */
function drawdream_donation_backfill_donate_type(mysqli $conn): void
{
    require_once __DIR__ . '/donate_category_resolve.php';

    $childCat = drawdream_get_or_create_child_donate_category_id($conn);
    $projCat = drawdream_get_or_create_project_donate_category_id($conn);
    $needCat = drawdream_get_or_create_needitem_donate_category_id($conn);
    if ($childCat <= 0 || $projCat <= 0 || $needCat <= 0) {
        return;
    }

    $chg = DRAWDREAM_DONATE_TYPE_CHILD_SUBSCRIPTION_CHARGE;
    $charged = 'charged';
    $st = $conn->prepare('UPDATE donation SET donate_type = ? WHERE donate_type IS NULL AND recurring_status = ? AND category_id = ?');
    if ($st) {
        $st->bind_param('ssi', $chg, $charged, $childCat);
        $st->execute();
    }

    $sub = DRAWDREAM_DONATE_TYPE_CHILD_SUBSCRIPTION;
    $st2 = $conn->prepare(
        "UPDATE donation SET donate_type = ?
         WHERE donate_type IS NULL AND category_id = ?
           AND (
             LOWER(TRIM(COALESCE(payment_status, ''))) = 'subscription'
             OR (
               recurring_status IN ('active', 'paused', 'cancelled')
               AND (recurring_schedule_id IS NOT NULL AND TRIM(recurring_schedule_id) <> '')
             )
           )"
    );
    if ($st2) {
        $st2->bind_param('si', $sub, $childCat);
        $st2->execute();
    }

    $one = DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME;
    $st3 = $conn->prepare('UPDATE donation SET donate_type = ? WHERE donate_type IS NULL AND category_id = ?');
    if ($st3) {
        $st3->bind_param('si', $one, $childCat);
        $st3->execute();
    }

    $proj = DRAWDREAM_DONATE_TYPE_PROJECT;
    $st4 = $conn->prepare('UPDATE donation SET donate_type = ? WHERE donate_type IS NULL AND category_id = ?');
    if ($st4) {
        $st4->bind_param('si', $proj, $projCat);
        $st4->execute();
    }

    $need = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
    $st5 = $conn->prepare('UPDATE donation SET donate_type = ? WHERE donate_type IS NULL AND category_id = ?');
    if ($st5) {
        $st5->bind_param('si', $need, $needCat);
        $st5->execute();
    }
}

/**
 * เฉพาะคอลัมน์ที่ยังใช้ในตาราง donation (ไม่สร้าง tax_id / pending_* / recurring_every_n / …)
 */
function drawdream_payment_transaction_ensure_schema(mysqli $conn): void
{
    drawdream_donation_migrate_recurring_type_to_donate_type($conn);

    $cols = [
        'omise_charge_id' => 'VARCHAR(80) NULL DEFAULT NULL',
        'transaction_status' => "VARCHAR(20) NOT NULL DEFAULT 'completed'",
        'donate_type' => 'VARCHAR(40) NULL DEFAULT NULL',
        'recurring_status' => 'VARCHAR(20) NULL DEFAULT NULL',
        'recurring_plan_code' => 'VARCHAR(24) NULL DEFAULT NULL',
        'recurring_next_charge_at' => 'DATETIME NULL DEFAULT NULL',
        'recurring_schedule_id' => 'VARCHAR(64) NULL DEFAULT NULL',
    ];
    foreach ($cols as $name => $def) {
        $c = @$conn->query("SHOW COLUMNS FROM donation LIKE '" . $conn->real_escape_string($name) . "'");
        if ($c && $c->num_rows === 0) {
            @$conn->query("ALTER TABLE donation ADD COLUMN `{$name}` {$def}");
        }
    }
    $indexDefs = [
        'idx_donation_omise_charge' => '(omise_charge_id)',
        'idx_donation_pending_charge' => '(transaction_status, omise_charge_id)',
        'idx_donation_recurring' => '(donate_type, recurring_status, target_id, donor_id)',
    ];
    foreach ($indexDefs as $indexName => $expr) {
        $idxChk = @$conn->query(
            "SHOW INDEX FROM donation WHERE Key_name = '" . $conn->real_escape_string($indexName) . "'"
        );
        $hasIndex = $idxChk && $idxChk->num_rows > 0;
        if (!$hasIndex) {
            @$conn->query("CREATE INDEX {$indexName} ON donation {$expr}");
        }
    }

    drawdream_donation_backfill_donate_type($conn);
    drawdream_donation_backfill_recurring_plan_code($conn);
}

/**
 * เติม recurring_plan_code ให้แถวเก่าที่ยังว่าง (ไม่ทับค่าที่มีอยู่แล้ว เช่น monthly/yearly)
 */
function drawdream_donation_backfill_recurring_plan_code(mysqli $conn): void
{
    $one = DRAWDREAM_DONATION_RECURRING_PLAN_ONE_TIME;
    $daily = DRAWDREAM_DONATION_RECURRING_PLAN_DAILY;
    $proj = DRAWDREAM_DONATE_TYPE_PROJECT;
    $need = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
    $childOne = DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME;

    $st = $conn->prepare(
        "UPDATE donation SET recurring_plan_code = ?
         WHERE (recurring_plan_code IS NULL OR TRIM(recurring_plan_code) = '')
           AND donate_type = ?"
    );
    if ($st) {
        $st->bind_param('ss', $one, $proj);
        $st->execute();
    }
    $st2 = $conn->prepare(
        "UPDATE donation SET recurring_plan_code = ?
         WHERE (recurring_plan_code IS NULL OR TRIM(recurring_plan_code) = '')
           AND donate_type = ?"
    );
    if ($st2) {
        $st2->bind_param('ss', $one, $need);
        $st2->execute();
    }
    $st3 = $conn->prepare(
        "UPDATE donation SET recurring_plan_code = ?
         WHERE (recurring_plan_code IS NULL OR TRIM(recurring_plan_code) = '')
           AND donate_type = ?
           AND omise_charge_id IS NOT NULL AND TRIM(omise_charge_id) <> ''"
    );
    if ($st3) {
        $st3->bind_param('ss', $daily, $childOne);
        $st3->execute();
    }
    $st4 = $conn->prepare(
        "UPDATE donation SET recurring_plan_code = ?
         WHERE (recurring_plan_code IS NULL OR TRIM(recurring_plan_code) = '')
           AND donate_type = ?
           AND (omise_charge_id IS NULL OR TRIM(omise_charge_id) = '')"
    );
    if ($st4) {
        $st4->bind_param('ss', $one, $childOne);
        $st4->execute();
    }
}
