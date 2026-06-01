<?php
declare(strict_types=1);
// ใช้ทดสอบเท่านั้น: GET แล้วต้องไม่เห็น stack trace เมื่อ APP_ENV=production
putenv('APP_ENV=production');
require_once dirname(__DIR__) . '/includes/env_loader.php';
drawdream_load_env_file(dirname(__DIR__) . '/.env');
require_once dirname(__DIR__) . '/includes/session_init.php';
drawdream_apply_production_error_display();
trigger_error('drawdream_test_error_display', E_USER_NOTICE);
echo 'ok';
