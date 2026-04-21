<?php

// includes/needlist_donate_window.php — รอบรับบริจาครายการสิ่งของ (1 เดือนนับจากอนุมัติ)
// สรุปสั้น: คำนวณวันสิ้นสุดรับบริจาคของ needlist แบบคงที่ 1 เดือน และสร้างเงื่อนไข SQL ที่ใช้ซ้ำ
declare(strict_types=1);

/** legacy parser: kept for backward compatibility with old notes */
function drawdream_needlist_period_label_from_note(string $note): string
{
    $lines = preg_split('/\R/u', $note, 2);
    $first = (string)($lines[0] ?? '');
    if (preg_match('/^ระยะเวลา:\s*(.+)$/u', $first, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * คำนวณเวลาปิดรับบริจาค "คงที่ 1 เดือน" นับจากเวลาที่แอดมินอนุมัติ
 *
 * @return string datetime 'Y-m-d H:i:s'
 */
function drawdream_needlist_compute_donate_window_end(string $periodLabel, DateTimeImmutable $approvalMoment): ?string
{
    try {
        return $approvalMoment->modify('+1 month')->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return (new DateTimeImmutable('now'))->modify('+1 month')->format('Y-m-d H:i:s');
    }
}

/**
 * เงื่อนไข SQL: รายการที่ยังเปิดรับบริจาคได้ (อนุมัติแล้ว และยังไม่ถึงเวลาปิด)
 *
 * @param non-empty-string $alias prefix เช่น '' หรือ 'nl.'
 */
function drawdream_needlist_sql_open_for_donation(string $alias = ''): string
{
    $a = $alias;
    return "({$a}approve_item = 'approved' AND ({$a}donate_window_end_at IS NULL OR {$a}donate_window_end_at > NOW()))";
}

/**
 * มูลนิธิยังเสนอรายการสิ่งของใหม่ไม่ได้ (รอตรวจ / รอบรับบริจาคยังไม่จบ รวมกรณียังไม่มีวันปิดใน DB / กำลังจัดซื้อ)
 *
 * @return array{blocked: bool, reason: string, donate_end_at: ?string}
 */
function drawdream_foundation_needlist_propose_blocked(mysqli $conn, int $foundationId): array
{
    $none = ['blocked' => false, 'reason' => '', 'donate_end_at' => null];
    if ($foundationId <= 0) {
        return $none;
    }
    $st = $conn->prepare(
        "SELECT approve_item, donate_window_end_at FROM foundation_needlist
         WHERE foundation_id = ?
           AND (
             approve_item = 'pending'
             OR approve_item = 'purchasing'
             OR (approve_item = 'approved' AND (donate_window_end_at IS NULL OR donate_window_end_at > NOW()))
           )
         ORDER BY
           CASE approve_item
             WHEN 'pending' THEN 0
             WHEN 'purchasing' THEN 1
             ELSE 2
           END,
           item_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return $none;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return $none;
    }
    $ap = (string)($row['approve_item'] ?? '');
    $endRaw = trim((string)($row['donate_window_end_at'] ?? ''));
    if ($ap === 'pending') {
        return ['blocked' => true, 'reason' => 'pending', 'donate_end_at' => null];
    }
    if ($ap === 'purchasing') {
        return ['blocked' => true, 'reason' => 'purchasing', 'donate_end_at' => null];
    }
    $endOut = ($endRaw !== '' && !str_starts_with($endRaw, '0000-00-00')) ? $endRaw : null;

    return ['blocked' => true, 'reason' => 'approved_open', 'donate_end_at' => $endOut];
}

/**
 * Backfill donate_window_end_at สำหรับรายการอนุมัติก่อนติดตั้งคอลัมน์
 */
function drawdream_needlist_backfill_donate_window_ends(mysqli $conn): void
{
    $sql = "SELECT item_id, note, reviewed_at FROM foundation_needlist
            WHERE approve_item = 'approved'
              AND (donate_window_end_at IS NULL)
              AND reviewed_at IS NOT NULL";
    $res = @$conn->query($sql);
    if (!$res) {
        return;
    }
    while ($row = $res->fetch_assoc()) {
        $rid = (int)($row['item_id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $rv = trim((string)($row['reviewed_at'] ?? ''));
        if ($rv === '' || str_starts_with($rv, '0000-00-00')) {
            continue;
        }
        try {
            $from = new DateTimeImmutable($rv);
        } catch (Throwable $e) {
            continue;
        }
        $end = drawdream_needlist_compute_donate_window_end('', $from);
        $st = $conn->prepare('UPDATE foundation_needlist SET donate_window_end_at = ? WHERE item_id = ?');
        if ($st) {
            $st->bind_param('si', $end, $rid);
            $st->execute();
        }
    }
}
