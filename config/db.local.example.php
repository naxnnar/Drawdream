<?php
/**
 * คัดลอกเป็น db.local.php แล้วแก้ค่าให้ตรงกับ MySQL ของคุณ
 * ไฟล์ db.local.php ถูก gitignore — อย่า commit รหัสผ่าน
 */
declare(strict_types=1);

return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'database' => getenv('DB_NAME') ?: 'drawdream_db',
];
