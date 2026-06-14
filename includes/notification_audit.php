<?php
// includes/notification_audit.php — แจ้งเตือน notifications + audit แอดมิน
// สรุปสั้น: ส่งแจ้งเตือนผู้ใช้และบันทึก audit คิวงานฝั่งแอดมินในจุดเดียว
/**
 * แจ้งเตือน (notifications) + บันทึกคิว/audit ฝั่งแอดมิน (ตาราง admin)
 *
 * drawdream_send_notification — ไม่เก็บคอลัมน์ type ในตาราง notifications แล้ว
 * พารามิเตอร์ $type (ถ้ามี) และ $entityKey รองรับ backward compatible แต่ไม่บันทึกลง DB
 * โครงสร้าง notifications ปัจจุบันใช้ is_read รายแถว
 *
 * @see README.md
 */
// helpers: แจ้งเตือนผู้ใช้ + บันทึก admin พร้อมเชื่อมว่ามีการแจ้งเตือนไปที่ใคร

declare(strict_types=1);

require_once __DIR__ . '/admin_audit_migrate.php';
require_once __DIR__ . '/drawdream_schema_once.php';

function drawdream_ensure_notifications_table(mysqli $conn): void
{
    drawdream_schema_once('notifications_table', static function (mysqli $c): void {
        drawdream_ensure_notifications_table_inner($c);
    }, $conn);
}

/** @internal */
function drawdream_ensure_notifications_table_inner(mysqli $conn): void
{
    @$conn->query(
        "CREATE TABLE IF NOT EXISTS notifications (
            notif_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            message TEXT,
            link VARCHAR(512) DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $cType = @$conn->query("SHOW COLUMNS FROM `notifications` LIKE 'type'");
    if ($cType && $cType->num_rows > 0) {
        @$conn->query('ALTER TABLE `notifications` DROP COLUMN `type`');
    }
    // ลบคอลัมน์/ดัชนีเก่าที่ไม่ใช้แล้ว
    $c = @$conn->query("SHOW COLUMNS FROM `notifications` LIKE 'entity_key'");
    if ($c && $c->num_rows > 0) {
        @$conn->query("ALTER TABLE `notifications` DROP COLUMN entity_key");
    }
    $c2 = @$conn->query("SHOW COLUMNS FROM `notifications` LIKE 'is_read'");
    if ($c2 && $c2->num_rows === 0) {
        @$conn->query("ALTER TABLE `notifications` ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER link");
    }
    $i1 = @$conn->query("SHOW INDEX FROM `notifications` WHERE Key_name = 'idx_notif_user_entity'");
    if ($i1 && $i1->num_rows > 0) {
        @$conn->query("ALTER TABLE `notifications` DROP INDEX idx_notif_user_entity");
    }
    $i2 = @$conn->query("SHOW INDEX FROM `notifications` WHERE Key_name = 'idx_user_read'");
    if (!$i2 || $i2->num_rows === 0) {
        @$conn->query("ALTER TABLE `notifications` ADD INDEX idx_user_read (user_id, is_read)");
    }

    // ย้ายสถานะอ่านจากตารางเก่า notification_read_state -> is_read รายแถว แล้วลบตารางเก่า
    $legacyRead = @$conn->query("SHOW TABLES LIKE 'notification_read_state'");
    if ($legacyRead && $legacyRead->num_rows > 0) {
        @$conn->query(
            "UPDATE notifications n
             INNER JOIN notification_read_state rs ON rs.user_id = n.user_id
             SET n.is_read = 1
             WHERE rs.last_read_at IS NOT NULL
               AND n.created_at <= rs.last_read_at"
        );
        @$conn->query("DROP TABLE notification_read_state");
    }
}

function drawdream_notifications_mark_all_read(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    drawdream_ensure_notifications_table($conn);
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    return (bool)$stmt->execute();
}

/** ทำเครื่องหมายแจ้งเตือนรายการเดียวว่าอ่านแล้ว (ไม่ลบแถว) */
function drawdream_notifications_mark_read(mysqli $conn, int $userId, int $notifId): bool
{
    if ($userId <= 0 || $notifId <= 0) {
        return false;
    }
    drawdream_ensure_notifications_table($conn);
    $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND user_id = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $notifId, $userId);
    return (bool)$stmt->execute();
}

/** มีแจ้งเตือนเดิม title+link สำหรับ user นี้แล้ว (กันซ้ำจาก polling ชำระเงิน) */
function drawdream_notification_exists_for_user(
    mysqli $conn,
    int $userId,
    string $title,
    string $link
): bool {
    if ($userId <= 0) {
        return false;
    }
    drawdream_ensure_notifications_table($conn);
    $st = $conn->prepare(
        'SELECT 1 FROM notifications WHERE user_id = ? AND title = ? AND link = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('iss', $userId, $title, $link);
    $st->execute();

    return (bool)$st->get_result()->fetch_assoc();
}

function drawdream_notifications_unread_count(mysqli $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    drawdream_ensure_notifications_table($conn);

    $st = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0');
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int)($row['cnt'] ?? 0);
}

/**
 * เรียกจาก db.php ครั้งแรกที่ยังมีข้อมูลเก่า: ลบแจ้งเตือน “ส่ง…แล้ว” ที่ระบบไม่สร้างแล้ว
 * หลังลบครั้งแรก จะไม่มีแถวค้าง → ไม่มีผลต่อ performance รอบถัดไป
 */
function drawdream_notifications_migrate_legacy_on_boot(mysqli $conn): void
{
    drawdream_ensure_notifications_table($conn);
    $legacyTitles = [
        'ส่งรายการสิ่งของแล้ว',
        'ส่งโปรไฟล์เด็กแล้ว',
        'ส่งคำขอเสนอโครงการแล้ว',
    ];
    $inList = implode(',', array_map(static function (string $t) use ($conn): string {
        return "'" . $conn->real_escape_string($t) . "'";
    }, $legacyTitles));
    $chk = @$conn->query("SELECT 1 FROM notifications WHERE title IN ({$inList}) LIMIT 1");
    if (!$chk || $chk->num_rows === 0) {
        return;
    }
    @$conn->query("DELETE FROM notifications WHERE title IN ({$inList})");
}

/** ลบแจ้งเตือนคิวงานที่เคยอ้างด้วย entity_key (คงชื่อฟังก์ชันเดิมไว้เพื่อไม่ให้จุดเรียกพัง) */
function drawdream_notifications_delete_by_entity_key(mysqli $conn, string $entityKey): void
{
    if ($entityKey === '') {
        return;
    }
    drawdream_ensure_notifications_table($conn);
    $link = '';
    $title = '';
    if (str_starts_with($entityKey, 'adm_pending_project:')) {
        $id = (int)substr($entityKey, strlen('adm_pending_project:'));
        $link = 'admin_approve_projects.php?id=' . $id;
        $title = 'มีโครงการรออนุมัติ';
    } elseif (str_starts_with($entityKey, 'adm_pending_need:')) {
        $link = 'admin_approve_needlist.php';
        $title = 'รายการสิ่งของรออนุมัติ';
    } elseif (str_starts_with($entityKey, 'adm_pending_child:')) {
        $id = (int)substr($entityKey, strlen('adm_pending_child:'));
        $link = 'children_donate.php?id=' . $id;
        $title = 'โปรไฟล์เด็กรออนุมัติ';
    } elseif (str_starts_with($entityKey, 'fdn_need_round_open:')) {
        $link = 'foundation_add_need.php';
        $title = 'ถึงเวลาเสนอรายการสิ่งของรอบใหม่';
    }

    if ($link !== '' && $title !== '') {
        $st = $conn->prepare('DELETE FROM notifications WHERE link = ? AND title = ?');
        if ($st) {
            $st->bind_param('ss', $link, $title);
            @$st->execute();
        }
    }
}

/** แยกข้อความเหตุผลจากเนื้อหาแจ้งเตือนรูปแบบ "โปรไฟล์ {ชื่อ}: {เหตุผล}" */
function drawdream_parse_child_reject_notification_message(string $message, string $childName): string
{
    $message = trim($message);
    if ($message === '') {
        return '';
    }
    $childName = trim($childName);
    if ($childName !== '') {
        $prefix = 'โปรไฟล์ ' . $childName . ': ';
        if (str_starts_with($message, $prefix)) {
            return trim(substr($message, strlen($prefix)));
        }
    }
    if (preg_match('/^โปรไฟล์\s*.+?:\s*(.+)$/us', $message, $m)) {
        return trim((string)$m[1]);
    }

    return $message;
}

/** ดึงเหตุผลจาก remark ตาราง admin หลัง merge (ข้อความส่งตรวจ | เหตุผลไม่อนุมัติ) */
function drawdream_child_reject_reason_from_admin_remark(string $remark): string
{
    $remark = trim($remark);
    if ($remark === '') {
        return '';
    }
    if (str_contains($remark, ' | ')) {
        $parts = explode(' | ', $remark);

        return trim((string)end($parts));
    }
    if (str_starts_with($remark, 'มูลนิธิ')) {
        return '';
    }

    return $remark;
}

/**
 * เหตุผลไม่อนุมัติโปรไฟล์เด็กสำหรับแสดงใน UI — ไม่เก็บใน foundation_children
 * ลำดับ: แจ้งเตือนล่าสุด → remark ใน audit แอดมิน
 */
function drawdream_foundation_child_profile_reject_reason_for_ui(mysqli $conn, int $childId, string $childName): string
{
    if ($childId <= 0) {
        return '';
    }
    drawdream_ensure_notifications_table($conn);
    $title = 'ไม่อนุมัติโปรไฟล์เด็ก';
    $link = 'children_donate.php?id=' . $childId;
    $stmt = $conn->prepare('SELECT message FROM notifications WHERE title = ? AND link = ? ORDER BY created_at DESC LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ss', $title, $link);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $msg = trim((string)($row['message'] ?? ''));
            if ($msg !== '') {
                $parsed = drawdream_parse_child_reject_notification_message($msg, $childName);
                if ($parsed !== '') {
                    return $parsed;
                }
            }
        }
    }

    drawdream_ensure_admin_audit_table($conn);
    $stmt2 = $conn->prepare(
        'SELECT remark FROM `admin` WHERE target_id = ? AND target_entity = ? AND notif_type = ? ORDER BY action_at DESC, id DESC LIMIT 1'
    );
    if ($stmt2) {
        $ent = 'child';
        $nt = 'ไม่อนุมัติ';
        $stmt2->bind_param('iss', $childId, $ent, $nt);
        if ($stmt2->execute()) {
            $row2 = $stmt2->get_result()->fetch_assoc();

            return drawdream_child_reject_reason_from_admin_remark((string)($row2['remark'] ?? ''));
        }
    }

    return '';
}

/**
 * @param array<int, array<string, mixed>> $childRows
 * @return array<int, string> child_id => เหตุผล (ว่างได้)
 */
function drawdream_foundation_child_profile_reject_reasons_for_children_batch(mysqli $conn, array $childRows): array
{
    $rejected = [];
    foreach ($childRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['approve_profile'] ?? '') !== 'ไม่อนุมัติ') {
            continue;
        }
        $cid = (int)($row['child_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $rejected[$cid] = trim((string)($row['child_name'] ?? ''));
    }
    if ($rejected === []) {
        return [];
    }

    drawdream_ensure_notifications_table($conn);
    $out = [];
    foreach (array_keys($rejected) as $cid) {
        $out[$cid] = '';
    }

    $title = 'ไม่อนุมัติโปรไฟล์เด็ก';
    $linkList = [];
    foreach (array_keys($rejected) as $cid) {
        $linkList[] = 'children_donate.php?id=' . $cid;
    }

    $placeholders = implode(',', array_fill(0, count($linkList), '?'));
    $sql = "SELECT link, message, created_at FROM notifications WHERE title = ? AND link IN ($placeholders) ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = 's' . str_repeat('s', count($linkList));
        $stmt->bind_param($types, $title, ...$linkList);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $link = (string)($row['link'] ?? '');
                $message = (string)($row['message'] ?? '');
                if (!preg_match('/children_donate\\.php\\?id=(\\d+)/', $link, $m)) {
                    continue;
                }
                $cid = (int)$m[1];
                if (!array_key_exists($cid, $out) || $out[$cid] !== '') {
                    continue;
                }
                $parsed = drawdream_parse_child_reject_notification_message($message, $rejected[$cid] ?? '');
                if ($parsed !== '') {
                    $out[$cid] = $parsed;
                }
            }
        }
    }

    $missing = [];
    foreach ($out as $cid => $reason) {
        if ($reason === '') {
            $missing[] = $cid;
        }
    }
    if ($missing !== []) {
        drawdream_ensure_admin_audit_table($conn);
        $placeholders2 = implode(',', array_fill(0, count($missing), '?'));
        $sql2 = "SELECT target_id, remark, action_at, id FROM `admin` WHERE target_entity = 'child' AND notif_type = 'ไม่อนุมัติ' AND target_id IN ($placeholders2) ORDER BY action_at DESC, id DESC";
        $stmt2 = $conn->prepare($sql2);
        if ($stmt2) {
            $types2 = str_repeat('i', count($missing));
            $stmt2->bind_param($types2, ...$missing);
            if ($stmt2->execute()) {
                $res2 = $stmt2->get_result();
                while ($row2 = $res2->fetch_assoc()) {
                    $tid = (int)($row2['target_id'] ?? 0);
                    if ($tid <= 0 || !array_key_exists($tid, $out) || $out[$tid] !== '') {
                        continue;
                    }
                    $parsed = drawdream_child_reject_reason_from_admin_remark((string)($row2['remark'] ?? ''));
                    if ($parsed !== '') {
                        $out[$tid] = $parsed;
                    }
                }
            }
        }
    }

    return $out;
}

function drawdream_ensure_admin_notif_columns(mysqli $conn): void
{
    $c = @$conn->query("SHOW COLUMNS FROM `admin` WHERE Field = 'notif_recipient_user_id'");
    if ($c && $c->num_rows === 0) {
        @$conn->query("ALTER TABLE `admin` ADD COLUMN notif_recipient_user_id INT UNSIGNED NULL DEFAULT NULL AFTER remark");
    }
    $c2 = @$conn->query("SHOW COLUMNS FROM `admin` WHERE Field = 'notif_type'");
    if ($c2 && $c2->num_rows === 0) {
        @$conn->query("ALTER TABLE `admin` ADD COLUMN notif_type VARCHAR(64) NULL DEFAULT NULL AFTER notif_recipient_user_id");
    }
    // แถวเก่าที่บันทึกเป็นรหัสอังกฤษ — เก็บมาตรฐานเดียวกับ project_submitted / ประเภทรอดำเนินการ
    @$conn->query(
        "UPDATE `admin` SET notif_type = 'กำลังรอดำเนินการ' WHERE TRIM(COALESCE(notif_type,'')) IN ('child_submitted','project_submitted','need_submitted')"
    );
}

/** หัวข้อแจ้งเตือนที่เปิดหน้าจดหมายจากเด็ก (ซอง → กระดาษ) */
function drawdream_child_letter_notification_titles(): array
{
    return ['จดหมายจากเด็ก', 'อัปเดตผลลัพธ์เด็กที่คุณอุปการะ'];
}

function drawdream_is_child_letter_notification(string $title): bool
{
    $t = trim($title);
    if ($t === '') {
        return false;
    }
    if (in_array($t, drawdream_child_letter_notification_titles(), true)) {
        return true;
    }

    return str_contains($t, 'จดหมายจากเด็ก') || str_contains($t, 'ผลลัพธ์เด็ก');
}

/** บังคับ view=outcome&letter=open สำหรับลิงก์ children_donate จากแจ้งเตือนจดหมาย (เห็นกระดาษทันที) */
function drawdream_normalize_child_donate_notification_link(string $link, string $title): string
{
    $raw = trim($link);
    if ($raw === '' || !drawdream_is_child_letter_notification($title)) {
        return $raw;
    }
    if (preg_match('~^https?://[^/]+/(.+)$~i', $raw, $hostMatch)) {
        $raw = $hostMatch[1];
    }
    if (!preg_match('~^/?children_donate\.php\?~i', $raw)) {
        return trim($link);
    }
    $path = ltrim($raw, '/');
    if (stripos($path, 'view=outcome') === false) {
        $path .= (str_contains($path, '?') ? '&' : '?') . 'view=outcome';
    }
    if (preg_match('/(?:^|[?&])letter=1(?:&|$)/i', $path)) {
        $path = preg_replace('/((?:^|[?&])letter=)1/i', '${1}open', $path) ?? $path;
    } elseif (!preg_match('/(?:^|[?&])letter=/i', $path)) {
        $path .= (str_contains($path, '?') ? '&' : '?') . 'letter=open';
    }

    return $path;
}

/**
 * ไอคอน/คลาสการ์ดแจ้งเตือนจากหัวข้อและลิงก์ (ไม่ใช้คอลัมน์ type)
 *
 * @return array{icon: string, class: string}
 */
function drawdream_notification_card_meta(string $title, string $link = ''): array
{
    $t = trim($title);
    if ($t !== '' && str_contains($t, 'อนุมัติ') && !str_contains($t, 'ไม่อนุมัติ')) {
        return ['icon' => '✅', 'class' => 'approved'];
    }
    if ($t !== '' && (str_contains($t, 'ไม่อนุมัติ') || str_contains($t, 'ปฏิเสธ'))) {
        return ['icon' => '⛔', 'class' => 'rejected'];
    }
    if ($t !== '' && (str_contains($t, 'รอ') || str_contains($t, 'ตรวจสอบ'))) {
        return ['icon' => '⏳', 'class' => 'pending'];
    }
    if (str_contains($t, 'ประกาศ') || str_contains($link, 'broadcast')) {
        return ['icon' => '📣', 'class' => 'broadcast'];
    }
    if (str_contains($t, 'ครบเป้า') || str_contains($t, 'ครบแล้ว') || str_contains($t, 'พร้อมอัปเดต')) {
        return ['icon' => '🎉', 'class' => 'success'];
    }
    if ($t === 'จดหมายจากเด็ก' || str_contains($t, 'จดหมายจากเด็ก')) {
        return ['icon' => '✉️', 'class' => 'letter'];
    }
    if ($t !== '' && str_contains($t, 'ผลลัพธ์เด็ก')) {
        return ['icon' => '✉️', 'class' => 'letter'];
    }

    return ['icon' => '🔔', 'class' => 'other'];
}

function drawdream_send_notification(
    mysqli $conn,
    int $userId,
    string $type,
    string $title,
    string $message,
    string $link = '',
    ?string $entityKey = null
): bool {
    if ($userId <= 0) {
        return false;
    }
    unset($type, $entityKey);
    drawdream_ensure_notifications_table($conn);
    if (drawdream_notification_exists_for_user($conn, $userId, $title, $link)) {
        return true;
    }
    $stmt = $conn->prepare('INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isss', $userId, $title, $message, $link);
    return $stmt->execute();
}

/** บันทึกลงตาราง admin ว่ามีโครงการถูกเสนอ (ไม่มีแอดมิน — admin_id เป็น NULL; ใช้ notif_type แทน action_type) */
function drawdream_record_foundation_submitted_project(
    mysqli $conn,
    int $foundationUserId,
    int $projectId,
    string $projectName
): void {
    drawdream_ensure_admin_notif_columns($conn);
    $entity = 'project';
    $remark = 'มูลนิธิ user_id ' . $foundationUserId . ' เสนอโครงการ: ' . $projectName;
    $notifType = drawdream_normalize_notif_type_to_th('project_submitted');
    $stmt = $conn->prepare(
        'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (NULL, ?, ?, ?, NULL, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isss', $projectId, $entity, $remark, $notifType);
    @$stmt->execute();
}

/** มูลนิธิส่งโปรไฟล์เด็กใหม่ (บันทึกในตาราง admin สำหรับแอดมิน) */
function drawdream_record_foundation_submitted_child(
    mysqli $conn,
    int $foundationUserId,
    int $childId,
    string $childName
): void {
    drawdream_ensure_admin_notif_columns($conn);
    $entity = 'child';
    $remark = 'มูลนิธิ user_id ' . $foundationUserId . ' เสนอโปรไฟล์เด็ก: ' . $childName;
    $notifType = drawdream_normalize_notif_type_to_th('child_submitted');
    $stmt = $conn->prepare(
        'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (NULL, ?, ?, ?, NULL, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isss', $childId, $entity, $remark, $notifType);
    @$stmt->execute();
}

/** แจ้งเตือนแอดมินทุกคนเมื่อมีโปรไฟล์เด็กรออนุมัติ */
function drawdream_notify_admins_child_submitted(
    mysqli $conn,
    int $childId,
    string $childName,
    string $foundationName
): void {
    if ($childId <= 0) {
        return;
    }
    drawdream_ensure_notifications_table($conn);
    $link = 'children_donate.php?id=' . $childId;
    foreach (drawdream_admin_user_ids($conn) as $adminUid) {
        if ($adminUid <= 0) {
            continue;
        }
        drawdream_send_notification(
            $conn,
            $adminUid,
            'child_submitted',
            'โปรไฟล์เด็กรออนุมัติ',
            'มูลนิธิ ' . $foundationName . ' เสนอโปรไฟล์เด็ก: ' . $childName,
            $link,
            'adm_pending_child:' . $childId
        );
    }
}

/** มูลนิธิเสนอรายการสิ่งของ — บันทึกตาราง admin (แอดมินดูคิว / ประวัติ) */
function drawdream_record_foundation_submitted_need(
    mysqli $conn,
    int $foundationUserId,
    int $itemId,
    string $itemSummary,
    float $goalAmount,
    string $foundationName,
    bool $isUrgent
): void {
    if ($itemId <= 0) {
        return;
    }
    drawdream_ensure_admin_notif_columns($conn);
    $entity = 'need';
    $urgentTag = $isUrgent ? ' [ต้องการด่วน]' : '';
    $fn = trim($foundationName) !== '' ? $foundationName : ('user_id ' . $foundationUserId);
    $remark = 'มูลนิธิ ' . $fn . ' (user_id ' . $foundationUserId . ') เสนอรายการสิ่งของ' . $urgentTag . ': ' . $itemSummary
        . ' | เป้าหมาย ' . number_format($goalAmount, 0) . ' บาท';
    $notifType = drawdream_normalize_notif_type_to_th('need_submitted');
    $stmt = $conn->prepare(
        'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (NULL, ?, ?, ?, NULL, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isss', $itemId, $entity, $remark, $notifType);
    @$stmt->execute();
}

/** แจ้งเตือนแอดมินทุกคนเมื่อมีรายการสิ่งของรออนุมัติ */
function drawdream_notify_admins_need_submitted(
    mysqli $conn,
    int $itemId,
    string $itemSummary,
    string $foundationName,
    float $goalAmount,
    bool $isUrgent
): void {
    if ($itemId <= 0) {
        return;
    }
    drawdream_ensure_notifications_table($conn);
    $link = 'admin_approve_needlist.php';
    $urgentPart = $isUrgent ? ' (ต้องการด่วน)' : '';
    $fn = trim($foundationName) !== '' ? $foundationName : 'มูลนิธิ';
    $body = $fn . ' เสนอรายการสิ่งของ' . $urgentPart . ': ' . $itemSummary
        . ' — เป้าหมาย ' . number_format($goalAmount, 0) . ' บาท';
    foreach (drawdream_admin_user_ids($conn) as $adminUid) {
        if ($adminUid <= 0) {
            continue;
        }
        drawdream_send_notification(
            $conn,
            $adminUid,
            'need_submitted',
            'รายการสิ่งของรออนุมัติ',
            $body,
            $link,
            'adm_pending_need:' . $itemId
        );
    }
}

/** แจ้งแอดมินทุกคนเมื่อมูลนิธิชำระค่าบริการรายการสิ่งของแล้ว */
function drawdream_notify_admins_needlist_service_charge_paid(
    mysqli $conn,
    int $itemId,
    string $itemName,
    string $foundationName
): void {
    if ($itemId <= 0) {
        return;
    }
    drawdream_ensure_notifications_table($conn);
    $dispItem = trim($itemName) !== '' ? trim($itemName) : 'รายการสิ่งของ';
    $dispFn = trim($foundationName) !== '' ? trim($foundationName) : 'มูลนิธิ';
    $title = 'มูลนิธิชำระค่าบริการแล้ว';
    $msg = $dispFn . ' ชำระค่าบริการระบบสำหรับรายการ "' . $dispItem . '" แล้ว '
        . 'สามารถเริ่มจัดซื้อและยืนยันจัดส่งได้';
    $link = 'admin_escrow.php';
    foreach (drawdream_admin_user_ids($conn) as $adminUid) {
        if ($adminUid <= 0) {
            continue;
        }
        drawdream_send_notification(
            $conn,
            $adminUid,
            'needlist_service_charge_paid',
            $title,
            $msg,
            $link,
            'needlist_sc_paid:' . $itemId
        );
    }
}

/** แจ้งแอดมินเมื่อมูลนิธิชำระค่าบริการโครงการแล้ว */
function drawdream_notify_admins_project_service_charge_paid(
    mysqli $conn,
    int $projectId,
    string $projectName,
    string $foundationName
): void {
    if ($projectId <= 0) {
        return;
    }
    drawdream_ensure_notifications_table($conn);
    $dispProj = trim($projectName) !== '' ? trim($projectName) : 'โครงการ';
    $dispFn = trim($foundationName) !== '' ? trim($foundationName) : 'มูลนิธิ';
    $title = 'มูลนิธิชำระค่าบริการโครงการแล้ว';
    $msg = $dispFn . ' ชำระค่าบริการระบบสำหรับโครงการ "' . $dispProj . '" แล้ว '
        . 'สามารถยืนยันโอนเงิน escrow ได้';
    $link = 'admin_escrow.php';
    foreach (drawdream_admin_user_ids($conn) as $adminUid) {
        if ($adminUid <= 0) {
            continue;
        }
        drawdream_send_notification(
            $conn,
            $adminUid,
            'project_service_charge_paid',
            $title,
            $msg,
            $link,
            'project_sc_paid:' . $projectId
        );
    }
}

/** @return int[] */
function drawdream_admin_user_ids(mysqli $conn): array
{
    $r = @$conn->query("SELECT user_id FROM `user` WHERE LOWER(TRIM(COALESCE(role,''))) = 'admin'");
    if (!$r) {
        return [];
    }
    $out = [];
    while ($row = $r->fetch_assoc()) {
        $uid = (int)($row['user_id'] ?? 0);
        if ($uid > 0) {
            $out[] = $uid;
        }
    }
    return $out;
}

function drawdream_log_admin_action(
    mysqli $conn,
    int $adminId,
    string $actionType,
    int $targetId,
    string $remark,
    ?int $notifRecipientUserId = null,
    ?string $notifType = null
): bool {
    drawdream_ensure_admin_notif_columns($conn);
    $entity = drawdream_action_target_entity($actionType);
    if ($notifRecipientUserId !== null && $notifRecipientUserId > 0 && $notifType !== null && $notifType !== '') {
        // กรณีมีผู้รับการแจ้งเตือน + ประเภทแจ้งเตือน: ถือว่าเป็น action ต่อจากที่มูลนิธิเสนอเข้ามาแล้ว
        // พยายามอัปเดตแถวเดิมในตาราง admin (target เดียวกัน) แทนการสร้างแถวใหม่ซ้ำ
        $notifTypeTh = drawdream_normalize_notif_type_to_th($notifType);

        $existingId = 0;
        $existingRemark = '';
        $sel = $conn->prepare('SELECT id, remark FROM `admin` WHERE target_id = ? AND target_entity = ? ORDER BY id ASC LIMIT 1');
        if ($sel) {
            $sel->bind_param('is', $targetId, $entity);
            if ($sel->execute()) {
                $res = $sel->get_result();
                if ($row = $res->fetch_assoc()) {
                    $existingId = (int)($row['id'] ?? 0);
                    $existingRemark = (string)($row['remark'] ?? '');
                }
            }
        }

        if ($existingId > 0) {
            // รวมข้อความ remark เดิม + ใหม่ เพื่อเก็บเป็นเหตุการณ์เดียวกัน
            $mergedRemark = trim($existingRemark);
            $extra = trim($remark);
            if ($extra !== '') {
                $mergedRemark = $mergedRemark !== '' ? ($mergedRemark . ' | ' . $extra) : $extra;
            }

            $up = $conn->prepare(
                'UPDATE `admin` SET admin_id = ?, remark = ?, notif_recipient_user_id = ?, notif_type = ?, action_at = NOW() WHERE id = ?'
            );
            if (!$up) {
                return false;
            }
            $up->bind_param('isisi', $adminId, $mergedRemark, $notifRecipientUserId, $notifTypeTh, $existingId);
            return $up->execute();
        }

        // ถ้าไม่พบแถวเดิม (เช่น ข้อมูลเก่าหรือเคยลบไป) ค่อย fallback ไปสร้างแถวใหม่
        $stmt = $conn->prepare(
            'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iissis', $adminId, $targetId, $entity, $remark, $notifRecipientUserId, $notifTypeTh);
        return $stmt->execute();
    }
    $stmt = $conn->prepare(
        'INSERT INTO `admin` (admin_id, target_id, target_entity, remark, notif_recipient_user_id, notif_type) VALUES (?, ?, ?, ?, NULL, NULL)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiss', $adminId, $targetId, $entity, $remark);
    return $stmt->execute();
}

function drawdream_foundation_user_id_by_name(mysqli $conn, string $foundationName): int
{
    $foundationName = trim($foundationName);
    if ($foundationName === '') {
        return 0;
    }
    $st = $conn->prepare('SELECT user_id FROM foundation_profile WHERE foundation_name = ? LIMIT 1');
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $foundationName);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int)($row['user_id'] ?? 0);
}

function drawdream_foundation_user_id_by_foundation_id(mysqli $conn, int $foundationId): int
{
    if ($foundationId <= 0) {
        return 0;
    }
    $st = $conn->prepare('SELECT user_id FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int)($row['user_id'] ?? 0);
}

/**
 * รวบรวม user_id สำหรับประกาศจากแอดมิน — $recipient: donors | foundations | both
 *
 * @return list<int>
 */
function drawdream_admin_broadcast_recipient_user_ids(mysqli $conn, string $recipient): array
{
    $recipient = strtolower(trim($recipient));
    $ids = [];

    if ($recipient === 'donors' || $recipient === 'both') {
        $q = @$conn->query(
            "SELECT DISTINCT d.user_id FROM donor d
             INNER JOIN `user` u ON u.user_id = d.user_id
             WHERE LOWER(TRIM(COALESCE(u.role, ''))) = 'donor' AND d.user_id > 0"
        );
        if ($q) {
            while ($row = $q->fetch_assoc()) {
                $ids[] = (int)($row['user_id'] ?? 0);
            }
        }
    }

    if ($recipient === 'foundations' || $recipient === 'both') {
        $q2 = @$conn->query(
            "SELECT DISTINCT fp.user_id FROM foundation_profile fp
             INNER JOIN `user` u ON u.user_id = fp.user_id
             WHERE LOWER(TRIM(COALESCE(u.role, ''))) = 'foundation' AND fp.user_id > 0"
        );
        if ($q2) {
            while ($row = $q2->fetch_assoc()) {
                $ids[] = (int)($row['user_id'] ?? 0);
            }
        }
    }

    $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

    return $ids;
}

/**
 * ส่งแจ้งเตือน (กระดิ่ง) จากแอดมินไปยังผู้บริจาคและ/หรือมูลนิธิ
 *
 * @return array{sent:int, error:string}
 */
function drawdream_admin_broadcast_notifications(mysqli $conn, string $recipient, string $message): array
{
    $recipient = strtolower(trim($recipient));
    if (!in_array($recipient, ['donors', 'foundations', 'both'], true)) {
        return ['sent' => 0, 'error' => 'กรุณาเลือกกลุ่มผู้รับ'];
    }
    $message = trim($message);
    if ($message === '') {
        return ['sent' => 0, 'error' => 'กรุณากรอกข้อความ'];
    }
    if (strlen($message) > 4000) {
        return ['sent' => 0, 'error' => 'ข้อความยาวเกิน 4,000 ตัวอักษร'];
    }

    $userIds = drawdream_admin_broadcast_recipient_user_ids($conn, $recipient);
    if ($userIds === []) {
        return ['sent' => 0, 'error' => 'ไม่พบบัญชีผู้รับในกลุ่มที่เลือก'];
    }

    $title = 'ประกาศจากผู้ดูแลระบบ';
    $link = 'notifications.php';
    $sent = 0;
    foreach ($userIds as $uid) {
        if (drawdream_send_notification($conn, $uid, 'admin_broadcast', $title, $message, $link)) {
            ++$sent;
        }
    }

    return ['sent' => $sent, 'error' => ''];
}
