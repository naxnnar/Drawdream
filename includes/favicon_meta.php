<?php
// สรุปสั้น: include meta/favicon กลาง เพื่อให้ทุกหน้าใช้ไอคอนเว็บชุดเดียวกัน
/**
 * Favicon — ใส่ใน <head> หลังเปิดแท็ก head (ทุกหน้า)
 * อัปเดตไฟล์: py scripts/favicon_build.py
 */
declare(strict_types=1);

if (!empty($GLOBALS['_drawdream_favicon_done'])) {
    return;
}
$GLOBALS['_drawdream_favicon_done'] = true;

$projectRoot = realpath(__DIR__ . '/..');
$scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($projectRoot === false || $scriptDir === '') {
    $base = '';
} else {
    $rootNorm = str_replace('\\', '/', $projectRoot);
    $dirNorm = str_replace('\\', '/', $scriptDir);
    $rel = '';
    if (strncmp($dirNorm, $rootNorm, strlen($rootNorm)) === 0) {
        $rel = substr($dirNorm, strlen($rootNorm));
    }
    $depth = substr_count(trim($rel, '/'), '/');
    $base = str_repeat('../', max(0, $depth));
}

require_once __DIR__ . '/brand_logo.php';

$imgDir = $projectRoot !== false ? $projectRoot . DIRECTORY_SEPARATOR . 'img' : '';
$hasSquare = $imgDir !== ''
    && is_file($imgDir . DIRECTORY_SEPARATOR . 'favicon-32x32.png');

if ($hasSquare) {
    $emit = static function (string $href, string $sizes) use ($base): void {
        echo '  <link rel="icon" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
            . '" type="image/png" sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    };
    // ขนาดใหญ่ก่อน — Chrome จอ Retina เลือกไฟล์ละเอียดขึ้น โลโก้ดูใหญ่ชัดกว่า
    foreach ([128, 64, 48, 32] as $px) {
        $file = $imgDir . DIRECTORY_SEPARATOR . 'favicon-' . $px . 'x' . $px . '.png';
        if (is_file($file)) {
            $emit(drawdream_favicon_square_src($base, $px), $px . 'x' . $px);
        }
    }
    $apple = drawdream_favicon_square_src($base, 192);
    if (is_file($imgDir . DIRECTORY_SEPARATOR . 'favicon-192x192.png')) {
        echo '  <link rel="apple-touch-icon" href="' . htmlspecialchars($apple, ENT_QUOTES, 'UTF-8') . '" sizes="192x192">' . "\n";
    }
} else {
    $iconPng = drawdream_favicon_src($base);
    echo '  <link rel="icon" href="' . htmlspecialchars($iconPng, ENT_QUOTES, 'UTF-8') . '" type="image/png">' . "\n";
    echo '  <link rel="apple-touch-icon" href="' . htmlspecialchars($iconPng, ENT_QUOTES, 'UTF-8') . '">' . "\n";
}
