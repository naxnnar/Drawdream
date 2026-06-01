<?php
require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
require_once __DIR__ . '/includes/session_init.php';
drawdream_session_start();
header('Location: project.php?view=foundation&msg=' . rawurlencode('ปิดการใช้งานฟีเจอร์สมทบยอดโครงการแล้ว'));
exit();
