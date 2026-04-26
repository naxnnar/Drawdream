<?php
declare(strict_types=1);

// includes/env_loader.php — โหลดค่า .env แบบเบาๆ สำหรับ local dev
if (!function_exists('drawdream_load_env_file')) {
    /**
     * โหลดไฟล์ .env แล้ว putenv / $_ENV / $_SERVER
     * - รองรับรูปแบบ KEY=VALUE
     * - ข้ามบรรทัดว่างและคอมเมนต์ (# ...)
     * - ไม่ทับค่าที่มีอยู่แล้วใน environment
     */
    function drawdream_load_env_file(?string $envPath = null): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $path = $envPath ?: (__DIR__ . '/../.env');
        if (!is_file($path)) {
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false || $eqPos < 1) {
                continue;
            }
            $key = trim(substr($line, 0, $eqPos));
            $val = trim(substr($line, $eqPos + 1));
            if ($key === '') {
                continue;
            }

            if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
                || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }

            if (getenv($key) !== false) {
                continue;
            }
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

