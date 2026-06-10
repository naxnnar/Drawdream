<?php
declare(strict_types=1);

/**
 * จุดเข้าเว็บราก — เปิด https://โดเมน/ แล้วไปหน้าหลักสาธารณะ
 * ตั้ง DirectoryIndex เป็น index.php บน Apache/Nginx หรือใช้ไฟล์นี้โดยตรง
 */
header('Location: homepage.php', true, 302);
exit;
