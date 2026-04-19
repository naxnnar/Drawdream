<?php
/**
 * คัดลอกไฟล์นี้เป็น google_oauth.local.php แล้วใส่ค่าจริงจาก Google Cloud Console
 * ห้าม commit google_oauth.local.php ขึ้น git (ไฟล์ถูก ignore แล้ว)
 */
declare(strict_types=1);

return [
    'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
    'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
    // ตัวอย่าง callback: http://localhost/drawdream/auth/google_callback.php
    'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
];
