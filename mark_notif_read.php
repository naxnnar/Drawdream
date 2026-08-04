<?php
// mark_notif_read.php — ทำเครื่องหมายแจ้งเตือนอ่านแล้ว (ไม่ลบแถว)

declare(strict_types=1);

define('DRAWDREAM_DB_LIGHT', true);

include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/notification_audit.php';
require_once __DIR__ . '/includes/qr_payment_abandon.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('forbidden');
}

$uid = (int)$_SESSION['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mark_all'])) {
    if (!drawdream_csrf_verify()) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        exit('forbidden');
    }

    drawdream_notifications_mark_all_read($conn, $uid);
    $_SESSION['dismiss_auto_need_round_open'] = 1;
    $_SESSION['dismiss_auto_child_outcome'] = 1;
    require_once __DIR__ . '/includes/navbar_notifications.php';
    drawdream_navbar_notifications_cache_bust($uid, (string)($_SESSION['role'] ?? ''));

    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'count' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ref = drawdream_safe_payment_return_url(
        (string)($_POST['return'] ?? ''),
        (string)($_SERVER['HTTP_REFERER'] ?? 'notifications.php')
    );
    header('Location: ' . $ref);
    exit;
}

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if ($id > 0) {
        drawdream_notifications_mark_read($conn, $uid, $id);
    }

    $gotoRaw = trim((string)($_GET['goto'] ?? ''));
    if ($gotoRaw !== '') {
        $st = $conn->prepare('SELECT title, link FROM notifications WHERE notif_id = ? AND user_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('ii', $id, $uid);
            $st->execute();
            $nrow = $st->get_result()->fetch_assoc();
            if (is_array($nrow)) {
                $gotoRaw = drawdream_normalize_notification_link(
                    $gotoRaw !== '' ? $gotoRaw : (string)($nrow['link'] ?? ''),
                    (string)($nrow['title'] ?? ''),
                    $conn,
                    $uid
                );
            }
        }
        header('Location: ' . drawdream_safe_payment_return_url($gotoRaw, 'notifications.php'));
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo 'ok';
