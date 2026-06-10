<?php
/**
 * สร้าง favicon สี่เหลี่ยมจัตุรัสจาก โลโก้เว็บ.png (ไม่บีบสัดส่วน)
 * รัน: php scripts/favicon_build.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$src = $root . '/img/โลโก้เว็บ.png';
if (!is_file($src)) {
    fwrite(STDERR, "Missing source: {$src}\n");
    exit(1);
}

if (!function_exists('imagecreatefrompng')) {
    fwrite(STDERR, "PHP GD extension required.\n");
    exit(1);
}

$img = @imagecreatefrompng($src);
if ($img === false) {
    fwrite(STDERR, "Cannot load PNG.\n");
    exit(1);
}

$w = imagesx($img);
$h = imagesy($img);
echo "Source: {$w}x{$h}\n";

// ครอปสี่เหลี่ยมจัตุรัสกลาง (ให้โลโก้ใหญ่ในแท็บ)
$side = min($w, $h);
$left = (int) floor(($w - $side) / 2);
$top = (int) floor(($h - $side) / 2);
$crop = imagecreatetruecolor($side, $side);
imagealphablending($crop, false);
imagesavealpha($crop, true);
$transparent = imagecolorallocatealpha($crop, 0, 0, 0, 127);
imagefilledrectangle($crop, 0, 0, $side, $side, $transparent);
imagecopy($crop, $img, 0, 0, $left, $top, $side, $side);
imagedestroy($img);
$img = $crop;
$w = $side;
$h = $side;

$fill = 0.96;
$sizes = [32, 48, 64, 128, 192];
foreach ($sizes as $size) {
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $size, $size, $transparent);
    imagealphablending($out, true);

    $inner = (int) round($size * $fill);
    $dx = (int) round(($size - $inner) / 2);
    $dy = $dx;

    imagecopyresampled($out, $img, $dx, $dy, 0, 0, $inner, $inner, $w, $h);

    $path = $root . '/img/favicon-' . $size . 'x' . $size . '.png';
    imagepng($out, $path, 9);
    imagedestroy($out);
    echo "Wrote {$path}\n";
}

imagedestroy($img);
echo "Done.\n";
