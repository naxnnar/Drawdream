<?php
// foundation_merge_project.php — รวม/จัดการโครงการ
// มูลนิธิ: สมทบยอดบริจาคจากโครงการที่ปิดแล้วแต่ได้ไม่ถึง 50% เข้าโครงการอื่น
session_start();
include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: project.php');
    exit();
}

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$uid = (int)($_SESSION['user_id'] ?? 0);
$stmtFp = $conn->prepare('SELECT foundation_name, foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
$stmtFp->bind_param('i', $uid);
$stmtFp->execute();
$fp = $stmtFp->get_result()->fetch_assoc();
$foundationName = trim((string)($fp['foundation_name'] ?? ''));
if ($foundationName === '') {
    header('Location: update_profile.php');
    exit();
}

$chk = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'merged_into_project_id'");
if ($chk && $chk->num_rows === 0) {
    $conn->query('ALTER TABLE foundation_project ADD COLUMN merged_into_project_id INT UNSIGNED NULL DEFAULT NULL');
}

$fromId = (int)($_GET['from'] ?? $_POST['from_project_id'] ?? 0);
if ($fromId <= 0) {
    header('Location: project.php?view=foundation');
    exit();
}

$stmt = $conn->prepare('SELECT * FROM foundation_project WHERE project_id = ? AND foundation_name = ? AND deleted_at IS NULL LIMIT 1');
$stmt->bind_param('is', $fromId, $foundationName);
$stmt->execute();
$src = $stmt->get_result()->fetch_assoc();
if (!$src) {
    echo "<script>alert('ไม่พบโครงการ'); window.location='project.php?view=foundation';</script>";
    exit();
}

$tz = new DateTimeZone('Asia/Bangkok');
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$goal = (float)($src['goal_amount'] ?? 0);
$raised = (float)($src['current_donate'] ?? 0);
$endRaw = $src['end_date'] ?? null;
$ended = false;
if (!empty($endRaw)) {
    try {
        $endD = new DateTimeImmutable(substr((string)$endRaw, 0, 10), $tz);
        $ended = $endD->format('Y-m-d') < $today;
    } catch (Exception $e) {
        $ended = false;
    }
}
$mergedInto = (int)($src['merged_into_project_id'] ?? 0);
$eligible = ($src['project_status'] === 'approved' && $ended && $goal > 0 && $raised > 0 && $raised < ($goal * 0.5) && $mergedInto <= 0);

if (!$eligible) {
    echo "<script>alert('โครงการนี้ไม่เข้าเงื่อนไขการสมทบยอด'); window.location='project.php?view=foundation';</script>";
    exit();
}

$projectCatId = drawdream_donate_category_id_for_project($conn);
if ($projectCatId <= 0) {
    $projectCatId = drawdream_get_or_create_project_donate_category_id($conn);
}

$targets = [];
$q = $conn->prepare("SELECT project_id, project_name, current_donate, goal_amount FROM foundation_project WHERE foundation_name = ? AND project_id != ? AND project_status = 'approved' AND COALESCE(merged_into_project_id,0) = 0 AND deleted_at IS NULL ORDER BY project_id DESC");
$q->bind_param('si', $foundationName, $fromId);
$q->execute();
$res = $q->get_result();
while ($r = $res->fetch_assoc()) {
    $targets[] = $r;
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_merge'])) {
    $toId = (int)($_POST['to_project_id'] ?? 0);
    if ($toId <= 0 || $toId === $fromId) {
        $msg = 'กรุณาเลือกโครงการปลายทาง';
    } else {
        $stmtT = $conn->prepare('SELECT project_id, foundation_name, project_status FROM foundation_project WHERE project_id = ? AND foundation_name = ? AND deleted_at IS NULL LIMIT 1');
        $stmtT->bind_param('is', $toId, $foundationName);
        $stmtT->execute();
        $tgt = $stmtT->get_result()->fetch_assoc();
        if (!$tgt || $tgt['project_status'] !== 'approved') {
            $msg = 'โครงการปลายทางไม่ถูกต้อง';
        } else {
            mysqli_begin_transaction($conn);
            try {
                $add = $raised;
                $u1 = $conn->prepare('UPDATE foundation_project SET current_donate = COALESCE(current_donate,0) + ? WHERE project_id = ? AND foundation_name = ?');
                $u1->bind_param('dis', $add, $toId, $foundationName);
                if (!$u1->execute()) {
                    throw new Exception($u1->error ?: 'อัปเดตโครงการปลายทางไม่สำเร็จ');
                }
                $u2 = $conn->prepare("UPDATE foundation_project SET current_donate = 0, project_status = 'completed', merged_into_project_id = ? WHERE project_id = ? AND foundation_name = ?");
                $u2->bind_param('iis', $toId, $fromId, $foundationName);
                if (!$u2->execute()) {
                    throw new Exception($u2->error ?: 'อัปเดตโครงการต้นทางไม่สำเร็จ');
                }
                if ($projectCatId > 0) {
                    $ud = $conn->prepare('UPDATE donation SET target_id = ? WHERE target_id = ? AND category_id = ?');
                    $ud->bind_param('iii', $toId, $fromId, $projectCatId);
                    $ud->execute();
                }
                mysqli_commit($conn);
                echo "<script>alert('สมทบยอดจำนวน " . number_format($add, 0) . " บาทเข้าโครงการปลายทางแล้ว'); window.location='project.php?view=foundation';</script>";
                exit();
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $msg = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมทบยอดบริจาคเข้าโครงการอื่น | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/project.css">
</head>
<body class="project-form-page">
<?php include 'navbar.php'; ?>
<div class="form-container" style="max-width:720px;margin:24px auto;padding:0 16px;">
    <h1 style="font-family:'Prompt',sans-serif;">สมทบยอดที่ได้รับเข้าโครงการอื่น</h1>
    <p style="font-family:'Sarabun',sans-serif;color:#374151;">โครงการต้นทาง: <strong><?= htmlspecialchars($src['project_name'] ?? '') ?></strong> — ยอดที่จะสมทบ: <strong><?= number_format($raised, 0) ?> บาท</strong></p>
    <?php if ($msg !== ''): ?>
        <div style="padding:12px;background:#fee2e2;border-radius:10px;margin:12px 0;color:#991b1b;"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if (empty($targets)): ?>
        <p>ไม่มีโครงการอื่นของมูลนิธิที่รับสมทบได้ในขณะนี้</p>
        <a href="project.php?view=foundation" class="foundation-manage-btn foundation-manage-btn-primary" style="margin-top:12px;display:inline-block;">กลับ</a>
    <?php else: ?>
        <form method="post" style="margin-top:20px;">
            <input type="hidden" name="from_project_id" value="<?= (int)$fromId ?>">
            <label for="to_project_id" style="display:block;font-weight:700;margin-bottom:8px;font-family:'Prompt',sans-serif;">เลือกโครงการปลายทาง</label>
            <select name="to_project_id" id="to_project_id" required style="width:100%;padding:12px;border-radius:10px;border:1px solid #cbd5e1;font-size:1rem;">
                <?php foreach ($targets as $t): ?>
                    <option value="<?= (int)$t['project_id'] ?>"><?= htmlspecialchars($t['project_name']) ?> (ระดมไปแล้ว <?= number_format((float)$t['current_donate'], 0) ?> / <?= number_format((float)$t['goal_amount'], 0) ?> บาท)</option>
                <?php endforeach; ?>
            </select>
            <p style="font-size:0.9rem;color:#6b7280;margin:12px 0;font-family:'Sarabun',sans-serif;">ยอดบริจาครวมในโครงการต้นทางจะถูกนำไปบวกที่โครงการปลายทาง และรายการบริจาคที่ชำระแล้วจะอ้างอิงโครงการปลายทางแทน</p>
            <button type="submit" name="confirm_merge" value="1" class="btn-submit" onclick="return confirm('ยืนยันสมทบยอดนี้? ไม่สามารถย้อนกลับได้จากหน้านี้');">บันทึกข้อมูล</button>
            <a href="project.php?view=foundation" style="display:inline-block;margin-top:12px;margin-left:12px;">ยกเลิก</a>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
