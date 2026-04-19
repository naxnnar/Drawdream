<?php
// needlist_result.php — ผลลัพธ์การระดมสิ่งของมูลนิธิ (หลังครบเป้าหมาย) — UI เดียวกับ project_result.php

declare(strict_types=1);

include 'db.php';
require_once __DIR__ . '/includes/needlist_donate_window.php';

session_start();

$fid = (int)($_GET['fid'] ?? 0);
if ($fid <= 0) {
    header('Location: foundation.php');
    exit;
}

$st = $conn->prepare('SELECT * FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
$st->bind_param('i', $fid);
$st->execute();
$fp = $st->get_result()->fetch_assoc();
if (!$fp) {
    header('Location: foundation.php');
    exit;
}

$needOpen = drawdream_needlist_sql_open_for_donation();
$agg = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS c, COALESCE(SUM(total_price), 0) AS g FROM foundation_needlist WHERE foundation_id = ? AND ($needOpen)");
$agg->bind_param('i', $fid);
$agg->execute();
$rowAgg = $agg->get_result()->fetch_assoc();
$current = (float)($rowAgg['c'] ?? 0);
$goal = (float)($rowAgg['g'] ?? 0);
$goalMet = $goal > 0 && $current >= $goal;

if (!$goalMet) {
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ผลลัพธ์สิ่งของ</title></head><body style="font-family:sans-serif;padding:2rem;text-align:center;">ยังไม่ครบเป้าหมายการระดมทุนสิ่งของ หรือไม่มีรายการที่เปิดรับบริจาค<br><a href="foundation.php">กลับหน้ามูลนิธิ</a></body></html>';
    exit;
}

$text = trim((string)($fp['needlist_result_text'] ?? ''));
$rawImg = trim((string)($fp['needlist_result_images'] ?? ''));
$updateAt = $fp['needlist_result_at'] ?? null;

function drawdream_needlist_result_image_path(string $filename): string
{
    $base = basename($filename);
    if ($base === '') {
        return '';
    }
    if (is_file(__DIR__ . '/uploads/evidence/' . $base)) {
        return 'uploads/evidence/' . $base;
    }
    return 'uploads/updates/' . $base;
}

/** @return list<string> */
function drawdream_needlist_result_images_from_profile(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return [];
    }
    $out = [];
    foreach ($arr as $img) {
        $bn = basename((string)$img);
        if ($bn !== '') {
            $out[] = $bn;
        }
    }
    return array_values(array_unique($out));
}

$imageList = drawdream_needlist_result_images_from_profile($rawImg);
$hasContent = $text !== '' || $imageList !== [];
$fname = (string)($fp['foundation_name'] ?? 'มูลนิธิ');

?><!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลลัพธ์ของมูลนิธิ | <?= htmlspecialchars($fname) ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css">
    <style>
        body { background: #fff; }
        .result-wrap { max-width: 1120px; margin: 26px auto 30px; background: #fff; border-radius: 14px; box-shadow: 0 2px 12px #0001; padding: 22px 26px 26px; }
        .result-title { font-size: 2rem; font-weight: 700; margin-bottom: 0.25em; }
        .result-meta { color: #666; margin-bottom: 1.2em; font-size: 1.05rem; line-height: 1.55; }
        .result-update-layout { display: grid; grid-template-columns: 1.15fr 0.95fr; gap: 22px; align-items: start; }
        .result-quote { color: #d8a83d; font-size: 2rem; line-height: 1; margin-bottom: 10px; font-weight: 800; }
        .result-update-desc { color:#222; font-size: 1.08rem; line-height: 1.95; margin-bottom: 12px; white-space: pre-line; }
        .result-update-date { color:#888; font-size:0.95rem; }
        .result-media-box { position: relative; }
        .result-slider { position: relative; }
        .result-slide { display: none; }
        .result-slide.active { display: block; }
        .result-update-img {
            width: 100%; max-width: 100%; max-height: 560px; object-fit: cover; display: block;
            border-radius: 12px; margin: 0; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.16);
        }
        .result-slider-btn {
            position: absolute; top: 50%; transform: translateY(-50%); width: 34px; height: 34px;
            border: none; border-radius: 50%; background: rgba(255,255,255,0.95); color: #374151;
            font-size: 1.2rem; line-height: 1; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .result-slider-btn.prev { left: 8px; }
        .result-slider-btn.next { right: 8px; }
        .result-slider-dots { display: flex; gap: 6px; justify-content: center; margin-top: 10px; }
        .result-slider-dot { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; }
        .result-slider-dot.active { background: #4A5BA8; }
        @media (max-width: 920px) {
            .result-wrap { margin: 16px auto 24px; padding: 18px 14px 20px; border-radius: 12px; }
            .result-title { font-size: 1.6rem; }
            .result-update-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="result-wrap">
    <div class="result-title">ผลลัพธ์ของมูลนิธิ: <?= htmlspecialchars($fname) ?></div>
    <div class="result-meta">
        ได้รับเงิน <?= number_format($current, 0) ?> / <?= number_format($goal, 0) ?> บาท<br>
        สถานะ: <span style="color:#597D57;">โครงการเสร็จสิ้น</span>
    </div>
    <?php if (!$hasContent): ?>
        <div style="color:#aaa; text-align:center;">ยังไม่มีผลลัพธ์ที่มูลนิธิโพสต์ไว้</div>
    <?php else: ?>
        <div class="result-update-layout">
            <div class="result-text-col">
                <?php if ($text !== ''): ?>
                <div class="result-quote">❝</div>
                <div class="result-update-desc"><?= htmlspecialchars($text) ?></div>
                <?php endif; ?>
                <div class="result-update-date">อัปเดตเมื่อ: <?= !empty($updateAt) && !str_starts_with((string)$updateAt, '0000-00-00') ? date('d/m/Y H:i', strtotime((string)$updateAt)) : '-' ?></div>
            </div>
            <div class="result-media-box">
                <?php if ($imageList !== []): ?>
                    <div class="result-slider" id="needlistResultSlider">
                        <?php foreach ($imageList as $idx => $img): ?>
                            <div class="result-slide<?= $idx === 0 ? ' active' : '' ?>">
                                <img src="<?= htmlspecialchars(drawdream_needlist_result_image_path($img), ENT_QUOTES, 'UTF-8') ?>" class="result-update-img" alt="">
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($imageList) > 1): ?>
                            <button type="button" class="result-slider-btn prev" id="needlistResultPrev" aria-label="รูปก่อนหน้า">‹</button>
                            <button type="button" class="result-slider-btn next" id="needlistResultNext" aria-label="รูปถัดไป">›</button>
                        <?php endif; ?>
                    </div>
                    <?php if (count($imageList) > 1): ?>
                        <div class="result-slider-dots" id="needlistResultDots">
                            <?php foreach ($imageList as $idx => $_): ?>
                                <span class="result-slider-dot<?= $idx === 0 ? ' active' : '' ?>"></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="background:#f3f4f6;border-radius:12px;padding:34px 14px;color:#9ca3af;text-align:center;">ยังไม่มีรูปภาพผลลัพธ์</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php if ($hasContent && count($imageList) > 1): ?>
<script>
(function () {
    var slider = document.getElementById('needlistResultSlider');
    if (!slider) return;
    var slides = slider.querySelectorAll('.result-slide');
    var dotsWrap = document.getElementById('needlistResultDots');
    var dots = dotsWrap ? dotsWrap.querySelectorAll('.result-slider-dot') : [];
    var prev = document.getElementById('needlistResultPrev');
    var next = document.getElementById('needlistResultNext');
    var idx = 0;
    function render() {
        slides.forEach(function (s, i) { s.classList.toggle('active', i === idx); });
        dots.forEach(function (d, i) { d.classList.toggle('active', i === idx); });
    }
    if (prev) prev.addEventListener('click', function () { idx = (idx - 1 + slides.length) % slides.length; render(); });
    if (next) next.addEventListener('click', function () { idx = (idx + 1) % slides.length; render(); });
})();
</script>
<?php endif; ?>
</body>
</html>
