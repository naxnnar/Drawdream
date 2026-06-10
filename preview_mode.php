<?php
// preview_mode.php — สลับโหมดมุมมองผู้บริจาค / ออกกลับมูลนิธิ (ไม่พึ่ง navbar — ใช้ได้จากทุกหน้า)
declare(strict_types=1);

require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
require_once __DIR__ . '/includes/session_init.php';
drawdream_session_start();
require_once __DIR__ . '/includes/foundation_donor_preview.php';

drawdream_foundation_preview_process_request();

header('Location: foundation.php');
exit;
