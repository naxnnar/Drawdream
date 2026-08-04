<?php
// includes/payment_bootstrap.php — bootstrap เบาสำหรับ payment/* (ไม่รัน migration batch ทุกครั้ง)
declare(strict_types=1);

if (!defined('DRAWDREAM_DB_LIGHT')) {
    define('DRAWDREAM_DB_LIGHT', true);
}

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/payment_transaction_schema.php';
require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/donate_type.php';
