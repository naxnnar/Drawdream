<?php
// notifications.php — ประวัติการแจ้งเตือนทั้งหมด (ผู้บริจาค + มูลนิธิ)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/admin_audit_migrate.php';

$role = $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['foundation', 'donor'], true)) {
    header('Location: index.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

if (isset($_GET['mark_read'])) {
    $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
    }
    header('Location: notifications.php');
    exit();
}

$result = mysqli_query(
    $conn,
    'SELECT * FROM notifications WHERE user_id = ' . (int)$uid . ' ORDER BY created_at DESC LIMIT 200'
);

$unreadRow = mysqli_fetch_assoc(
    mysqli_query($conn, 'SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ' . (int)$uid . ' AND is_read = 0')
);
$unread_count = (int)($unreadRow['cnt'] ?? 0);

?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

        .btn-markall {
            padding: 8px 20px;
            background: white;
            border: 2px solid #4A5BA8;
            border-radius: 20px;
            color: #4A5BA8;
            font-family: 'Prompt', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-markall:hover { background: #4A5BA8; color: white; }

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

        .notif-card.unread {
            border-left-color: #4A5BA8;
            background: #f0f4ff;
        }

        .notif-card.type-project_funded { border-left-color: #4CAF50; }
        .notif-card.unread.type-project_funded { background: #f0fff4; }

        .notif-card.type-needlist_done { border-left-color: #FF9800; }
        .notif-card.unread.type-needlist_done { background: #fff8f0; }

        .notif-card.type-broadcast { border-left-color: #5c6bc0; }
        .notif-card.unread.type-broadcast { background: #eef1ff; }

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

        .notif-dot {
            width: 10px;
            height: 10px;
            background: #4A5BA8;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 6px;
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
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="wrap">
    <div class="page-header">
        <div class="page-title">
            🔔 การแจ้งเตือน
            <?php if ($unread_count > 0): ?>
                <span class="badge-unread"><?= (int)$unread_count ?> ใหม่</span>
            <?php endif; ?>
        </div>
        <?php if ($unread_count > 0): ?>
            <a href="?mark_read=all" class="btn-markall">อ่านทั้งหมดแล้ว</a>
        <?php endif; ?>
    </div>
    <p class="page-sub">แสดงประวัติล่าสุดไม่เกิน 200 รายการ</p>

    <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <div class="notif-list">
        <?php while ($row = mysqli_fetch_assoc($result)):
            $is_unread = !(bool)$row['is_read'];
            $type = $row['type'] ?? '';
            $typeBucket = drawdream_normalize_notif_type_to_th($type);
            $icon = match ($typeBucket) {
                'อนุมัติ' => '✅',
                'ไม่อนุมัติ' => '⛔',
                'กำลังรอดำเนินการ' => '⏳',
                'ประกาศจากผู้ดูแลระบบ' => '📣',
                default => '🔔',
            };
            $typeClass = match ($typeBucket) {
                'อนุมัติ' => 'approved',
                'ไม่อนุมัติ' => 'rejected',
                'กำลังรอดำเนินการ' => 'pending',
                'ประกาศจากผู้ดูแลระบบ' => 'broadcast',
                default => 'other',
            };
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

            $rawLink = trim((string)($row['link'] ?? ''));
            if ($rawLink !== ''
                && ($row['title'] ?? '') === 'อัปเดตผลลัพธ์เด็กที่คุณอุปการะ'
                && preg_match('#^children_donate\.php\?#', $rawLink)
                && strpos($rawLink, 'view=outcome') === false) {
                $rawLink .= (str_contains($rawLink, '?') ? '&' : '?') . 'view=outcome';
            }
            $cardClasses = 'notif-card ' . ($is_unread ? 'unread' : '') . ' type-' . htmlspecialchars($typeClass, ENT_QUOTES, 'UTF-8');
        ?>
            <?php if ($rawLink !== ''): ?>
            <a href="<?= htmlspecialchars($rawLink, ENT_QUOTES, 'UTF-8') ?>" class="<?= $cardClasses ?>">
            <?php else: ?>
            <div class="<?= $cardClasses ?>">
            <?php endif; ?>
                <div class="notif-icon"><?= $icon ?></div>
                <div class="notif-body">
                    <div class="notif-title"><?= htmlspecialchars((string)$row['title']) ?></div>
                    <div class="notif-message"><?= htmlspecialchars((string)$row['message']) ?></div>
                    <div class="notif-time"><?= htmlspecialchars($time_diff) ?></div>
                </div>
                <?php if ($is_unread): ?>
                    <div class="notif-dot"></div>
                <?php endif; ?>
            <?php if ($rawLink !== ''): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
        <?php endwhile; ?>
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
