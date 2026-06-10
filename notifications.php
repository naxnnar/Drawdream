<?php
// notifications.php — ประวัติการแจ้งเตือนทั้งหมด (ผู้บริจาค + มูลนิธิ)

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน notifications

include 'db.php';
require_once __DIR__ . '/includes/admin_audit_migrate.php';
require_once __DIR__ . '/includes/notification_audit.php';
drawdream_ensure_notifications_table($conn);

$role = $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['foundation', 'donor'], true)) {
    header('Location: index.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

if (isset($_GET['mark_all'])) {
    drawdream_notifications_mark_all_read($conn, $uid);
    $_SESSION['dismiss_auto_need_round_open'] = 1;
    $_SESSION['dismiss_auto_child_outcome'] = 1;
    header('Location: notifications.php');
    exit;
}

$notif_rows = [];
$listSt = $conn->prepare(
    'SELECT notif_id, title, message, link, created_at, is_read
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 200'
);
if ($listSt) {
    $listSt->bind_param('i', $uid);
    $listSt->execute();
    $res = $listSt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $notif_rows[] = $row;
    }
}

$notif_count = count($notif_rows);
$unread_count = drawdream_notifications_unread_count($conn, $uid);

?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>การแจ้งเตือน | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&family=Sarabun:wght@400;500&display=swap');

        body { background: #F7ECDE; font-family: 'Prompt', sans-serif; margin: 0; }

        .wrap {
            max-width: 760px;
            margin: 36px auto;
            padding: 0 20px 60px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .mark-all-link {
            font-size: 13px;
            font-weight: 600;
            color: #4A5BA8;
            text-decoration: none;
            white-space: nowrap;
        }
        .mark-all-link:hover { text-decoration: underline; }

        .notif-card--unread {
            border-left-width: 5px;
            border-left-color: #4A5BA8;
            background: #f5f8ff;
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: #333;
        }

        .page-sub {
            font-size: 14px;
            color: #888;
            margin-bottom: 24px;
            font-family: 'Sarabun', sans-serif;
        }

        .badge-unread {
            background: #E74C3C;
            color: white;
            font-size: 12px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            margin-left: 10px;
            font-family: 'Sarabun', sans-serif;
        }

        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .notif-card {
            background: white;
            border-radius: 14px;
            padding: 18px 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            gap: 16px;
            align-items: flex-start;
            border-left: 5px solid #ddd;
            transition: all 0.2s;
        }

        a.notif-card {
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }

        a.notif-card:hover {
            box-shadow: 0 4px 14px rgba(0,0,0,0.1);
        }

        .notif-card.notif--approved { border-left-color: #4CAF50; background: #f0fff4; }
        .notif-card.notif--success { border-left-color: #4CAF50; background: #f0fff4; }
        .notif-card.notif--rejected { border-left-color: #e53935; background: #fff5f5; }
        .notif-card.notif--pending { border-left-color: #FF9800; background: #fff8f0; }
        .notif-card.notif--broadcast { border-left-color: #5c6bc0; background: #eef1ff; }
        .notif-card.notif--letter { border-left-color: #3c5099; background: #f5f8ff; }

        .notif-icon {
            font-size: 28px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .notif-body { flex: 1; min-width: 0; }

        .notif-title {
            font-size: 15px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }

        .notif-message {
            font-size: 13px;
            color: #666;
            font-family: 'Sarabun', sans-serif;
            line-height: 1.6;
            margin-bottom: 8px;
        }

        .notif-time {
            font-size: 12px;
            color: #bbb;
            font-family: 'Sarabun', sans-serif;
        }

        .empty {
            text-align: center;
            padding: 60px 20px;
            color: #ccc;
            font-size: 15px;
            background: white;
            border-radius: 14px;
        }

        .empty-icon { font-size: 48px; margin-bottom: 12px; }

        @media (max-width: 480px) {
            .wrap { padding: 0 12px 40px; margin: 16px auto; }
            .page-title { font-size: 18px; }
            .notif-card { padding: 12px 14px; gap: 10px; }
            .notif-icon { font-size: 22px; }
            .notif-title { font-size: 14px; }
            .notif-message { font-size: 12px; }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="wrap">
    <div class="page-header">
        <div class="page-title">
            🔔 การแจ้งเตือน
            <?php if ($unread_count > 0): ?>
                <span class="badge-unread"><?= (int)$unread_count ?> ยังไม่อ่าน</span>
            <?php endif; ?>
        </div>
        <?php if ($unread_count > 0): ?>
            <a href="notifications.php?mark_all=1" class="mark-all-link">ทำเครื่องหมายว่าอ่านทั้งหมด</a>
        <?php endif; ?>
    </div>
    <p class="page-sub">แจ้งเตือนในระบบ (กระดิ่ง) — ไม่ส่งอีเมลอัตโนมัติ แสดงประวัติล่าสุดไม่เกิน 200 รายการ</p>

    <?php if ($notif_count > 0): ?>
        <div class="notif-list">
        <?php foreach ($notif_rows as $row):
            $cardMeta = drawdream_notification_card_meta(
                (string)($row['title'] ?? ''),
                (string)($row['link'] ?? '')
            );
            $icon = $cardMeta['icon'];
            $typeClass = $cardMeta['class'];
            $created = strtotime($row['created_at']);
            $diff = time() - (int)$created;
            if ($diff < 60) {
                $time_diff = 'เมื่อกี้';
            } elseif ($diff < 3600) {
                $time_diff = floor($diff / 60) . ' นาทีที่แล้ว';
            } elseif ($diff < 86400) {
                $time_diff = floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
            } else {
                $time_diff = date('d/m/Y H:i', (int)$created);
            }

            $rawLink = drawdream_normalize_child_donate_notification_link(
                trim((string)($row['link'] ?? '')),
                (string)($row['title'] ?? '')
            );
            $isUnread = (int)($row['is_read'] ?? 0) === 0;
            $cardClasses = 'notif-card notif--' . htmlspecialchars($typeClass, ENT_QUOTES, 'UTF-8')
                . ($isUnread ? ' notif-card--unread' : '');
            $notifIdRow = (int)($row['notif_id'] ?? 0);
            $cardHref = $rawLink;
            if ($notifIdRow > 0 && $rawLink !== '') {
                $isChildLetter = drawdream_is_child_letter_notification((string)($row['title'] ?? ''))
                    && preg_match('~^/?children_donate\.php\?~i', $rawLink);
                if ($isChildLetter) {
                    $sep = str_contains($rawLink, '?') ? '&' : '?';
                    $cardHref = $rawLink . $sep . 'notif_read=' . $notifIdRow;
                } else {
                    $cardHref = 'mark_notif_read.php?id=' . $notifIdRow . '&goto=' . rawurlencode($rawLink);
                }
            }
        ?>
            <?php if ($cardHref !== ''): ?>
            <a href="<?= htmlspecialchars($cardHref, ENT_QUOTES, 'UTF-8') ?>" class="<?= $cardClasses ?>">
            <?php else: ?>
            <div class="<?= $cardClasses ?>">
            <?php endif; ?>
                <div class="notif-icon"><?= $icon ?></div>
                <div class="notif-body">
                    <div class="notif-title"><?= htmlspecialchars((string)$row['title']) ?></div>
                    <div class="notif-message"><?= htmlspecialchars((string)$row['message']) ?></div>
                    <div class="notif-time"><?= htmlspecialchars($time_diff) ?></div>
                </div>
            <?php if ($cardHref !== ''): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty">
            <div class="empty-icon">🔕</div>
            ยังไม่มีการแจ้งเตือน
        </div>
    <?php endif; ?>
</div>
</body>
</html>
