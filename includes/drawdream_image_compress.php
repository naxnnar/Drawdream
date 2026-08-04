<?php
// includes/drawdream_image_compress.php — บีบอัดรูปด้วย GD ก่อนบันทึก

if (!function_exists('drawdream_image_load_gd')) {
    /**
     * @return \GdImage|resource|null
     */
    function drawdream_image_load_gd(string $path)
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                return @imagecreatefromjpeg($path) ?: null;
            case IMAGETYPE_PNG:
                $img = @imagecreatefrompng($path);
                if ($img) {
                    imagealphablending($img, true);
                    imagesavealpha($img, true);
                }
                return $img ?: null;
            case IMAGETYPE_GIF:
                return @imagecreatefromgif($path) ?: null;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    return @imagecreatefromwebp($path) ?: null;
                }
                return null;
            default:
                return null;
        }
    }
}

if (!function_exists('drawdream_image_jpeg_bytes')) {
    /**
     * @param \GdImage|resource $canvas
     */
    function drawdream_image_jpeg_bytes($canvas, int $quality): ?string
    {
        ob_start();
        $ok = imagejpeg($canvas, null, max(20, min(95, $quality)));
        $data = ob_get_clean();
        if (!$ok || $data === false || $data === '') {
            return null;
        }
        return $data;
    }
}

if (!function_exists('drawdream_store_compressed_upload')) {
    /**
     * บันทึกไฟล์อัปโหลด — บีบอัดเป็น JPEG ถ้าใหญ่เกิน maxBytes
     */
    function drawdream_store_compressed_upload(string $tmpPath, string $destPath, int $maxBytes, bool $forceReencode = false): bool
    {
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return false;
        }

        $size = @filesize($tmpPath);
        if ($size === false) {
            return false;
        }

        if (!$forceReencode && $size <= $maxBytes) {
            return move_uploaded_file($tmpPath, $destPath);
        }

        if (!function_exists('imagejpeg')) {
            return false;
        }

        $src = drawdream_image_load_gd($tmpPath);
        if ($src === null) {
            return false;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            return false;
        }

        $ratio = $size / max(1, $maxBytes);
        $scale = min(1.0, 2048 / max($srcW, $srcH));
        if ($ratio > 4) {
            $scale = min($scale, 0.65);
        }
        if ($ratio > 10) {
            $scale = min($scale, 0.45);
        }

        $quality = 88;
        $lastData = null;

        for ($attempt = 0; $attempt < 22; $attempt++) {
            $nw = max(1, (int)round($srcW * $scale));
            $nh = max(1, (int)round($srcH * $scale));
            $canvas = imagecreatetruecolor($nw, $nh);
            if ($canvas === false) {
                break;
            }

            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $nw, $nh, $srcW, $srcH);

            $data = drawdream_image_jpeg_bytes($canvas, $quality);
            imagedestroy($canvas);

            if ($data === null) {
                break;
            }

            $lastData = $data;
            if (strlen($data) <= $maxBytes) {
                imagedestroy($src);
                if (@file_put_contents($destPath, $data) === false) {
                    return false;
                }
                @unlink($tmpPath);
                return true;
            }

            if ($quality > 38) {
                $quality = max(38, $quality - 10);
            } else {
                $scale *= 0.8;
                $quality = 82;
            }
        }

        imagedestroy($src);

        if ($lastData !== null && strlen($lastData) <= (int)($maxBytes * 1.03)) {
            if (@file_put_contents($destPath, $lastData) === false) {
                return false;
            }
            @unlink($tmpPath);
            return true;
        }

        return false;
    }
}
