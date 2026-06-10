<?php
// foundation_project_view.php — มูลนิธิดูรายละเอียดโครงการ (อ่านอย่างเดียว) ข้อมูลที่กรอกครบเหมือนหน้าเสนอโครงการ

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน project view

include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/includes/drawdream_project_service_charge.php';
drawdream_ensure_foundation_project_service_charge_columns($conn);

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
    'SELECT * FROM foundation_project WHERE project_id = ? AND foundation_name = ? LIMIT 1'
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

if ($goal > 0 && $raised >= $goal - 1e-6) {
    drawdream_project_sync_service_charge_for_project($conn, $projectId);
    $stRefresh = $conn->prepare(
        'SELECT service_charge, service_charge_paid_at FROM foundation_project WHERE project_id = ? LIMIT 1'
    );
    if ($stRefresh) {
        $stRefresh->bind_param('i', $projectId);
        $stRefresh->execute();
        $ref = $stRefresh->get_result()->fetch_assoc();
        if (is_array($ref)) {
            $p['service_charge'] = $ref['service_charge'] ?? $p['service_charge'];
            $p['service_charge_paid_at'] = $ref['service_charge_paid_at'] ?? $p['service_charge_paid_at'];
        }
    }
}
$serviceChargeItem = (float)($p['service_charge'] ?? 0);
if ($serviceChargeItem <= 0 && $goal > 0 && $raised >= $goal - 1e-6) {
    $serviceChargeItem = drawdream_needlist_compute_service_charge($raised);
}
$goalMet = drawdream_project_goal_met($raised, $goal);
$serviceChargePaid = !empty($p['service_charge_paid_at']);
$serviceChargePayAmount = (int) round($serviceChargeItem);
$showServiceChargeBlock = $goalMet && ($serviceChargeItem > 0 || $serviceChargePaid);
$canPayServiceCharge = $goalMet && !$serviceChargePaid && $serviceChargeItem > 0 && $serviceChargePayAmount >= 20;
$waitingAdminAfterScPaid = $goalMet && $serviceChargePaid && !in_array(
    strtolower(trim((string)($p['project_status'] ?? ''))),
    ['purchasing', 'done', 'completed'],
    true
);
$serviceChargePctLabel = (int) round(drawdream_needlist_service_charge_rate() * 100);
$scFlash = '';
if (isset($_GET['sc_paid'])) {
    $scFlash = 'ชำระค่าบริการระบบสำหรับโครงการนี้เรียบร้อยแล้ว';
} elseif (isset($_GET['sc_err'])) {
    $scErr = (string)($_GET['sc_err'] ?? '');
    $scFlash = match ($scErr) {
        'min' => 'ยอดค่าบริการต่ำกว่าขั้นต่ำการชำระ (20 บาท) — ติดต่อแอดมิน',
        'no_amount' => 'ยังไม่มียอดค่าบริการให้ชำระ',
        'not_ready' => 'ชำระค่าบริการได้เมื่อผู้บริจาคบริจาคครบเป้าหมายแล้วเท่านั้น',
        default => 'ไม่สามารถเริ่มชำระค่าบริการได้ กรุณาลองใหม่',
    };
}
$scPaidFmt = '';
$scPaidRaw = trim((string)($p['service_charge_paid_at'] ?? ''));
if ($scPaidRaw !== '') {
    $ts = strtotime($scPaidRaw);
    if ($ts !== false) {
        $scPaidFmt = date('d/m/Y H:i', $ts);
    }
}

$imgUrl = '';
if (!empty($p['project_image'])) {
    $imgUrl = drawdream_project_image_url((string)$p['project_image'], 'uploads/');
}

$endDate = trim(substr((string)($p['end_date'] ?? ''), 0, 10));
$startDate = trim(substr((string)($p['start_date'] ?? ''), 0, 10));

$pageTitle = htmlspecialchars((string)($p['project_name'] ?? 'โครงการ'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>รายละเอียดโครงการ — <?= $pageTitle ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/project.css?v=41">
    <style>
        .fnv-sc-section { margin: 20px 0 0; padding: 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
        .fnv-sc-section__title { margin: 0 0 8px; font-size: 1.1rem; font-weight: 700; color: #0f172a; }
        .fnv-sc-section__lead { margin: 0 0 14px; font-size: 0.9rem; color: #64748b; line-height: 1.5; }
        .fnv-sc-section__wait { margin: 12px 0 0; font-size: 0.88rem; color: #0369a1; }
        .fnv-delivery__fee-rows { list-style: none; margin: 0; padding: 0; }
        .fnv-delivery__fee-rows li { display: flex; justify-content: space-between; padding: 6px 0; font-size: 0.92rem; color: #334155; }
        .fnv-delivery__fee-total { display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #cbd5e1; font-weight: 700; }
        .fnv-delivery__fee-paid { margin: 12px 0 0; color: #15803d; font-weight: 600; font-size: 0.9rem; }
        .fnv-delivery__pay-btn { display: inline-block; margin-top: 14px; padding: 12px 20px; background: #1e3a5f; color: #fff !important; border-radius: 10px; text-decoration: none; font-weight: 600; }
        .fnv-delivery__pay-btn:hover { background: #152a47; }
        .fnv-delivery__sc-flash { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; font-size: 0.9rem; }
        .fnv-delivery__sc-flash--ok { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
        .fnv-delivery__sc-flash--err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    </style>
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

    <?php if ($showServiceChargeBlock): ?>
    <section class="fnv-sc-section" aria-labelledby="fpv-sc-title">
        <?php if ($scFlash !== ''): ?>
        <p class="fnv-delivery__sc-flash <?= isset($_GET['sc_paid']) ? 'fnv-delivery__sc-flash--ok' : 'fnv-delivery__sc-flash--err' ?>">
            <?= htmlspecialchars($scFlash, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <?php endif; ?>
        <h2 id="fpv-sc-title" class="fnv-sc-section__title">💳 ค่าบริการระบบ (<?= $serviceChargePctLabel ?>%)</h2>
        <p class="fnv-sc-section__lead">
            ชำระหลังจากผู้บริจาคบริจาคครบยอดเป้าหมายโครงการแล้ว
            — แอดมินจะยืนยันโอนเงิน escrow ให้หลังมูลนิธิชำระค่าบริการเรียบร้อย
        </p>
        <div class="fnv-delivery__fee" style="margin:0;">
            <ul class="fnv-delivery__fee-rows">
                <li>
                    <span>ยอดบริจาคที่ได้รับ (โครงการนี้)</span>
                    <span><?= number_format($raised, 2) ?> บาท</span>
                </li>
                <li>
                    <span>ค่าบริการ <?= $serviceChargePctLabel ?>%</span>
                    <span><?= number_format($serviceChargeItem, 2) ?> บาท</span>
                </li>
            </ul>
            <div class="fnv-delivery__fee-total">
                <span>ยอดที่ต้องชำระหลังครบเป้าหมาย</span>
                <span><?= number_format($serviceChargeItem, 2) ?> บาท</span>
            </div>
            <?php if ($serviceChargePaid): ?>
            <p class="fnv-delivery__fee-paid">✅ ชำระค่าบริการระบบแล้ว<?= $scPaidFmt !== '' ? ' — ' . htmlspecialchars($scPaidFmt, ENT_QUOTES, 'UTF-8') : '' ?> — รอแอดมินยืนยันโอนเงิน</p>
            <?php elseif ($canPayServiceCharge): ?>
            <a href="payment/project_service_charge.php?project_id=<?= (int) $projectId ?>" class="fnv-delivery__pay-btn">
                💳 ชำระค่าบริการ <?= number_format($serviceChargeItem, 2) ?> บาท
            </a>
            <?php endif; ?>
        </div>
        <?php if ($waitingAdminAfterScPaid): ?>
        <p class="fnv-sc-section__wait">แอดมินได้รับแจ้งแล้วว่าคุณชำระค่าบริการ — จะดำเนินการยืนยันโอนเงินให้ในลำดับถัดไป</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

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
