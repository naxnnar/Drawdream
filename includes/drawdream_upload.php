<?php
// includes/drawdream_upload.php — ขนาดอัปโหลด + ข้อความ error ภาษาไทย

if (!function_exists('drawdream_parse_ini_size')) {
    /**
     * แปลงค่า ini เช่น 2M, 8M, 512K เป็นจำนวนไบต์
     */
    function drawdream_parse_ini_size(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($value, -1));
        $num = (float)$value;
        if ($unit === 'g') {
            return (int)round($num * 1024 * 1024 * 1024);
        }
        if ($unit === 'm') {
            return (int)round($num * 1024 * 1024);
        }
        if ($unit === 'k') {
            return (int)round($num * 1024);
        }
        return (int)round((float)$value);
    }
}

if (!function_exists('drawdream_needlist_max_upload_bytes')) {
    /** ขนาดสูงสุดต่อไฟล์รูป needlist (ไม่เกิน 5MB และไม่เกิน limit ของ PHP) */
    function drawdream_needlist_max_upload_bytes(): int
    {
        $appCap = 5 * 1024 * 1024;
        $iniCap = drawdream_parse_ini_size((string)ini_get('upload_max_filesize'));
        $postCap = drawdream_parse_ini_size((string)ini_get('post_max_size'));
        return min($appCap, $iniCap, $postCap);
    }
}

if (!function_exists('drawdream_child_photo_max_upload_bytes')) {
    /** ขนาดสูงสุดต่อรูปโปรไฟล์เด็ก (บีบอัดก่อนอัปโหลด — ไม่เกิน 5MB) */
    function drawdream_child_photo_max_upload_bytes(): int
    {
        return drawdream_needlist_max_upload_bytes();
    }
}

if (!function_exists('drawdream_format_bytes_mb_label')) {
    function drawdream_format_bytes_mb_label(int $bytes): string
    {
        $mb = $bytes / (1024 * 1024);
        if ($mb >= 1 && abs($mb - round($mb)) < 0.05) {
            return (int)round($mb) . 'MB';
        }
        return rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.') . 'MB';
    }
}

if (!function_exists('drawdream_upload_error_message_th')) {
    function drawdream_upload_error_message_th(int $code, string $label = 'รูป'): string
    {
        $iniLimit = (string)ini_get('upload_max_filesize');
        $map = [
            UPLOAD_ERR_INI_SIZE => $label . 'ใหญ่เกินขีดจำกัดเซิร์ฟเวอร์ (' . $iniLimit . ') — รอให้เบราว์เซอร์บีบอัดเสร็จหรือเลือกรูปเล็กลง',
            UPLOAD_ERR_FORM_SIZE => $label . 'ใหญ่เกินที่ฟอร์มรองรับ',
            UPLOAD_ERR_PARTIAL => 'อัปโหลด' . $label . 'ไม่ครบ — ลองใหม่อีกครั้ง',
            UPLOAD_ERR_NO_FILE => 'ยังไม่ได้เลือก' . $label,
            UPLOAD_ERR_NO_TMP_DIR => 'เซิร์ฟเวอร์ไม่พร้อมรับไฟล์ชั่วคราว — แจ้งผู้ดูแลระบบ',
            UPLOAD_ERR_CANT_WRITE => 'บันทึก' . $label . 'ลงดิสก์ไม่สำเร็จ — แจ้งผู้ดูแลระบบ',
            UPLOAD_ERR_EXTENSION => 'ประเภทไฟล์ถูกปฏิเสธโดยเซิร์ฟเวอร์',
        ];
        if (isset($map[$code])) {
            return $map[$code];
        }
        return 'ข้อผิดพลาดอัปโหลด' . $label . ' (รหัส ' . $code . ')';
    }
}

if (!function_exists('drawdream_upload_is_image_tmp')) {
    /** ตรวจว่าไฟล์ชั่วคราวจากอัปโหลดเป็นรูป (รองรับชื่อไม่มีนามสกุล / HEIC) */
    function drawdream_upload_is_image_tmp(string $tmpPath, string $originalName): bool
    {
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return false;
        }
        if (@getimagesize($tmpPath) !== false) {
            return true;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $byExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp'];
        if (in_array($ext, $byExt, true)) {
            return true;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                if (is_string($mime) && strncmp($mime, 'image/', 6) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('drawdream_upload_resolve_image_ext')) {
    function drawdream_upload_resolve_image_ext(string $tmpPath, string $originalName): string
    {
        $info = @getimagesize($tmpPath);
        if ($info !== false) {
            $map = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                IMAGETYPE_WEBP => 'webp',
            ];
            if (isset($map[$info[2]])) {
                return $map[$info[2]];
            }
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            return 'jpg';
        }
        if ($ext !== '') {
            return $ext;
        }

        return 'jpg';
    }
}

if (!function_exists('drawdream_upload_needs_jpeg_output')) {
    function drawdream_upload_needs_jpeg_output(string $tmpPath, string $originalName, int $fileSize, int $maxBytes): bool
    {
        if ($fileSize > $maxBytes) {
            return true;
        }
        $ext = drawdream_upload_resolve_image_ext($tmpPath, $originalName);
        return in_array($ext, ['heic', 'heif', 'bmp'], true);
    }
}
