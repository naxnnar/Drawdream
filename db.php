<?php
// ไฟล์นี้: db.php
// หน้าที่: ไฟล์เชื่อมต่อฐานข้อมูลกลางของระบบ
$conn = mysqli_connect("localhost", "root", "", "drawdream_db");

if (!$conn) {
    die("Connection failed");
}

require_once __DIR__ . '/includes/drawdream_project_status.php';
drawdream_normalize_foundation_project_statuses($conn);

require_once __DIR__ . '/includes/admin_audit_migrate.php';
drawdream_ensure_admin_audit_table($conn);
drawdream_admin_deduplicate_entity_rows($conn);

require_once __DIR__ . '/includes/drawdream_soft_delete.php';
drawdream_ensure_soft_delete_columns($conn);

require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
drawdream_ensure_needlist_schema($conn);

require_once __DIR__ . '/includes/notification_audit.php';
drawdream_notifications_migrate_legacy_on_boot($conn);
?>
