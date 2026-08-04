<?php
declare(strict_types=1);

require_once __DIR__ . '/return_to.php';
require_once __DIR__ . '/welcome_session.php';
require_once __DIR__ . '/user_activity_tracking.php';
require_once __DIR__ . '/session_init.php';

function drawdream_auth_finish_donor_login(
    mysqli $conn,
    int $userId,
    string $email,
    string $via = 'register'
): never {
    drawdream_session_regenerate_after_login();
    if (function_exists('drawdream_auth_csrf_rotate')) {
        drawdream_auth_csrf_rotate();
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = 'donor';
    drawdream_log_user_login($conn, $userId, $via);
    $returnDest = drawdream_return_to_consume_after_login('donor');
    if ($returnDest !== null) {
        header('Location: ' . $returnDest);
    } else {
        header('Location: ' . drawdream_post_login_redirect_url('donor'));
    }
    exit;
}

function drawdream_auth_ensure_donor_row(
    mysqli $conn,
    int $userId,
    string $firstName,
    string $lastName
): void {
    $st = $conn->prepare('SELECT user_id FROM donor WHERE user_id = ? LIMIT 1');
    if (!$st) {
        return;
    }
    $st->bind_param('i', $userId);
    $st->execute();
    if ($st->get_result()->fetch_assoc()) {
        return;
    }
    $st2 = $conn->prepare('INSERT INTO donor (user_id, first_name, last_name) VALUES (?, ?, ?)');
    if (!$st2) {
        return;
    }
    $st2->bind_param('iss', $userId, $firstName, $lastName);
    $st2->execute();
}

function drawdream_auth_email_in_use_message(): string
{
    return 'อีเมลนี้ถูกใช้งานแล้ว หากเคยสมัครหรือเข้าสู่ระบบด้วย Google แล้ว ให้กด เข้าสู่ระบบ ด้านล่าง หรือใช้ ลืมรหัสผ่าน';
}

/**
 * @return array{ok:bool,error?:string}
 */
function drawdream_auth_register_donor(
    mysqli $conn,
    string $email,
    string $password,
    string $firstName,
    string $lastName
): array {
    $st = $conn->prepare('SELECT user_id, password, role FROM `user` WHERE email = ? LIMIT 1');
    if (!$st) {
        return ['ok' => false, 'error' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
    }
    $st->bind_param('s', $email);
    $st->execute();
    $existing = $st->get_result()->fetch_assoc();

    if ($existing) {
        $role = (string)($existing['role'] ?? '');
        if ($role !== 'donor') {
            return ['ok' => false, 'error' => drawdream_auth_email_in_use_message()];
        }
        $hash = (string)($existing['password'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return ['ok' => false, 'error' => drawdream_auth_email_in_use_message()];
        }
        $userId = (int)($existing['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['ok' => false, 'error' => drawdream_auth_email_in_use_message()];
        }
        drawdream_auth_ensure_donor_row($conn, $userId, $firstName, $lastName);
        drawdream_auth_finish_donor_login($conn, $userId, $email, 'register_existing');
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stIns = $conn->prepare("INSERT INTO `user` (email, password, role) VALUES (?, ?, 'donor')");
    if (!$stIns) {
        return ['ok' => false, 'error' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'];
    }
    $stIns->bind_param('ss', $email, $hashedPassword);
    if (!$stIns->execute()) {
        if ((int)$conn->errno === 1062) {
            return drawdream_auth_register_donor($conn, $email, $password, $firstName, $lastName);
        }

        return ['ok' => false, 'error' => 'เกิดข้อผิดพลาด: ' . $stIns->error];
    }

    $userId = (int)$conn->insert_id;
    drawdream_auth_ensure_donor_row($conn, $userId, $firstName, $lastName);
    drawdream_auth_finish_donor_login($conn, $userId, $email, 'register');
}
