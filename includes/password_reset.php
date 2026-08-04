<?php
declare(strict_types=1);

require_once __DIR__ . '/password_reset_schema.php';
require_once __DIR__ . '/password_policy.php';
require_once __DIR__ . '/utf8_helpers.php';
require_once __DIR__ . '/mail_transport.php';

function drawdream_app_base_url(): string
{
    $env = trim((string)(getenv('APP_URL') ?: ''));
    if ($env !== '') {
        return rtrim($env, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

    return ($https ? 'https' : 'http') . '://' . $host;
}

function drawdream_password_reset_create(mysqli $conn, int $userId): string
{
    drawdream_password_reset_ensure_schema($conn);

    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $expires = date('Y-m-d H:i:s', time() + 3600);

    $st = $conn->prepare(
        'UPDATE password_reset_token SET used_at = NOW()
         WHERE user_id = ? AND used_at IS NULL'
    );
    $st->bind_param('i', $userId);
    $st->execute();
    $st->close();

    $stIns = $conn->prepare(
        'INSERT INTO password_reset_token (user_id, token_hash, expires_at)
         VALUES (?, ?, ?)'
    );
    $stIns->bind_param('iss', $userId, $hash, $expires);
    $stIns->execute();
    $stIns->close();

    return $plain;
}

/**
 * @return array{user_id:int,email:string}|null
 */
function drawdream_password_reset_lookup(mysqli $conn, string $plainToken): ?array
{
    drawdream_password_reset_ensure_schema($conn);

    $plain = trim($plainToken);
    if ($plain === '' || !preg_match('/^[a-f0-9]{64}$/', $plain)) {
        return null;
    }

    $hash = hash('sha256', $plain);
    $st = $conn->prepare(
        'SELECT t.user_id, u.email
         FROM password_reset_token t
         INNER JOIN `user` u ON u.user_id = t.user_id
         WHERE t.token_hash = ?
           AND t.used_at IS NULL
           AND t.expires_at > NOW()
         LIMIT 1'
    );
    $st->bind_param('s', $hash);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        return null;
    }

    return [
        'user_id' => (int)$row['user_id'],
        'email' => (string)$row['email'],
    ];
}

function drawdream_password_reset_apply(mysqli $conn, string $plainToken, string $newPassword): bool
{
    $lookup = drawdream_password_reset_lookup($conn, $plainToken);
    if ($lookup === null || !drawdream_password_meets_policy($newPassword)) {
        return false;
    }

    $hash = hash('sha256', $plainToken);
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $userId = $lookup['user_id'];

    $st = $conn->prepare('UPDATE `user` SET password = ? WHERE user_id = ?');
    $st->bind_param('si', $hashedPassword, $userId);
    $ok = $st->execute();
    $st->close();
    if (!$ok) {
        return false;
    }

    $stUse = $conn->prepare(
        'UPDATE password_reset_token SET used_at = NOW()
         WHERE token_hash = ? AND used_at IS NULL'
    );
    $stUse->bind_param('s', $hash);
    $stUse->execute();
    $stUse->close();

    return true;
}

function drawdream_password_reset_send_mail(string $email, string $plainToken): bool
{
    $resetUrl = drawdream_app_base_url() . '/auth/reset_password.php?token=' . rawurlencode($plainToken);
    $subject = 'DrawDream — รีเซ็ตรหัสผ่าน';
    $body = "สวัสดีครับ/ค่ะ\n\n"
        . "เราได้รับคำขอรีเซ็ตรหัสผ่านสำหรับบัญชี DrawDream ของคุณ\n\n"
        . "กดลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่ (ใช้ได้ภายใน 1 ชั่วโมง):\n"
        . $resetUrl . "\n\n"
        . "หากคุณไม่ได้ขอรีเซ็ตรหัสผ่าน สามารถละเว้นอีเมลนี้ได้\n\n"
        . "DrawDream";

    $result = drawdream_mail_send($email, $subject, $body);
    if (!$result['ok']) {
        error_log('DrawDream password reset mail failed (' . $result['transport'] . '): ' . $result['error']);
    }

    return $result['ok'];
}

/** @return 'sent'|'no_account'|'mail_failed' */
function drawdream_password_reset_request_by_email(mysqli $conn, string $email): string
{
    $email = drawdream_normalize_email($email);
    if ($email === '') {
        return 'no_account';
    }

    $st = $conn->prepare('SELECT user_id FROM `user` WHERE email = ? LIMIT 1');
    $st->bind_param('s', $email);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        return 'no_account';
    }

    $token = drawdream_password_reset_create($conn, (int)$row['user_id']);
    if (drawdream_password_reset_send_mail($email, $token)) {
        return 'sent';
    }

    return 'mail_failed';
}
