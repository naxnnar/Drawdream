<?php
declare(strict_types=1);

/** ไฟล์โลโก้มาตรฐานของ DrawDream (ใต้โฟลเดอร์ img/) */
function drawdream_brand_logo_filename(): string
{
    return 'โลโก้.png';
}

/** path สัมพัทธ์จากรากเว็บ เช่น '' หรือ '../' */
function drawdream_brand_logo_src(string $basePath = ''): string
{
    return $basePath . 'img/' . drawdream_brand_logo_filename();
}

/** โลโก้ sidebar แอดมิน */
function drawdream_admin_brand_logo_src(string $basePath = ''): string
{
    return $basePath . 'img/logopic.png';
}

/** ไอคอนแท็บเบราว์เซอร์ (favicon) — ไฟล์แยกจากโลโก้บนหน้าเว็บ */
function drawdream_favicon_filename(): string
{
    return 'โลโก้เว็บ.png';
}

function drawdream_favicon_src(string $basePath = ''): string
{
    return $basePath . 'img/' . drawdream_favicon_filename();
}

/** favicon สี่เหลี่ยมจัตุรัส — สร้างจาก scripts/favicon_build.py (ไม่บีบสัดส่วน) */
function drawdream_favicon_square_src(string $basePath, int $size): string
{
    return $basePath . 'img/favicon-' . $size . 'x' . $size . '.png';
}
