<?php
// project_result.php
// แสดงผลลัพธ์โครงการที่เสร็จสิ้น (สำหรับผู้บริจาค/บุคคลทั่วไป)
include 'db.php';
session_start();

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($project_id <= 0) {
    echo '<div style="padding:2em;text-align:center;">ไม่พบโครงการ</div>';
    exit;
}

// ดึงข้อมูลโครงการ
$stmt = $conn->prepare("SELECT * FROM project WHERE project_id = ? AND project_status IN ('completed','done') LIMIT 1");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
if (!$project) {
    echo '<div style="padding:2em;text-align:center;">ไม่พบโครงการนี้ หรือโครงการยังไม่เสร็จสิ้น</div>';
    exit;
}

// ดึงผลลัพธ์ (updates)
$updates = [];
$stmt2 = $conn->prepare("SELECT * FROM project_updates WHERE project_id = ? ORDER BY update_id DESC");
$stmt2->bind_param("i", $project_id);
$stmt2->execute();
$updates = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลลัพธ์โครงการ | <?= htmlspecialchars($project['project_name']) ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css">
    <style>
        .result-wrap { max-width:600px; margin:2em auto; background:#fff; border-radius:12px; box-shadow:0 2px 12px #0001; padding:2em; }
        .result-title { font-size:1.5em; font-weight:700; margin-bottom:0.5em; }
        .result-meta { color:#666; margin-bottom:1em; }
        .result-update-card { background:#f7f7fa; border-radius:8px; margin-bottom:1.5em; padding:1em 1.2em; }
        .result-update-title { font-weight:600; font-size:1.1em; margin-bottom:0.3em; }
        .result-update-img { max-width:100%; border-radius:6px; margin:0.5em 0; }
        .result-update-desc { color:#333; margin-bottom:0.5em; }
        .result-update-date { color:#888; font-size:0.95em; }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="result-wrap">
    <div class="result-title">ผลลัพธ์โครงการ: <?= htmlspecialchars($project['project_name']) ?></div>
    <div class="result-meta">
        ได้รับเงิน <?= number_format((float)$project['current_donate'], 0) ?> / <?= number_format((float)$project['goal_amount'], 0) ?> บาท<br>
        สถานะ: <span style="color:#597D57;">โครงการเสร็จสิ้น</span>
    </div>
    <?php if (empty($updates)): ?>
        <div style="color:#aaa; text-align:center;">ยังไม่มีผลลัพธ์ที่มูลนิธิโพสต์ไว้</div>
    <?php else: ?>
        <?php foreach ($updates as $u): ?>
            <div class="result-update-card">
                <div class="result-update-title"><?= htmlspecialchars($u['title']) ?></div>
                <?php if (!empty($u['update_image'])): ?>
                    <img src="uploads/updates/<?= htmlspecialchars($u['update_image']) ?>" class="result-update-img" alt="">
                <?php endif; ?>
                <div class="result-update-desc"><?= nl2br(htmlspecialchars($u['description'])) ?></div>
                <div class="result-update-date">โพสต์เมื่อ: <?= !empty($u['created_at']) ? date('d/m/Y H:i', strtotime($u['created_at'])) : '-' ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <div style="text-align:center;margin-top:2em;">
        <a href="project.php" style="color:#4A5BA8;">← กลับหน้าโครงการ</a>
    </div>
</div>
</body>
</html>
