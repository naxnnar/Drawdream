<?php
// includes/vendor_assets.php — self-host Bootstrap / icons / SweetAlert / Chart.js
declare(strict_types=1);

function drawdream_vendor_base(string $prefix = ''): string
{
    $base = rtrim($prefix, '/') . '/vendor';
    return $base === '/vendor' ? 'vendor' : $base;
}

function drawdream_bootstrap_css_link(string $prefix = ''): string
{
    $href = htmlspecialchars(drawdream_vendor_base($prefix) . '/bootstrap/bootstrap.min.css', ENT_QUOTES, 'UTF-8');
    return '<link href="' . $href . '" rel="stylesheet">';
}

function drawdream_bootstrap_js_tag(string $prefix = '', bool $defer = true): string
{
    $src = htmlspecialchars(drawdream_vendor_base($prefix) . '/bootstrap/bootstrap.bundle.min.js', ENT_QUOTES, 'UTF-8');
    $deferAttr = $defer ? ' defer' : '';
    return '<script src="' . $src . '"' . $deferAttr . '></script>';
}

function drawdream_bootstrap_icons_link(string $prefix = ''): string
{
    $href = htmlspecialchars(drawdream_vendor_base($prefix) . '/bootstrap-icons/bootstrap-icons.min.css', ENT_QUOTES, 'UTF-8');
    return '<link rel="stylesheet" href="' . $href . '">';
}

function drawdream_sweetalert2_js_tag(string $prefix = '', bool $defer = true): string
{
    $src = htmlspecialchars(drawdream_vendor_base($prefix) . '/sweetalert2/sweetalert2.all.min.js', ENT_QUOTES, 'UTF-8');
    $deferAttr = $defer ? ' defer' : '';
    return '<script src="' . $src . '"' . $deferAttr . '></script>';
}

function drawdream_chartjs_tag(string $prefix = '', bool $defer = true): string
{
    $src = htmlspecialchars(drawdream_vendor_base($prefix) . '/chart.js/chart.umd.min.js', ENT_QUOTES, 'UTF-8');
    $deferAttr = $defer ? ' defer' : '';
    return '<script src="' . $src . '"' . $deferAttr . '></script>';
}

/**
 * CSS ที่ควรอยู่ใน &lt;head&gt; ก่อนเนื้อหา (navbar สาธารณะ + แจ้งเตือน + ไอคอน)
 * หน้าใน payment/ ใช้ prefix '../'
 */
function drawdream_foundation_page_assets_head(string $prefix = '', bool $withBootstrapJs = true): void
{
    echo drawdream_bootstrap_css_link($prefix) . "\n";
    if ($withBootstrapJs) {
        echo drawdream_bootstrap_js_tag($prefix, true) . "\n";
    }
    echo drawdream_bootstrap_icons_link($prefix) . "\n";
}

function drawdream_public_navbar_assets_head(string $prefix = ''): void
{
    $cssPrefix = rtrim($prefix, '/');
    $cssPrefix = $cssPrefix === '' ? '' : $cssPrefix . '/';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($cssPrefix . 'css/navbar.css?v=14', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="stylesheet" href="' . htmlspecialchars($cssPrefix . 'css/notif.css?v=7', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    drawdream_foundation_page_assets_head($prefix, false);
}
