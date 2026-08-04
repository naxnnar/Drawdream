<?php
/**
 * CLI: ทดสอบการส่งอีเมลรีเซ็ตรหัสผ่าน (SMTP / mail)
 *
 * Usage:
 *   php tools/check_password_reset_mail.php you@example.com
 *   php tools/check_password_reset_mail.php --config-only
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../includes/mail_transport.php';
require_once __DIR__ . '/../includes/password_reset.php';

$argv = $_SERVER['argv'] ?? [];
$configOnly = in_array('--config-only', $argv, true);
$to = '';
foreach ($argv as $i => $arg) {
    if ($i === 0 || $arg === '--config-only') {
        continue;
    }
    $to = trim((string)$arg);
    break;
}

$cfg = drawdream_mail_config();

echo "MAIL_TRANSPORT: {$cfg['transport']}\n";
echo 'SMTP configured: ' . (drawdream_mail_is_smtp_configured() ? 'yes' : 'no') . "\n";
echo "SMTP_HOST: " . ($cfg['host'] !== '' ? $cfg['host'] : '(empty)') . "\n";
echo "SMTP_PORT: {$cfg['port']}\n";
echo "SMTP_ENCRYPTION: {$cfg['encryption']}\n";
echo "SMTP_USER: " . ($cfg['user'] !== '' ? $cfg['user'] : '(empty)') . "\n";
echo "MAIL_FROM: {$cfg['from']}\n";
echo 'APP_URL: ' . (trim((string)(getenv('APP_URL') ?: '')) !== '' ? getenv('APP_URL') : drawdream_app_base_url()) . "\n";

if ($configOnly) {
    exit(0);
}

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/check_password_reset_mail.php you@example.com\n");
    exit(1);
}

$token = bin2hex(random_bytes(16));
echo "Sending test reset mail to {$to} ...\n";

$ok = drawdream_password_reset_send_mail($to, $token);
if ($ok) {
    echo "OK — check inbox (and spam) for DrawDream reset mail.\n";
    exit(0);
}

echo "FAILED — check PHP error_log and SMTP settings in .env\n";
exit(1);
