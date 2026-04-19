<?php
// db.php — เชื่อมต่อ MySQL + bootstrap migration
/**
 * db.php — จุดเชื่อมต่อ MySQL กลางของ DrawDream
 *
 * ค่าการเชื่อมต่ออ่านจาก (ตามลำดับ):
 * 1) ไฟล์ config/db.local.php (แนะนำ — สำเนาจาก config/db.local.example.php)
 * 2) ตัวแปรสภาพแวดล้อม DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
 * 3) ค่าเริ่มต้นแบบ XAMPP เดิม (root ไร้รหัส + drawdream_db)
 *
 * @see docs/CODEBASE_FILE_INDEX.md | docs/SYSTEM_PRESENTATION_GUIDE.md
 */
declare(strict_types=1);

/** ให้ header() / redirect ทำงานได้แม้หน้าเพจเริ่มส่ง HTML แล้ว (เช่น โหมดดูมุมมองผู้บริจาคใน navbar) */
if (ob_get_level() === 0) {
    ob_start();
}

$dbLocalFile = __DIR__ . '/config/db.local.php';
if (is_file($dbLocalFile)) {
    /** @var array{host:string,port?:int,user:string,password:string,database:string} $dbConfig */
    $dbConfig = require $dbLocalFile;
} else {
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '',
        'database' => getenv('DB_NAME') ?: 'drawdream_db',
    ];
}

$host = (string)($dbConfig['host'] ?? 'localhost');
$port = (int)($dbConfig['port'] ?? 3306);
$user = (string)($dbConfig['user'] ?? 'root');
$password = (string)($dbConfig['password'] ?? '');
$database = (string)($dbConfig['database'] ?? 'drawdream_db');

$conn = mysqli_connect($host, $user, $password, $database, $port);

if (!$conn) {
    die('Connection failed: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8'));
}

mysqli_set_charset($conn, 'utf8mb4');

require_once __DIR__ . '/includes/drawdream_project_status.php';
drawdream_normalize_foundation_project_statuses($conn);

require_once __DIR__ . '/includes/admin_audit_migrate.php';
drawdream_ensure_admin_audit_table($conn);
drawdream_admin_deduplicate_entity_rows($conn);

require_once __DIR__ . '/includes/drawdream_soft_delete.php';
drawdream_ensure_soft_delete_columns($conn);

require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
drawdream_ensure_needlist_schema($conn);
drawdream_ensure_foundation_profile_needlist_result_columns($conn);

require_once __DIR__ . '/includes/drawdream_project_updates_schema.php';
drawdream_ensure_foundation_project_update_columns($conn);

require_once __DIR__ . '/includes/notification_audit.php';
drawdream_notifications_migrate_legacy_on_boot($conn);

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
