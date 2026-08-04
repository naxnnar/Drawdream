<?php
declare(strict_types=1);

/** Migration รันได้เฉพาะ CLI — เปิดหน้าเว็บจะไม่รัน ALTER / SHOW COLUMNS ผ่าน schema_once */
function drawdream_schema_migrations_allowed(): bool
{
    return defined('DRAWDREAM_RUNNING_MIGRATIONS') && DRAWDREAM_RUNNING_MIGRATIONS === true;
}

/**
 * รัน schema migration แต่ละชุดได้ครั้งเดียวต่อ process — เฉพาะตอน tools/run_migrations.php
 */
function drawdream_schema_once(string $key, callable $runner, mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }

    static $done = [];
    if (isset($done[$key])) {
        return;
    }
    $runner($conn);
    $done[$key] = true;
}
