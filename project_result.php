<?php
// project_result.php — แสดงผลลัพธ์โครงการที่เสร็จสิ้น
// แสดงผลลัพธ์โครงการที่เสร็จสิ้น (สำหรับผู้บริจาค/บุคคลทั่วไป)
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน project result
include 'db.php';
session_start();

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($project_id <= 0) {
    echo '<div style="padding:2em;text-align:center;">ไม่พบโครงการ</div>';
    exit;
}

// ดึงข้อมูลโครงการ
$stmt = $conn->prepare("SELECT * FROM foundation_project WHERE project_id = ? AND project_status IN ('completed','done','purchasing') AND deleted_at IS NULL LIMIT 1");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
if (!$project) {
    echo '<div style="padding:2em;text-align:center;">ไม่พบโครงการนี้ หรือโครงการยังไม่เสร็จสิ้น</div>';
    exit;
}

$projectGoalAmount = max(0.0, (float)($project['goal_amount'] ?? 0));
$projectRaisedAmount = max(0.0, (float)($project['current_donate'] ?? 0));
// กันยอดโชว์เกินเป้าหมาย: UI ควรสะท้อนกติกา "ไม่รับเกิน goal"
if ($projectGoalAmount > 0 && $projectRaisedAmount > $projectGoalAmount) {
    $projectRaisedAmount = $projectGoalAmount;
}

// ผลลัพธ์จากคอลัมน์ foundation_project (update_text / update_at / update_images) — fallback ตาราง project_updates ถ้ามีข้อมูลเก่า
$update = null;
$text = trim((string)($project['update_text'] ?? ''));
$rawUpdImg = trim((string)($project['update_images'] ?? ''));
if ($text !== '' || $rawUpdImg !== '') {
    $update = [
        'description' => $text,
        'created_at' => $project['update_at'] ?? null,
        'update_images' => $project['update_images'] ?? '',
        'update_image' => '',
    ];
} else {
    $stmt2 = $conn->prepare("SELECT * FROM project_updates WHERE project_id = ? ORDER BY update_id DESC LIMIT 1");
    $stmt2->bind_param("i", $project_id);
    $stmt2->execute();
    $update = $stmt2->get_result()->fetch_assoc() ?: null;
}

function drawdream_project_result_image_path(string $filename): string {
    $base = basename($filename);
    if ($base === '') {
        return '';
    }
    if (is_file(__DIR__ . '/uploads/evidence/' . $base)) {
        return 'uploads/evidence/' . $base;
    }
    return 'uploads/updates/' . $base;
}

/**
 * @return list<string>
 */
function drawdream_project_result_images(array $update): array {
    $images = [];
    $raw = trim((string)($update['update_images'] ?? ''));
    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            foreach ($arr as $img) {
                $bn = basename((string)$img);
                if ($bn !== '') {
                    $images[] = $bn;
                }
            }
        }
    }
    if ($images === []) {
        $single = basename((string)($update['update_image'] ?? ''));
        if ($single !== '') {
            $images[] = $single;
        }
    }
    return array_values(array_unique($images));
}

?><!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลลัพธ์โครงการ | <?= htmlspecialchars($project['project_name']) ?></title>
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
        .result-update-title { font-weight:700; font-size:1.2rem; margin-bottom:10px; color:#111; }
        .result-slider { position: relative; }
        .result-slide { display: none; }
        .result-slide.active { display: block; }
        .result-update-img {
            width: 100%;
            max-width: 100%;
            max-height: 560px;
            object-fit: cover;
            display: block;
            border-radius: 12px;
            margin: 0;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.16);
        }
        .result-slider-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 50%;
            background: rgba(255,255,255,0.95);
            color: #374151;
            font-size: 1.2rem;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
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
    <div class="result-title">ผลลัพธ์โครงการ: <?= htmlspecialchars($project['project_name']) ?></div>
    <div class="result-meta">
        ได้รับเงิน <?= number_format($projectRaisedAmount, 0) ?> / <?= number_format($projectGoalAmount, 0) ?> บาท<br>
        สถานะ: <span style="color:#597D57;">โครงการเสร็จสิ้น</span>
    </div>
    <?php if (!$update): ?>
        <div style="color:#aaa; text-align:center;">ยังไม่มีผลลัพธ์ที่มูลนิธิโพสต์ไว้</div>
    <?php else: ?>
        <?php $imageList = drawdream_project_result_images($update); ?>
        <div class="result-update-layout">
            <div class="result-text-col">
                <div class="result-quote">❝</div>
                <div class="result-update-desc"><?= htmlspecialchars((string)$update['description']) ?></div>
                <div class="result-update-date">อัปเดตเมื่อ: <?= !empty($update['created_at']) ? date('d/m/Y H:i', strtotime($update['created_at'])) : '-' ?></div>
            </div>
            <div class="result-media-box">
                <?php if ($imageList !== []): ?>
                    <div class="result-slider" id="resultSlider">
                        <?php foreach ($imageList as $idx => $img): ?>
                            <div class="result-slide<?= $idx === 0 ? ' active' : '' ?>">
                                <img src="<?= htmlspecialchars(drawdream_project_result_image_path($img), ENT_QUOTES, 'UTF-8') ?>" class="result-update-img" alt="">
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($imageList) > 1): ?>
                            <button type="button" class="result-slider-btn prev" id="resultPrevBtn" aria-label="รูปก่อนหน้า">‹</button>
                            <button type="button" class="result-slider-btn next" id="resultNextBtn" aria-label="รูปถัดไป">›</button>
                        <?php endif; ?>
                    </div>
                    <?php if (count($imageList) > 1): ?>
                        <div class="result-slider-dots" id="resultDots">
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
<?php if ($update && count($imageList ?? []) > 1): ?>
<script>
(function () {
    var slider = document.getElementById('resultSlider');
    if (!slider) return;
    var slides = slider.querySelectorAll('.result-slide');
    var dotsWrap = document.getElementById('resultDots');
    var dots = dotsWrap ? dotsWrap.querySelectorAll('.result-slider-dot') : [];
    var prev = document.getElementById('resultPrevBtn');
    var next = document.getElementById('resultNextBtn');
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
