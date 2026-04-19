<?php
// admin_approve_projects.php — ตรวจสอบ/อนุมัติโครงการ (UI เดียวกับตรวจสอบโปรไฟล์เด็กใน children_donate.php)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

require_once __DIR__ . '/includes/drawdream_project_status.php';
require_once __DIR__ . '/includes/notification_audit.php';

$pendExprP = drawdream_sql_project_is_pending('p.project_status');

/** @param array<string,mixed> $row */
function admin_appr_project_format_date(?string $d): string
{
    $d = trim((string)$d);
    if ($d === '') {
        return '—';
    }
    $t = strtotime($d);
    if ($t === false) {
        return htmlspecialchars($d, ENT_QUOTES, 'UTF-8');
    }
    if (strlen($d) > 12) {
        return date('d/m/Y H:i', $t);
    }
    return date('d/m/Y', $t);
}

// ======== POST: อนุมัติ / ปฏิเสธ ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int)($_POST['project_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $remark = trim($_POST['remark'] ?? '');

    $newStatus = null;
    if ($action === 'approve') {
        $newStatus = 'approved';
    }
    if ($action === 'reject') {
        $newStatus = 'rejected';
    }

    if ($project_id > 0 && in_array($newStatus, ['approved', 'rejected'], true)) {
        $pend = drawdream_sql_project_is_pending('project_status');
        $stmt = $conn->prepare("UPDATE foundation_project SET project_status=? WHERE project_id=? AND {$pend} AND deleted_at IS NULL");
        $stmt->bind_param('si', $newStatus, $project_id);
        $stmt->execute();
        if ($stmt->affected_rows >= 1) {
            drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_project:' . $project_id);
            $stP = $conn->prepare('SELECT foundation_name, project_name FROM foundation_project WHERE project_id = ? LIMIT 1');
            $stP->bind_param('i', $project_id);
            $stP->execute();
            $pr = $stP->get_result()->fetch_assoc();
            $fname = trim((string)($pr['foundation_name'] ?? ''));
            $pname = (string)($pr['project_name'] ?? '');
            $fu = drawdream_foundation_user_id_by_name($conn, $fname);
            $payLink = 'payment/payment_project.php?project_id=' . $project_id;
            if ($newStatus === 'approved') {
                drawdream_send_notification(
                    $conn,
                    $fu,
                    'project_approved',
                    'โครงการได้รับการอนุมัติ',
                    'โครงการ "' . $pname . '" ผ่านการตรวจสอบแล้ว สามารถแชร์ลิงก์ให้ผู้บริจาคได้',
                    $payLink
                );
                drawdream_log_admin_action($conn, $uid, 'Approve_Project', $project_id, $remark, $fu > 0 ? $fu : null, 'project_approved');
            } else {
                $rejBody = 'โครงการ "' . $pname . '" ไม่ผ่านการอนุมัติ';
                if ($remark !== '') {
                    $rejBody .= ' เหตุผล: ' . $remark;
                }
                drawdream_send_notification(
                    $conn,
                    $fu,
                    'project_rejected',
                    'โครงการไม่ผ่านการอนุมัติ',
                    $rejBody,
                    'project.php?view=foundation',
                    'fdn_project:' . $project_id
                );
                drawdream_log_admin_action($conn, $uid, 'Reject_Project', $project_id, $remark, $fu > 0 ? $fu : null, 'project_rejected');
            }
            header('Location: admin_notifications.php?done=project#admin-pending-projects');
            exit();
        }
    }
    header('Location: admin_notifications.php?err=project#admin-pending-projects');
    exit();
}

$notifProjectsUrl = 'admin_notifications.php#admin-pending-projects';

// ไม่มีหน้ารายการ — ต้องมี ?id= มิฉะนั้น redirect ไปศูนย์แจ้งเตือน
$pid = (int)($_GET['id'] ?? 0);
if ($pid <= 0) {
    header('Location: ' . $notifProjectsUrl);
    exit();
}

$sql = "
    SELECT p.*, fp.phone AS fp_phone, fp.address AS fp_address, fp.registration_number AS fp_reg,
           u.email AS fp_email
    FROM foundation_project p
    LEFT JOIN foundation_profile fp ON fp.foundation_id = p.foundation_id
    LEFT JOIN `user` u ON u.user_id = fp.user_id
    WHERE p.project_id = ? AND {$pendExprP} AND p.deleted_at IS NULL
    LIMIT 1
";
$st = $conn->prepare($sql);
$st->bind_param('i', $pid);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) {
    header('Location: ' . $notifProjectsUrl);
    exit();
}

$goalAp = (float)($row['goal_amount'] ?? 0);
$raisedAp = (float)($row['current_donate'] ?? 0);
$pctAp = ($goalAp > 0) ? (int)min(100, round(($raisedAp / $goalAp) * 100)) : 0;
$endStatRaw = admin_appr_project_format_date(isset($row['end_date']) ? (string)$row['end_date'] : '');
$endStatLabel = ($endStatRaw === '—') ? '—' : $endStatRaw;
$projImgUrl = !empty($row['project_image'])
    ? drawdream_project_image_url((string)$row['project_image'], 'uploads/')
    : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบโครงการ | DrawDream Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=35">
</head>
<body class="admin-approve-projects-page">

<?php include 'navbar.php'; ?>

<main class="container-fluid my-4">
    <div class="admin-review-card admin-project-review">
        <div class="admin-review-header">
            <div class="admin-review-title">
                <h4 class="mb-1">ตรวจสอบโครงการ</h4>
                <div>มูลนิธิ: <?= htmlspecialchars((string)($row['foundation_name'] ?? '—')) ?></div>
            </div>
        </div>

        <div class="admin-review-body">
            <div class="row g-4 admin-review-layout">
                <div class="col-lg-4 admin-image-col">
                    <?php if ($projImgUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($projImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="รูปปกโครงการ" class="admin-project-cover">
                    <?php else: ?>
                        <div class="admin-project-cover admin-project-cover--empty" role="img" aria-label="ไม่มีรูปปก">ไม่มีรูปปก</div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-8 admin-details-col">
                    <div class="data-grid">
                        <div class="data-item">
                            <span class="label">รหัสโครงการ</span>
                            <span class="value"><?= (int)($row['project_id'] ?? 0) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สถานะ (ขณะส่ง)</span>
                            <span class="value"><?= htmlspecialchars((string)($row['project_status'] ?? 'pending')) ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">ชื่อโครงการ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['project_name'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">มูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['foundation_name'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">อีเมลติดต่อมูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['fp_email'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">เบอร์โทรมูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['fp_phone'] ?? '')) !== '' ? (string)$row['fp_phone'] : '—') ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">ที่อยู่มูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['fp_address'] ?? '—')) ?></span>
                        </div>
                        <?php if (array_key_exists('fp_reg', $row) && trim((string)($row['fp_reg'] ?? '')) !== ''): ?>
                        <div class="data-item full">
                            <span class="label">เลขทะเบียนมูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)$row['fp_reg']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="data-item">
                            <span class="label">ประเภท / หมวดโครงการ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['category'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">กลุ่มเป้าหมายที่ได้รับประโยชน์</span>
                            <span class="value"><?= htmlspecialchars((string)($row['target_group'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">คำโปรย</span>
                            <span class="value"><?= htmlspecialchars((string)($row['project_quote'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">รายละเอียดโครงการ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['project_desc'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">แผนการดำเนินงาน / กิจกรรมมูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['need_info'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">พื้นที่ดำเนินโครงการ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['location'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">วันเริ่มระดมทุน (บันทึกในระบบ)</span>
                            <span class="value"><?= htmlspecialchars(admin_appr_project_format_date(isset($row['start_date']) ? (string)$row['start_date'] : '')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">วันปิดรับบริจาค</span>
                            <span class="value"><?= htmlspecialchars(admin_appr_project_format_date(isset($row['end_date']) ? (string)$row['end_date'] : '')) ?></span>
                        </div>
                        <?php
                        $merged = (int)($row['merged_into_project_id'] ?? 0);
                        if ($merged > 0):
                        ?>
                        <div class="data-item full">
                            <span class="label">สมทบยอดไปโครงการ</span>
                            <span class="value">โครงการหมายเลข <?= $merged ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="donation-stats-panel" aria-label="สรุปตัวเลขโครงการ">
                        <div class="stats-row">
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-bullseye"></i></div>
                                <div class="stat-num"><?= number_format($goalAp, 0) ?></div>
                                <div class="stat-label">เป้าหมาย (บาท)</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-piggy-bank-fill"></i></div>
                                <div class="stat-num"><?= number_format($raisedAp, 0) ?></div>
                                <div class="stat-label">ยอดรับแล้ว (บาท)</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                                <div class="stat-num"><?= $pctAp ?>%</div>
                                <div class="stat-label">ความคืบหน้า</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-calendar-event"></i></div>
                                <div class="stat-num stat-num--date"><?= htmlspecialchars($endStatLabel) ?></div>
                                <div class="stat-label">ปิดรับบริจาค</div>
                            </div>
                        </div>
                    </div>

                    <p class="text-muted small mb-2 text-center">การไม่อนุมัติจะอัปเดตสถานะโครงการในระบบ — มูลนิธิสามารถแก้ไขและส่งพิจารณาใหม่ได้</p>

                    <form method="post" class="admin-actions">
                        <input type="hidden" name="project_id" value="<?= (int)$row['project_id'] ?>">
                        <textarea name="remark" placeholder="กรอกเหตุผลเมื่อไม่อนุมัติ"></textarea>
                        <button type="submit" name="action" value="approve" class="btn btn-primary">อนุมัติ</button>
                        <button type="submit" name="action" value="reject" class="btn btn-danger"
                                onclick="return confirm('ยืนยันไม่อนุมัติโครงการนี้?');">ไม่อนุมัติ</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
