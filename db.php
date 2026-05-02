<?php
// db.php — เชื่อมต่อ MySQL + bootstrap migration
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน db
/**
 * db.php — จุดเชื่อมต่อ MySQL กลางของ DrawDream
 *
 * ค่าการเชื่อมต่ออ่านจาก (ตามลำดับ):
 * 1) ไฟล์ config/db.local.php (แนะนำ — สำเนาจาก config/db.local.example.php)
 * 2) ตัวแปรสภาพแวดล้อม DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
 * 3) ค่าเริ่มต้นแบบ XAMPP เดิม (root ไร้รหัส + drawdream_db)
 *
 * @see README.md
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');

/** ให้ header() / redirect ทำงานได้แม้หน้าเพจเริ่มส่ง HTML แล้ว (เช่น โหมดดูมุมมองผู้บริจาคใน navbar) */
if (ob_get_level() === 0) {
    ob_start();
}

$dbLocalFile = __DIR__ . '/config/db.local.php';
if (is_file($dbLocalFile)) {
    /** @var array{host:string,port?:int,user:string,password:string,database:string} $dbConfig */
    $dbConfig = require $dbLocalFile;
} else {
    $envPass = getenv('DB_PASSWORD');
    if ($envPass === false || $envPass === '') {
        $envPass = getenv('AIVEN_PASSWORD');
    }
    $dbConfig = [
        // ค่า default สำหรับ Aiven Cloud (override ได้ด้วย env vars)
        'host' => getenv('DB_HOST') ?: (getenv('AIVEN_HOST') ?: 'mysql-17ffeb44-drawdream.c.aivencloud.com'),
        'port' => (int)(getenv('DB_PORT') ?: (getenv('AIVEN_PORT') ?: 21503)),
        'user' => getenv('DB_USER') ?: (getenv('AIVEN_USER') ?: 'avnadmin'),
        'password' => $envPass !== false && $envPass !== '' ? (string)$envPass : '',
        'database' => getenv('DB_NAME') ?: (getenv('AIVEN_DB') ?: 'defaultdb'),
    ];
}

$host = (string)($dbConfig['host'] ?? 'localhost');
$port = (int)($dbConfig['port'] ?? 3306);
$user = (string)($dbConfig['user'] ?? 'root');
$password = (string)($dbConfig['password'] ?? '');
$database = (string)($dbConfig['database'] ?? 'drawdream_db');

if (function_exists('mysqli_init') && function_exists('mysqli_real_connect')) {
    $connInit = mysqli_init();
    if (!$connInit) {
        die('Connection failed: cannot initialize MySQL client');
    }

    $sslCa = trim((string)(getenv('DB_SSL_CA') !== false ? getenv('DB_SSL_CA') : ''));
    $sslMode = strtolower(trim((string)(getenv('DB_SSL_MODE') !== false ? getenv('DB_SSL_MODE') : 'require')));
    $useSsl = ($sslMode !== 'disable') || str_contains($host, 'aivencloud.com');
    $sslFlags = 0;
    if ($useSsl) {
        if ($sslCa !== '' && is_file($sslCa)) {
            mysqli_ssl_set($connInit, null, null, $sslCa, null, null);
        } elseif (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
            $sslFlags = MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
        }
    }

    $connOk = @mysqli_real_connect(
        $connInit,
        $host,
        $user,
        $password,
        $database,
        $port,
        null,
        $sslFlags
    );
    if (!$connOk) {
        $hint = $password === '' ? ' (DB_PASSWORD/AIVEN_PASSWORD ยังว่าง)' : '';
        die('Connection failed: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8') . $hint);
    }
    $conn = $connInit;
} else {
    $conn = mysqli_connect($host, $user, $password, $database, $port);
    if (!$conn) {
        $hint = $password === '' ? ' (DB_PASSWORD/AIVEN_PASSWORD ยังว่าง)' : '';
        die('Connection failed: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8') . $hint);
    }
}

mysqli_set_charset($conn, 'utf8mb4');

require_once __DIR__ . '/includes/drawdream_project_status.php';
require_once __DIR__ . '/includes/admin_audit_migrate.php';
require_once __DIR__ . '/includes/drawdream_soft_delete.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/includes/drawdream_project_updates_schema.php';
require_once __DIR__ . '/includes/notification_audit.php';

// Migration cache — รันแค่ครั้งแรกหรือทุก 1 ชั่วโมง เพื่อไม่ให้ยิง SHOW COLUMNS ทุก request ไปยัง cloud DB
$_ddMigrationCache = __DIR__ . '/config/migration_done.txt';
$_ddMigrationTtl   = 3600; // วินาที
$_ddNeedMigration  = !file_exists($_ddMigrationCache)
    || (time() - (int)filemtime($_ddMigrationCache)) > $_ddMigrationTtl;

if ($_ddNeedMigration) {
    drawdream_normalize_foundation_project_statuses($conn);
    drawdream_ensure_admin_audit_table($conn);
    drawdream_admin_deduplicate_entity_rows($conn);
    drawdream_ensure_soft_delete_columns($conn);
    drawdream_ensure_needlist_schema($conn);
    drawdream_ensure_foundation_profile_needlist_result_columns($conn);
    drawdream_ensure_foundation_project_update_columns($conn);
    drawdream_notifications_migrate_legacy_on_boot($conn);

    @file_put_contents($_ddMigrationCache, date('Y-m-d H:i:s'));
}
unset($_ddMigrationCache, $_ddMigrationTtl, $_ddNeedMigration);

if (!function_exists('drawdream_project_image_storage_path')) {
    /**
     * คืนค่า path สัมพัทธ์ใต้โฟลเดอร์ uploads สำหรับรูปโครงการ
     * รองรับทั้งข้อมูลเก่า (uploads/) และใหม่ (uploads/project/)
     */
    function drawdream_project_image_storage_path(?string $rawPath): string
    {
        $raw = trim((string)$rawPath);
        if ($raw === '') {
            return '';
        }

        $raw = str_replace('\\', '/', $raw);
        $base = basename($raw);
        if ($base === '') {
            return '';
        }

        $uploadsRoot = __DIR__ . '/uploads';
        $candidates = [];

        $rawCandidate = ltrim($raw, '/');
        if ($rawCandidate !== '') {
            $candidates[] = $rawCandidate;
        }
        $candidates[] = 'project/' . $base;
        $candidates[] = $base;

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $fullPath = $uploadsRoot . '/' . $candidate;
            if (is_file($fullPath)) {
                return str_replace('\\', '/', $candidate);
            }
        }

        return 'project/' . $base;
    }
}

if (!function_exists('drawdream_project_image_url')) {
    /**
     * คืนค่า URL สำหรับแสดงรูปโครงการ
     * ตัวอย่าง prefix: "uploads/" หรือ "../uploads/"
     */
    function drawdream_project_image_url(?string $rawPath, string $prefix = 'uploads/'): string
    {
        $rel = drawdream_project_image_storage_path($rawPath);
        if ($rel === '') {
            return '';
        }

        return rtrim($prefix, '/') . '/' . ltrim($rel, '/');
    }
}
