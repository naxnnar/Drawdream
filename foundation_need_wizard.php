<?php
// foundation_need_wizard.php — ขั้นตอนหลังครบเป้า / จัดการรายการสิ่งของ (รวมศูนย์)

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/includes/foundation_donor_preview.php';

drawdream_foundation_require_management_access();

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_active_account($conn);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: foundation.php');
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stFn = $conn->prepare('SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$stFn) {
    header('Location: foundation.php');
    exit();
}
$stFn->bind_param('i', $uid);
$stFn->execute();
$fp = $stFn->get_result()->fetch_assoc();
$foundationId = (int)($fp['foundation_id'] ?? 0);
$foundationName = trim((string)($fp['foundation_name'] ?? ''));
if ($foundationId <= 0) {
    header('Location: update_profile.php');
    exit();
}

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    $stPick = $conn->prepare(
        "SELECT item_id FROM foundation_needlist
         WHERE foundation_id = ?
         ORDER BY
           CASE LOWER(TRIM(COALESCE(approve_item,'')))
             WHEN 'approved' THEN 1
             WHEN 'purchasing' THEN 2
             WHEN 'done' THEN 3
             WHEN 'pending' THEN 4
             ELSE 5
           END,
           item_id DESC
         LIMIT 1"
    );
    if ($stPick) {
        $stPick->bind_param('i', $foundationId);
        $stPick->execute();
        $itemId = (int)($stPick->get_result()->fetch_assoc()['item_id'] ?? 0);
    }
}
if ($itemId <= 0) {
    header('Location: foundation_add_need.php');
    exit();
}

$st = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? AND foundation_id = ? LIMIT 1');
if (!$st) {
    header('Location: foundation.php');
    exit();
}
$st->bind_param('ii', $itemId, $foundationId);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) {
    header('Location: foundation.php');
    exit();
}

$approve = strtolower(trim((string)($row['approve_item'] ?? 'pending')));
$itemName = trim((string)($row['item_name'] ?? ''));
$goal = (float)($row['total_price'] ?? 0);
$raised = (float)($row['current_donate'] ?? 0);
$goalMet = drawdream_needlist_item_goal_met($raised, $goal);
$serviceCharge = (float)($row['service_charge'] ?? 0);
if ($serviceCharge <= 0 && $goalMet) {
    $serviceCharge = drawdream_needlist_compute_service_charge($raised);
}
$serviceChargePaid = trim((string)($row['service_charge_paid_at'] ?? '')) !== '';
$hasOutcome = trim((string)($row['update_text'] ?? '')) !== '';

$statusTh = [
    'pending' => 'รอแอดมินอนุมัติ',
    'approved' => 'เปิดรับบริจาค',
    'rejected' => 'ไม่ผ่านการอนุมัติ',
    'purchasing' => 'แอดมินกำลังจัดซื้อ',
    'done' => 'จัดส่งเสร็จแล้ว',
][$approve] ?? $approve;

/**
 * @return list<array{num:int,label:string,state:string,hint:string,cta_href:string,cta_label:string}>
 */
function foundation_need_wizard_steps(
    int $itemId,
    string $approve,
    bool $goalMet,
    bool $serviceChargePaid,
    bool $hasOutcome
): array {
    $fundraisingDone = in_array($approve, ['approved', 'purchasing', 'done'], true);
    $adminDone = in_array($approve, ['purchasing', 'done'], true);

    $s1 = $fundraisingDone ? 'done' : ($approve === 'pending' ? 'current' : 'todo');
    $s2 = !$fundraisingDone ? 'todo' : ($goalMet ? 'done' : 'current');
    $s3 = !$goalMet ? 'todo' : ($serviceChargePaid ? 'done' : 'current');
    $s4 = !$serviceChargePaid ? 'todo' : ($adminDone ? 'done' : 'current');
    $s5 = !$adminDone ? 'todo' : ($hasOutcome ? 'done' : 'current');

    return [
        [
            'num' => 1,
            'label' => 'อนุมัติรายการ',
            'state' => $s1,
            'hint' => $approve === 'pending' ? 'รอแอดมินตรวจสอบรายละเอียดสิ่งของ' : 'ผ่านการอนุมัติแล้ว',
            'cta_href' => '',
            'cta_label' => '',
        ],
        [
            'num' => 2,
            'label' => 'ระดมทุน',
            'state' => $s2,
            'hint' => $goalMet ? 'ครบเป้าหมายแล้ว' : 'รอผู้บริจาคสมทบทุนจัดซื้อ',
            'cta_href' => 'foundation_need_view.php?id=' . $itemId,
            'cta_label' => 'ดูความคืบหน้า',
        ],
        [
            'num' => 3,
            'label' => 'ชำระค่าบริการ',
            'state' => $s3,
            'hint' => $serviceChargePaid ? 'ชำระแล้ว' : ($goalMet ? 'พร้อมชำระค่าบริการระบบ' : 'ชำระได้เมื่อครบเป้าหมาย'),
            'cta_href' => $goalMet && !$serviceChargePaid ? 'payment/needlist_service_charge.php?item_id=' . $itemId : '',
            'cta_label' => $goalMet && !$serviceChargePaid ? 'ชำระค่าบริการ' : '',
        ],
        [
            'num' => 4,
            'label' => 'รอแอดมินจัดซื้อ',
            'state' => $s4,
            'hint' => $adminDone ? 'แอดมินดำเนินการแล้ว' : ($serviceChargePaid ? 'โดยทั่วไป 1–3 วันทำการ' : 'รอชำระค่าบริการก่อน'),
            'cta_href' => '',
            'cta_label' => '',
        ],
        [
            'num' => 5,
            'label' => 'โพสต์ผลลัพธ์',
            'state' => $s5,
            'hint' => $hasOutcome ? 'โพสต์ผลแล้ว' : ($approve === 'done' ? 'อัปโหลดรูป/ข้อความให้ผู้บริจาค' : 'ทำได้หลังจัดส่งเสร็จ'),
            'cta_href' => $approve === 'done' && !$hasOutcome ? 'foundation_post_needlist_result.php?item_id=' . $itemId : '',
            'cta_label' => $approve === 'done' && !$hasOutcome ? 'โพสต์ผลการจัดส่ง' : '',
        ],
    ];
}

$steps = foundation_need_wizard_steps($itemId, $approve, $goalMet, $serviceChargePaid, $hasOutcome);

$progressPct = ($goal > 0) ? (int)min(100, round(($raised / $goal) * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ขั้นตอนรายการสิ่งของ | <?= htmlspecialchars($foundationName) ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <link rel="stylesheet" href="css/foundation_manage.css?v=1">
</head>
<body class="foundation-manage-page">
<?php include 'navbar.php'; ?>
<div class="nw-wrap">
    <a href="foundation_needlist_directory.php" class="nw-back" data-foundation-back>← กลับ</a>
    <h1 class="nw-title">ขั้นตอนรายการสิ่งของ</h1>
    <p class="nw-sub"><?= htmlspecialchars($foundationName) ?> · สถานะ: <?= htmlspecialchars($statusTh) ?></p>

    <div class="nw-card">
        <div class="nw-item-name"><?= htmlspecialchars($itemName !== '' ? $itemName : 'รายการ #' . $itemId) ?></div>
        <div class="nw-meta">ระดมทุน <?= number_format($raised, 0) ?> / <?= number_format($goal, 0) ?> บาท (<?= $progressPct ?>%)</div>
        <div class="nw-bar"><div style="width:<?= $progressPct ?>%;"></div></div>
        <a href="foundation_need_view.php?id=<?= $itemId ?>" class="nw-detail-link">ดูรายละเอียดเต็ม →</a>
    </div>

    <ol class="nw-steps">
        <?php foreach ($steps as $step):
            $state = (string)($step['state'] ?? 'todo');
            $cls = 'nw-step';
            if ($state === 'current') {
                $cls .= ' nw-step--current';
            } elseif ($state === 'done') {
                $cls .= ' nw-step--done';
            }
            ?>
        <li class="<?= htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') ?>">
            <span class="nw-step__num"><?= (int)($step['num'] ?? 0) ?></span>
            <div>
                <div class="nw-step__label"><?= htmlspecialchars((string)($step['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="nw-step__hint"><?= htmlspecialchars((string)($step['hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <?php if (!empty($step['cta_href']) && !empty($step['cta_label'])): ?>
                <a class="nw-cta" href="<?= htmlspecialchars((string)$step['cta_href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$step['cta_label'], ENT_QUOTES, 'UTF-8') ?></a>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
</div>
</body>
</html>
