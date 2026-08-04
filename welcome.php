<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/welcome_session.php';

drawdream_session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ' . drawdream_post_login_redirect_url((string)($_SESSION['role'] ?? '')));
    exit;
}

header('Location: login.php');
exit;
