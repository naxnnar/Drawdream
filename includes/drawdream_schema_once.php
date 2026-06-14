<?php
declare(strict_types=1);

/**
 * รัน schema migration แต่ละชุดได้ครั้งเดียวต่อ HTTP request (ลด SHOW COLUMNS ซ้ำไป Aiven)
 */
function drawdream_schema_once(string $key, callable $runner, mysqli $conn): void
{
    static $done = [];
    if (isset($done[$key])) {
        return;
    }
    $runner($conn);
    $done[$key] = true;
}
