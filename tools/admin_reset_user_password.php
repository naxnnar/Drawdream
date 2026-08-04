<?php
/**
 * CLI: รีเซ็ตรหัสผ่านผู้ใช้โดยแอดมิน (กรณีอีเมลส่งไม่ถึง)
 *
 * Usage:
 *   php tools/admin_reset_user_password.php --email=user@example.com --password=abc123
 *   php tools/admin_reset_user_password.php --email=user@example.com --generate
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../includes/utf8_helpers.php';

$argv = $_SERVER['argv'] ?? [];
$email = '';
$password = '';
$generate = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $email = drawdream_normalize_email(substr($arg, 8));
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, 11);
    } elseif ($arg === '--generate') {
        $generate = true;
    }
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tools/admin_reset_user_password.php --email=user@example.com --password=abc123\n");
    fwrite(STDERR, "  php tools/admin_reset_user_password.php --email=user@example.com --generate\n");
    exit(1);
}

if ($generate) {
    try {
        $password = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $password = (string)random_int(100000, 999999);
    }
}

if ($password === '') {
    fwrite(STDERR, "Provide --password=... or --generate\n");
    exit(1);
}

if (!drawdream_password_meets_policy($password)) {
    fwrite(STDERR, drawdream_password_policy_message() . "\n");
    exit(1);
}

$st = $conn->prepare('SELECT user_id, email, role FROM `user` WHERE email = ? LIMIT 1');
$st->bind_param('s', $email);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
    fwrite(STDERR, "No user found for email: {$email}\n");
    exit(1);
}

$userId = (int)$row['user_id'];
$hashed = password_hash($password, PASSWORD_DEFAULT);

$upd = $conn->prepare('UPDATE `user` SET password = ? WHERE user_id = ?');
$upd->bind_param('si', $hashed, $userId);
if (!$upd->execute()) {
    fwrite(STDERR, 'Update failed: ' . $conn->error . "\n");
    exit(1);
}
$upd->close();

echo "Password reset OK\n";
echo 'user_id: ' . $userId . "\n";
echo 'email: ' . (string)$row['email'] . "\n";
echo 'role: ' . (string)$row['role'] . "\n";
echo "new_password: {$password}\n";
echo "Tell the user to log in at login.php and change the password after login.\n";
