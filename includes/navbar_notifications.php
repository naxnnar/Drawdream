<?php
// includes/navbar_notifications.php — โหลดแจ้งเตือน navbar แบบเบา + เสริมมูลนิธิ

declare(strict_types=1);

require_once __DIR__ . '/navbar_cache.php';

/**
 * @return list<array{notif_id:int,title:string,message:string,link:string,created_at:string,is_read:int}>
 */
function drawdream_navbar_notifications_from_db(mysqli $conn, int $userId, int $limit = 10): array
{
    if ($userId <= 0 || $limit <= 0) {
        return [];
    }
    require_once __DIR__ . '/notification_audit.php';

    $items = [];
    $st = $conn->prepare(
        'SELECT notif_id, title, message, link, created_at, is_read
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT ?'
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('ii', $userId, $limit);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) {
        $items[] = [
            'notif_id' => (int)($row['notif_id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'link' => (string)($row['link'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'is_read' => (int)($row['is_read'] ?? 0),
        ];
    }

    return $items;
}

function drawdream_navbar_foundation_id_for_user(mysqli $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $cached = (int)($_SESSION['foundation_id'] ?? 0);
    if ($cached > 0) {
        return $cached;
    }
    $st = $conn->prepare('SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $fid = (int)($row['foundation_id'] ?? 0);
    if ($fid > 0) {
        $_SESSION['foundation_id'] = $fid;
    }
    return $fid;
}

/**
 * แจ้งเตือนเสริมมูลนิธิ (รอบสิ่งของ / อัปเดตผลลัพธ์เด็ก) — เรียกไม่บ่อย
 *
 * @return list<array{notif_id:int,title:string,message:string,link:string,created_at:string,is_read:int}>
 */
function drawdream_navbar_foundation_notification_extras(mysqli $conn, int $userId): array
{
    $extras = [];
    if ($userId <= 0) {
        return $extras;
    }

    require_once __DIR__ . '/notification_audit.php';
    require_once __DIR__ . '/donate_category_resolve.php';

    $fid = drawdream_navbar_foundation_id_for_user($conn, $userId);
    if ($fid <= 0) {
        return $extras;
    }

    $dismissNeedRoundAuto = (int)($_SESSION['dismiss_auto_need_round_open'] ?? 0) === 1;
    $dismissChildOutcomeAuto = (int)($_SESSION['dismiss_auto_child_outcome'] ?? 0) === 1;

    $lastSync = (int)($_SESSION['fdn_need_round_sync_at'] ?? 0);
    if (time() - $lastSync >= 1800) {
        $_SESSION['fdn_need_round_sync_at'] = time();

        $openRoundCount = 0;
        $stOpenRound = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM foundation_needlist
             WHERE foundation_id = ?
               AND approve_item = 'approved'
               AND donate_window_end_at IS NOT NULL
               AND donate_window_end_at > NOW()"
        );
        if ($stOpenRound) {
            $stOpenRound->bind_param('i', $fid);
            $stOpenRound->execute();
            $openRoundCount = (int)(($stOpenRound->get_result()->fetch_assoc()['cnt'] ?? 0));
        }

        $pendingNeedCount = 0;
        $stPendingNeed = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM foundation_needlist WHERE foundation_id = ? AND approve_item = 'pending'"
        );
        if ($stPendingNeed) {
            $stPendingNeed->bind_param('i', $fid);
            $stPendingNeed->execute();
            $pendingNeedCount = (int)(($stPendingNeed->get_result()->fetch_assoc()['cnt'] ?? 0));
        }

        $latestClosedNeed = null;
        $stClosedNeed = $conn->prepare(
            "SELECT donate_window_end_at
             FROM foundation_needlist
             WHERE foundation_id = ?
               AND approve_item = 'approved'
               AND donate_window_end_at IS NOT NULL
               AND donate_window_end_at <= NOW()
             ORDER BY donate_window_end_at DESC
             LIMIT 1"
        );
        if ($stClosedNeed) {
            $stClosedNeed->bind_param('i', $fid);
            $stClosedNeed->execute();
            $latestClosedNeed = $stClosedNeed->get_result()->fetch_assoc();
        }

        $closedNeedRaw = trim((string)($latestClosedNeed['donate_window_end_at'] ?? ''));
        if ($openRoundCount === 0 && $pendingNeedCount === 0 && $closedNeedRaw !== '') {
            $closedNeedTs = strtotime($closedNeedRaw);
            if ($closedNeedTs !== false) {
                $entityKey = 'fdn_need_round_open:' . date('YmdHis', $closedNeedTs);
                $titleNeedRound = 'ถึงเวลาเสนอรายการสิ่งของรอบใหม่';
                $linkNeedRound = 'foundation_add_need.php';
                if (!drawdream_notification_exists_for_user($conn, $userId, $titleNeedRound, $linkNeedRound) && !$dismissNeedRoundAuto) {
                    drawdream_send_notification(
                        $conn,
                        $userId,
                        'need_round_open',
                        $titleNeedRound,
                        'รอบก่อนหน้าปิดรับครบ 1 เดือนแล้ว ตอนนี้คุณสามารถเสนอรายการสิ่งของรอบใหม่ได้',
                        $linkNeedRound,
                        $entityKey
                    );
                }
            }
        } else {
            unset($_SESSION['dismiss_auto_need_round_open']);
        }
    }

    $childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
    $existExpr = "(EXISTS (SELECT 1 FROM donation d WHERE d.category_id = {$childCategoryId} AND d.target_id = c.child_id AND d.payment_status = 'completed' AND d.donor_id IS NOT NULL)
        OR EXISTS (SELECT 1 FROM child_subscription_history hs WHERE hs.child_id = c.child_id AND hs.current_status = 'active' AND hs.donor_user_id IS NOT NULL))";
    $stmtPending = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM foundation_children c
         WHERE c.foundation_id = ?
           AND ({$existExpr})
           AND COALESCE(TRIM(c.update_text), '') = ''
           AND COALESCE(NULLIF(c.update_images, ''), '[]') IN ('[]', '')"
    );
    $stmtFirstChild = $conn->prepare(
        "SELECT c.child_id
         FROM foundation_children c
         WHERE c.foundation_id = ?
           AND ({$existExpr})
           AND COALESCE(TRIM(c.update_text), '') = ''
           AND COALESCE(NULLIF(c.update_images, ''), '[]') IN ('[]', '')
         ORDER BY c.child_id ASC
         LIMIT 1"
    );
    if ($stmtPending) {
        $stmtPending->bind_param('i', $fid);
        $stmtPending->execute();
        $pendingCnt = (int)(($stmtPending->get_result()->fetch_assoc()['cnt'] ?? 0));
        if ($pendingCnt > 0 && !$dismissChildOutcomeAuto) {
            $firstChildId = 0;
            if ($stmtFirstChild) {
                $stmtFirstChild->bind_param('i', $fid);
                $stmtFirstChild->execute();
                $firstChildId = (int)(($stmtFirstChild->get_result()->fetch_assoc()['child_id'] ?? 0));
            }
            $outcomeLink = $firstChildId > 0
                ? 'foundation_child_outcome.php?id=' . $firstChildId
                : 'children_.php';
            $extras[] = [
                'notif_id' => 0,
                'title' => 'อัปเดตจดหมายเด็ก',
                'message' => "มีเด็กที่มีผู้อุปการะแล้ว {$pendingCnt} รายการ รออัปเดตจดหมาย",
                'link' => $outcomeLink,
                'created_at' => date('Y-m-d H:i:s'),
                'is_read' => 0,
            ];
        } elseif ($pendingCnt === 0) {
            unset($_SESSION['dismiss_auto_child_outcome']);
        }
    }

    return $extras;
}

/**
 * @param list<array{notif_id:int,title:string,message:string,link:string,created_at:string,is_read:int}> $notifs
 */
function drawdream_navbar_notification_list_html(array $notifs, string $navBase, ?mysqli $conn = null, int $userId = 0): string
{
    require_once __DIR__ . '/notification_audit.php';

    if ($notifs === []) {
        return '<div class="notif-empty">ยังไม่มีการแจ้งเตือน</div>';
    }

    $html = '';
    foreach ($notifs as $n) {
        $rawNotifLink = trim((string)($n['link'] ?? ''));
        if ($rawNotifLink === '') {
            $rawNotifLink = 'notifications.php';
        }
        $rawNotifLink = drawdream_normalize_notification_link(
            $rawNotifLink,
            (string)($n['title'] ?? ''),
            $conn,
            $userId
        );
        $notifIdNav = (int)($n['notif_id'] ?? 0);
        $isChildLetterNav = drawdream_is_child_letter_notification((string)($n['title'] ?? ''))
            && preg_match('~^/?children_donate\.php\?~i', $rawNotifLink);
        if ($notifIdNav > 0 && $isChildLetterNav) {
            $sepNav = str_contains($rawNotifLink, '?') ? '&' : '?';
            $destLink = $navBase . $rawNotifLink . $sepNav . 'notif_read=' . $notifIdNav;
        } else {
            $destLink = $navBase . $rawNotifLink;
        }
        $notifUnread = (int)($n['is_read'] ?? 0) === 0;
        $titleRaw = (string)($n['title'] ?? '');
        if ($titleRaw === 'อัปเดตผลลัพธ์เด็ก') {
            $titleRaw = 'อัปเดตจดหมายเด็ก';
        }
        $title = htmlspecialchars($titleRaw, ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars((string)($n['message'] ?? ''), ENT_QUOTES, 'UTF-8');
        $timeRaw = (string)($n['created_at'] ?? 'now');
        $timeLabel = htmlspecialchars(date('d/m/Y H:i', strtotime($timeRaw)), ENT_QUOTES, 'UTF-8');
        $destEsc = htmlspecialchars($destLink, ENT_QUOTES, 'UTF-8');
        $dataId = $notifIdNav > 0 ? ' data-notif-id="' . $notifIdNav . '"' : '';

        $html .= '<a href="' . $destEsc . '" class="notif-item' . ($notifUnread ? ' unread' : '') . '"'
            . $dataId . ' data-notif-link="' . $destEsc . '">'
            . '<div class="notif-item-title">' . $title . '</div>'
            . '<div class="notif-item-msg">' . $message . '</div>'
            . '<div class="notif-item-time">' . $timeLabel . '</div>'
            . '</a>';
    }

    return $html;
}

/**
 * @return array{notifs:list<array<string,mixed>>,count:int,html:string}
 */
function drawdream_navbar_notifications_bundle(mysqli $conn, int $userId, string $role, string $navBase, bool $withFoundationExtras = true): array
{
    $notifs = drawdream_navbar_notifications_from_db($conn, $userId, 10);
    if ($role === 'foundation' && $withFoundationExtras) {
        $extras = drawdream_navbar_foundation_notification_extras($conn, $userId);
        if ($extras !== []) {
            $notifs = array_merge($extras, $notifs);
        }
    }

    $count = drawdream_notifications_unread_count($conn, $userId);
    foreach ($notifs as $n) {
        if ((int)($n['notif_id'] ?? 0) === 0 && (int)($n['is_read'] ?? 0) === 0) {
            $count++;
        }
    }

    return [
        'notifs' => $notifs,
        'count' => $count,
        'html' => drawdream_navbar_notification_list_html($notifs, $navBase, $conn, $userId),
    ];
}

function drawdream_navbar_notifications_cache_bust(int $userId, string $role): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || $userId <= 0) {
        return;
    }
    $prefix = 'user_notifs_' . $role . '_' . $userId;
    if (!isset($_SESSION['drawdream_navbar_cache']) || !is_array($_SESSION['drawdream_navbar_cache'])) {
        return;
    }
    foreach (array_keys($_SESSION['drawdream_navbar_cache']) as $key) {
        if ($key === $prefix || $key === $prefix . '_feed') {
            unset($_SESSION['drawdream_navbar_cache'][$key]);
        }
    }
}
