<?php
declare(strict_types=1);

require_once __DIR__ . '/foundation_bulk_tasks.php';
require_once __DIR__ . '/notification_audit.php';
require_once __DIR__ . '/drawdream_schema_once.php';

/** @see DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED in foundation_account_verified.php */
if (!defined('DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED')) {
    define('DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED', 3);
}

/** แจ้งเตือนเมื่อค้างเกิน 30 วันนับจากครบกำหนด */
const DRAWDREAM_OUTCOME_COMPLIANCE_WARN_DAYS = 30;

/** พักบัญชีเมื่อค้างเกิน 37 วัน (30 + 7 หลังเตือน) */
const DRAWDREAM_OUTCOME_COMPLIANCE_PAUSE_DAYS = 37;

function drawdream_foundation_outcome_compliance_ensure_schema(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $cols = [
        'outcome_compliance_warned_at' => "ALTER TABLE foundation_profile ADD COLUMN outcome_compliance_warned_at DATETIME NULL DEFAULT NULL AFTER account_verified",
        'outcome_compliance_paused_at' => "ALTER TABLE foundation_profile ADD COLUMN outcome_compliance_paused_at DATETIME NULL DEFAULT NULL AFTER outcome_compliance_warned_at",
    ];
    foreach ($cols as $name => $ddl) {
        $chk = @$conn->query("SHOW COLUMNS FROM foundation_profile LIKE '" . $conn->real_escape_string($name) . "'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query($ddl);
        }
    }
}

function drawdream_foundation_outcome_days_overdue(DateTimeImmutable $dueSince, DateTimeImmutable $now): int
{
    if ($dueSince > $now) {
        return 0;
    }

    return (int)$dueSince->diff($now)->days;
}

function drawdream_foundation_project_outcome_due_since(array $row, DateTimeImmutable $monthStart): DateTimeImmutable
{
    $updateAt = trim((string)($row['update_at'] ?? ''));
    $updateText = trim((string)($row['update_text'] ?? ''));
    if ($updateAt !== '' && $updateText !== '') {
        return $monthStart;
    }
    $sc = trim((string)($row['service_charge_paid_at'] ?? ''));
    if ($sc !== '') {
        try {
            $dt = new DateTimeImmutable($sc, $monthStart->getTimezone());

            return $dt > $monthStart ? $dt : $monthStart;
        } catch (Exception $e) {
            return $monthStart;
        }
    }

    return $monthStart;
}

function drawdream_foundation_needlist_outcome_due_since(array $row, DateTimeImmutable $monthStart): DateTimeImmutable
{
    $updateAt = trim((string)($row['update_at'] ?? ''));
    $updateText = trim((string)($row['update_text'] ?? ''));
    if ($updateAt !== '' && $updateText !== '') {
        return $monthStart;
    }
    $delivered = trim((string)($row['admin_delivery_at'] ?? ''));
    if ($delivered !== '') {
        try {
            $dt = new DateTimeImmutable($delivered, $monthStart->getTimezone());

            return $dt > $monthStart ? $dt : $monthStart;
        } catch (Exception $e) {
            return $monthStart;
        }
    }

    return $monthStart;
}

/**
 * รายการงานอัปเดตผลลัพธ์ที่ค้าง พร้อมจำนวนวันเกินกำหนด
 *
 * @return list<array{type: string, type_label: string, id: int, label: string, due_since: DateTimeImmutable, days_overdue: int}>
 */
function drawdream_foundation_outcome_compliance_obligations(
    mysqli $conn,
    int $foundationId,
    string $foundationName,
    ?DateTimeImmutable $now = null
): array {
    if ($foundationId <= 0) {
        return [];
    }
    $tz = new DateTimeZone('Asia/Bangkok');
    $now = $now ?? new DateTimeImmutable('now', $tz);
    $monthStart = new DateTimeImmutable('first day of this month midnight', $tz);
    $obligations = [];

    foreach (drawdream_foundation_children_outcome_due_list($conn, $foundationId) as $row) {
        $cid = (int)($row['child_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $started = drawdream_child_sponsorship_started_at($conn, $cid, $row);
        if (!$started instanceof DateTimeImmutable) {
            continue;
        }
        $dueSince = drawdream_child_outcome_period_start($started, $now);
        $obligations[] = [
            'type' => 'child',
            'type_label' => 'ข้อความจากเด็ก',
            'id' => $cid,
            'label' => trim((string)($row['child_name'] ?? '')),
            'due_since' => $dueSince,
            'days_overdue' => drawdream_foundation_outcome_days_overdue($dueSince, $now),
        ];
    }

    foreach (foundation_bulk_projects_outcome_due($conn, $foundationId, $foundationName) as $row) {
        $pid = (int)($row['project_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $dueSince = drawdream_foundation_project_outcome_due_since($row, $monthStart);
        $obligations[] = [
            'type' => 'project',
            'type_label' => 'โครงการ',
            'id' => $pid,
            'label' => trim((string)($row['project_name'] ?? '')),
            'due_since' => $dueSince,
            'days_overdue' => drawdream_foundation_outcome_days_overdue($dueSince, $now),
        ];
    }

    foreach (foundation_bulk_needlist_outcome_due($conn, $foundationId) as $row) {
        $iid = (int)($row['item_id'] ?? 0);
        if ($iid <= 0) {
            continue;
        }
        $dueSince = drawdream_foundation_needlist_outcome_due_since($row, $monthStart);
        $obligations[] = [
            'type' => 'need',
            'type_label' => 'สิ่งของ',
            'id' => $iid,
            'label' => trim((string)($row['item_name'] ?? '')),
            'due_since' => $dueSince,
            'days_overdue' => drawdream_foundation_outcome_days_overdue($dueSince, $now),
        ];
    }

    usort(
        $obligations,
        static fn(array $a, array $b): int => ($b['days_overdue'] <=> $a['days_overdue']) ?: strcmp($a['type'], $b['type'])
    );

    return $obligations;
}

/**
 * @param list<array{type: string, type_label: string, id: int, label: string, due_since: DateTimeImmutable, days_overdue: int}> $obligations
 * @return array{
 *   obligations: list<array<string,mixed>>,
 *   total: int,
 *   counts: array{child: int, project: int, need: int},
 *   max_days_overdue: int,
 *   should_warn: bool,
 *   should_pause: bool,
 *   none_due: bool
 * }
 */
function drawdream_foundation_outcome_compliance_summarize(array $obligations): array
{
    $counts = ['child' => 0, 'project' => 0, 'need' => 0];
    $maxDays = 0;
    foreach ($obligations as $o) {
        $type = (string)($o['type'] ?? '');
        if (isset($counts[$type])) {
            $counts[$type]++;
        }
        $maxDays = max($maxDays, (int)($o['days_overdue'] ?? 0));
    }

    return [
        'obligations' => $obligations,
        'total' => count($obligations),
        'counts' => $counts,
        'max_days_overdue' => $maxDays,
        'should_warn' => $maxDays >= DRAWDREAM_OUTCOME_COMPLIANCE_WARN_DAYS
            && $maxDays < DRAWDREAM_OUTCOME_COMPLIANCE_PAUSE_DAYS,
        'should_pause' => $maxDays >= DRAWDREAM_OUTCOME_COMPLIANCE_PAUSE_DAYS,
        'none_due' => $obligations === [],
    ];
}

function drawdream_foundation_outcome_compliance_summary_text(array $counts): string
{
    $parts = [];
    if (($counts['child'] ?? 0) > 0) {
        $parts[] = 'ข้อความจากเด็ก ' . (int)$counts['child'] . ' รายการ';
    }
    if (($counts['project'] ?? 0) > 0) {
        $parts[] = 'โครงการ ' . (int)$counts['project'] . ' รายการ';
    }
    if (($counts['need'] ?? 0) > 0) {
        $parts[] = 'สิ่งของ ' . (int)$counts['need'] . ' รายการ';
    }

    return $parts === [] ? 'ไม่มีรายการค้าง' : implode(' · ', $parts);
}

function drawdream_foundation_outcome_compliance_bulk_link(array $counts): string
{
    if (($counts['child'] ?? 0) > 0) {
        return 'foundation_bulk_child_outcome.php';
    }
    if (($counts['project'] ?? 0) > 0) {
        return 'foundation_bulk_project_outcome.php';
    }
    if (($counts['need'] ?? 0) > 0) {
        return 'foundation_bulk_needlist_outcome.php';
    }

    return 'foundation_dashboard.php';
}

/**
 * ประมวลผลนโยบายเตือน/พัก/ปลดพัก สำหรับมูลนิธีหนึ่งราย
 *
 * @return array{action: string, summary: array<string,mixed>}
 */
function drawdream_foundation_outcome_compliance_run(
    mysqli $conn,
    int $foundationUserId,
    int $foundationId,
    string $foundationName
): array {
    drawdream_foundation_outcome_compliance_ensure_schema($conn);

    if ($foundationUserId <= 0 || $foundationId <= 0) {
        return ['action' => 'skip', 'summary' => ['none_due' => true, 'max_days_overdue' => 0, 'counts' => ['child' => 0, 'project' => 0, 'need' => 0]]];
    }

    $st = $conn->prepare(
        'SELECT account_verified, outcome_compliance_warned_at, outcome_compliance_paused_at
         FROM foundation_profile WHERE foundation_id = ? AND user_id = ? LIMIT 1'
    );
    if (!$st) {
        return ['action' => 'error', 'summary' => []];
    }
    $st->bind_param('ii', $foundationId, $foundationUserId);
    $st->execute();
    $profile = $st->get_result()->fetch_assoc();
    if (!$profile) {
        return ['action' => 'skip', 'summary' => []];
    }

    $verified = (int)($profile['account_verified'] ?? 0);
    if (!in_array($verified, [1, DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED], true)) {
        return ['action' => 'skip_inactive', 'summary' => []];
    }

    $obligations = drawdream_foundation_outcome_compliance_obligations($conn, $foundationId, $foundationName);
    $summary = drawdream_foundation_outcome_compliance_summarize($obligations);
    $counts = $summary['counts'];
    $summaryText = drawdream_foundation_outcome_compliance_summary_text($counts);
    $link = drawdream_foundation_outcome_compliance_bulk_link($counts);

    if ($summary['none_due']) {
        if ($verified === DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED) {
            $up = $conn->prepare(
                'UPDATE foundation_profile
                 SET account_verified = 1,
                     outcome_compliance_warned_at = NULL,
                     outcome_compliance_paused_at = NULL
                 WHERE foundation_id = ? AND user_id = ?'
            );
            if ($up) {
                $up->bind_param('ii', $foundationId, $foundationUserId);
                $up->execute();
            }
            drawdream_send_notification(
                $conn,
                $foundationUserId,
                'foundation_outcome_unpaused',
                'บัญชีกลับมาใช้งานได้แล้ว',
                'อัปเดตผลลัพธ์ครบแล้ว — บัญชีมูลนิธิของคุณกลับมาแสดงต่อสาธารณะและรับบริจาคใหม่ได้ตามปกติ',
                'foundation_dashboard.php'
            );

            return ['action' => 'unpaused', 'summary' => $summary];
        }

        $clr = $conn->prepare(
            'UPDATE foundation_profile SET outcome_compliance_warned_at = NULL WHERE foundation_id = ? AND user_id = ?'
        );
        if ($clr) {
            $clr->bind_param('ii', $foundationId, $foundationUserId);
            $clr->execute();
        }

        return ['action' => 'ok', 'summary' => $summary];
    }

    if ($summary['should_pause']) {
        if ($verified !== DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED) {
            $up = $conn->prepare(
                'UPDATE foundation_profile
                 SET account_verified = ?,
                     outcome_compliance_paused_at = NOW()
                 WHERE foundation_id = ? AND user_id = ?'
            );
            if ($up) {
                $paused = DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED;
                $up->bind_param('iii', $paused, $foundationId, $foundationUserId);
                $up->execute();
            }
            drawdream_send_notification(
                $conn,
                $foundationUserId,
                'foundation_outcome_paused',
                'บัญชีถูกพักชั่วคราว — อัปเดตผลลัพธ์ค้างเกิน 37 วัน',
                'งานค้าง: ' . $summaryText . ' — บัญชีถูกพักจากการแสดงต่อสาธารณะและไม่สามารถเพิ่มรายการใหม่ได้ กรุณาอัปเดตผลลัพธ์ให้ครบเพื่อปลดพักอัตโนมัติ',
                $link
            );

            return ['action' => 'paused', 'summary' => $summary];
        }

        return ['action' => 'still_paused', 'summary' => $summary];
    }

    if ($summary['should_warn']) {
        $warnedAt = trim((string)($profile['outcome_compliance_warned_at'] ?? ''));
        if ($warnedAt === '') {
            $up = $conn->prepare(
                'UPDATE foundation_profile SET outcome_compliance_warned_at = NOW() WHERE foundation_id = ? AND user_id = ?'
            );
            if ($up) {
                $up->bind_param('ii', $foundationId, $foundationUserId);
                $up->execute();
            }
            drawdream_send_notification(
                $conn,
                $foundationUserId,
                'foundation_outcome_warn',
                'แจ้งเตือน: อัปเดตผลลัพธ์ค้างเกิน 30 วัน',
                'งานค้าง: ' . $summaryText . ' — หากยังไม่อัปเดตภายใน 7 วัน บัญชีจะถูกพักชั่วคราว (ซ่อนจากสาธารณะและหยุดรับรายการใหม่)',
                $link
            );

            return ['action' => 'warned', 'summary' => $summary];
        }

        return ['action' => 'warn_pending', 'summary' => $summary];
    }

    $clr = $conn->prepare(
        'UPDATE foundation_profile SET outcome_compliance_warned_at = NULL WHERE foundation_id = ? AND user_id = ?'
    );
    if ($clr) {
        $clr->bind_param('ii', $foundationId, $foundationUserId);
        $clr->execute();
    }

    return ['action' => 'due_under_warn', 'summary' => $summary];
}

/**
 * รันตรวจทุกมูลนิธิที่ใช้งานหรือถูกพัก (สำหรับ cron)
 *
 * @return list<array<string,mixed>>
 */
function drawdream_foundation_outcome_compliance_run_all(mysqli $conn): array
{
    drawdream_foundation_outcome_compliance_ensure_schema($conn);
    $results = [];
    $st = $conn->query(
        'SELECT fp.user_id, fp.foundation_id, fp.foundation_name
         FROM foundation_profile fp
         INNER JOIN `user` u ON u.user_id = fp.user_id AND u.role = \'foundation\'
         WHERE fp.account_verified IN (1, ' . DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED . ')
         ORDER BY fp.foundation_id ASC'
    );
    if (!$st) {
        return $results;
    }
    while ($row = $st->fetch_assoc()) {
        $uid = (int)($row['user_id'] ?? 0);
        $fid = (int)($row['foundation_id'] ?? 0);
        $name = trim((string)($row['foundation_name'] ?? ''));
        $run = drawdream_foundation_outcome_compliance_run($conn, $uid, $fid, $name);
        $results[] = [
            'foundation_id' => $fid,
            'user_id' => $uid,
            'foundation_name' => $name,
            'action' => $run['action'],
            'max_days_overdue' => (int)($run['summary']['max_days_overdue'] ?? 0),
        ];
    }

    return $results;
}
