<?php
// includes/welcome_session.php — ปลายทางหลัง login (เดิม welcome.php ถูกลบแล้ว)

declare(strict_types=1);

function drawdream_post_login_redirect_url(string $role): string
{
    if ($role === 'admin') {
        return 'admin_dashboard.php';
    }
    if ($role === 'foundation') {
        return 'foundation.php';
    }

    return 'homepage.php';
}

/** @deprecated ใช้ drawdream_post_login_redirect_url */
function drawdream_welcome_redirect_url_for_role(string $role): string
{
    return drawdream_post_login_redirect_url($role);
}
