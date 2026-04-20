<?php
// foundation_project_view.php — มูลนิธิดูรายละเอียดโครงการ (อ่านอย่างเดียว) ข้อมูลที่กรอกครบเหมือนหน้าเสนอโครงการ

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน project view

session_start();
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: project.php');
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stmtFn = $conn->prepare('SELECT foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$stmtFn) {
    header('Location: project.php?view=foundation');
    exit();
}
$stmtFn->bind_param('i', $uid);
$stmtFn->execute();
$foundationName = trim((string)($stmtFn->get_result()->fetch_assoc()['foundation_name'] ?? ''));
if ($foundationName === '') {
    header('Location: update_profile.php');
    exit();
}

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) {
    header('Location: project.php?view=foundation');
    exit();
}

$st = $conn->prepare(
    'SELECT * FROM foundation_project WHERE project_id = ? AND foundation_name = ? AND deleted_at IS NULL LIMIT 1'
);
$st->bind_param('is', $projectId, $foundationName);
$st->execute();
$p = $st->get_result()->fetch_assoc();
if (!$p) {
    header('Location: project.php?view=foundation');
    exit();
}

/** @return array{label:string,class:string} */
function foundation_project_view_status_meta(string $status): array
{
    $map = [
        'pending' => ['label' => 'รอดำเนินการ', 'class' => 'st-pending'],
        'approved' => ['label' => 'กำลังระดมทุน', 'class' => 'st-approved'],
        'completed' => ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'],
        'done' => ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'],
        'purchasing' => ['label' => 'กำลังจัดซื้อ', 'class' => 'st-purchasing'],
        'rejected' => ['label' => 'ไม่ผ่านการอนุมัติ', 'class' => 'st-rejected'],
    ];
    $k = strtolower(trim($status));

    return $map[$k] ?? ['label' => $status !== '' ? $status : '—', 'class' => 'st-pending'];
}

$statusMeta = foundation_project_view_status_meta((string)($p['project_status'] ?? 'pending'));
$remark = '';
if (($p['project_status'] ?? '') === 'rejected') {
    $stmtR = $conn->prepare(
        "SELECT remark FROM admin WHERE target_entity = 'project' AND notif_type IN ('ไม่อนุมัติ', 'project_rejected') AND target_id=? ORDER BY id DESC LIMIT 1"
    );
    $stmtR->bind_param('i', $projectId);
    $stmtR->execute();
    $remark = (string)($stmtR->get_result()->fetch_assoc()['remark'] ?? '');
}

$goal = !empty($p['goal_amount']) ? (float)$p['goal_amount'] : 0.0;
$raised = (float)($p['current_donate'] ?? 0);
$progress = ($goal > 0) ? min(100, ($raised / $goal) * 100) : 0.0;
$remainingToGoal = ($goal > 0) ? max(0.0, $goal - $raised) : 0.0;

$imgUrl = '';
if (!empty($p['project_image'])) {
    $imgUrl = drawdream_project_image_url((string)$p['project_image'], 'uploads/');
}

$endDate = trim(substr((string)($p['end_date'] ?? ''), 0, 10));
$startDate = trim(substr((string)($p['start_date'] ?? ''), 0, 10));
$mergedInto = (int)($p['merged_into_project_id'] ?? 0);

$pageTitle = htmlspecialchars((string)($p['project_name'] ?? 'โครงการ'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดโครงการ — <?= $pageTitle ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/project.css?v=37">
</head>
<body class="foundation-project-view-page">

<?php include 'navbar.php'; ?>

<div class="foundation-project-view-wrap">
    <a href="project.php?view=foundation" class="foundation-project-view-back">← กลับไปรายการโครงการ</a>

    <article class="foundation-project-view-panel">
    <header class="foundation-project-view-hero">
        <?php if ($imgUrl !== ''): ?>
            <div class="foundation-project-view-hero-img">
                <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" decoding="async">
            </div>
        <?php endif; ?>
        <div class="foundation-project-view-hero-text">
            <span class="foundation-status-pill <?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label']) ?></span>
            <h1 class="foundation-project-view-title"><?= htmlspecialchars((string)($p['project_name'] ?? '')) ?></h1>
            <p class="foundation-project-view-quote"><?= nl2br(htmlspecialchars((string)($p['project_quote'] ?? ''))) ?></p>
        </div>
    </header>

    <?php if (($p['project_status'] ?? '') === 'pending'): ?>
        <div class="foundation-status-alert st-pending">โครงการนี้รอแอดมินตรวจสอบ</div>
    <?php elseif (($p['project_status'] ?? '') === 'rejected'): ?>
        <div class="foundation-status-alert st-rejected">โครงการนี้ไม่ผ่านการอนุมัติ<?= $remark !== '' ? ': ' . htmlspecialchars($remark) : '' ?></div>
    <?php endif; ?>

    <?php if ($mergedInto > 0): ?>
        <div class="foundation-project-view-note foundation-project-view-note--merge">
            <strong>สมทบยอดแล้ว</strong> — ยอดบริจาคถูกนำไปรวมกับโครงการหมายเลข <?= (int)$mergedInto ?>
        </div>
    <?php endif; ?>

    <div class="foundation-project-view-progress">
        <div class="foundation-progress-meta">
            <span>ได้รับ <?= number_format($raised, 0) ?> บาท</span>
            <span>เป้าหมาย <?= number_format($goal, 0) ?> บาท (<?= (int)round($progress) ?>%)</span>
        </div>
        <?php if ($goal > 0): ?>
            <?php if ($remainingToGoal > 0): ?>
                <p class="foundation-project-view-remaining">เหลืออีก <?= number_format($remainingToGoal, 0) ?> บาทจะครบเป้าหมาย</p>
            <?php else: ?>
                <p class="foundation-project-view-remaining foundation-project-view-remaining--done">ครบเป้าหมายตามยอดที่ตั้งไว้แล้ว</p>
            <?php endif; ?>
        <?php endif; ?>
        <div class="foundation-progress-bar foundation-progress-bar--view">
            <div class="foundation-progress-fill" style="width: <?= (float)$progress ?>%"></div>
        </div>
    </div>

    <dl class="foundation-project-view-dl">
        <div class="foundation-project-view-row">
            <dt>ประเภทโครงการ</dt>
            <dd><?= htmlspecialchars((string)($p['category'] ?? '—')) ?></dd>
        </div>
        <div class="foundation-project-view-row">
            <dt>กลุ่มเป้าหมายที่ได้รับประโยชน์</dt>
            <dd><?= htmlspecialchars((string)($p['target_group'] ?? '—')) ?></dd>
        </div>
        <?php if ($startDate !== ''): ?>
        <div class="foundation-project-view-row">
            <dt>วันเริ่มโครงการ (ในระบบ)</dt>
            <dd><?= htmlspecialchars($startDate) ?></dd>
        </div>
        <?php endif; ?>
        <?php if ($endDate !== ''): ?>
        <div class="foundation-project-view-row">
            <dt>วันสิ้นสุดรับบริจาค</dt>
            <dd><?= htmlspecialchars($endDate) ?></dd>
        </div>
        <?php endif; ?>
        <div class="foundation-project-view-row foundation-project-view-row--block">
            <dt>รายละเอียดโครงการ</dt>
            <dd class="foundation-project-view-pre"><?= nl2br(htmlspecialchars((string)($p['project_desc'] ?? ''))) ?></dd>
        </div>
        <div class="foundation-project-view-row foundation-project-view-row--block">
            <dt>แผนการดำเนินงาน</dt>
            <dd class="foundation-project-view-pre"><?= nl2br(htmlspecialchars((string)($p['need_info'] ?? ''))) ?></dd>
        </div>
        <div class="foundation-project-view-row foundation-project-view-row--block">
            <dt>พื้นที่ดำเนินโครงการ</dt>
            <dd class="foundation-project-view-pre"><?= nl2br(htmlspecialchars(trim((string)($p['location'] ?? '')))) ?></dd>
        </div>
    </dl>
    </article>
</div>

</body>
</html>
