<?php
// admin_view_project.php — ดูข้อมูลโครงการ (จากไดเรกทอรี — ไม่มีอนุมัติ/ไม่อนุมัติ)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

/** @param array<string,mixed> $row */
function admin_view_project_fmt_date(?string $d): string
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

$pid = (int)($_GET['id'] ?? 0);
if ($pid <= 0) {
    header('Location: admin_projects_directory.php');
    exit();
}

$sql = "
    SELECT p.*, fp.phone AS fp_phone, fp.address AS fp_address, fp.registration_number AS fp_reg,
           u.email AS fp_email
    FROM foundation_project p
    LEFT JOIN foundation_profile fp ON fp.foundation_id = p.foundation_id
    LEFT JOIN `user` u ON u.user_id = fp.user_id
    WHERE p.project_id = ? AND p.deleted_at IS NULL
    LIMIT 1
";
$st = $conn->prepare($sql);
$st->bind_param('i', $pid);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) {
    header('Location: admin_projects_directory.php');
    exit();
}

$projImgUrl = !empty($row['project_image'])
    ? drawdream_project_image_url((string)$row['project_image'], 'uploads/')
    : '';
$goalAp = (float)($row['goal_amount'] ?? 0);
$raisedAp = (float)($row['current_donate'] ?? 0);
$pctAp = ($goalAp > 0) ? (int)min(100, round(($raisedAp / $goalAp) * 100)) : 0;
$endStatRaw = admin_view_project_fmt_date(isset($row['end_date']) ? (string)$row['end_date'] : '');
$endStatLabel = ($endStatRaw === '—') ? '—' : $endStatRaw;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลโครงการ | DrawDream Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=35">
    <link rel="stylesheet" href="css/admin_record_view.css">
</head>
<body class="admin-record-view-page">
<?php include 'navbar.php'; ?>

<div class="admin-record-shell">
    <div class="admin-record-back">
        <a href="admin_projects_directory.php">← กลับไปโครงการทั้งหมด</a>
    </div>
    <article class="admin-record-sheet">
        <header class="admin-record-sheet__head">
            <h1>ข้อมูลโครงการ</h1>
            <p class="admin-record-sheet__sub"><?= htmlspecialchars((string)($row['project_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($row['foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
        </header>
        <div class="admin-record-body">
            <div class="admin-record-media">
                <?php if ($projImgUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($projImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="รูปปกโครงการ">
                <?php else: ?>
                    <div class="admin-record-ph">ไม่มีรูปปก</div>
                <?php endif; ?>
            </div>
            <div class="admin-record-grid">
                <div class="admin-record-field">
                    <div class="admin-record-k">รหัสโครงการ</div>
                    <div class="admin-record-v"><?= (int)($row['project_id'] ?? 0) ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">สถานะ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['project_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">ชื่อโครงการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['project_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">มูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">อีเมลติดต่อมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['fp_email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">เบอร์โทรมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['fp_phone'] ?? '')) !== '' ? (string)$row['fp_phone'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">ที่อยู่มูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['fp_address'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php if (array_key_exists('fp_reg', $row) && trim((string)($row['fp_reg'] ?? '')) !== ''): ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">เลขทะเบียนมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)$row['fp_reg'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <div class="admin-record-field">
                    <div class="admin-record-k">ประเภท / หมวดโครงการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['category'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">กลุ่มเป้าหมายที่ได้รับประโยชน์</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['target_group'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">คำโปรย</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['project_quote'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">รายละเอียดโครงการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['project_desc'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">แผนการดำเนินงาน / กิจกรรมมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['need_info'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">พื้นที่ดำเนินโครงการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['location'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">วันเริ่มระดมทุน (บันทึกในระบบ)</div>
                    <div class="admin-record-v"><?= htmlspecialchars(admin_view_project_fmt_date(isset($row['start_date']) ? (string)$row['start_date'] : ''), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">วันปิดรับบริจาค</div>
                    <div class="admin-record-v"><?= htmlspecialchars(admin_view_project_fmt_date(isset($row['end_date']) ? (string)$row['end_date'] : ''), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php
                $merged = (int)($row['merged_into_project_id'] ?? 0);
                if ($merged > 0):
                ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">สมทบยอดไปโครงการ</div>
                    <div class="admin-record-v">โครงการหมายเลข <?= $merged ?></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="admin-record-stats donation-stats-panel" aria-label="สรุปตัวเลขโครงการ">
                <div class="stats-row">
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-bullseye"></i></div>
                        <div class="stat-num"><?= number_format($goalAp, 0, '.', ',') ?></div>
                        <div class="stat-label">เป้าหมาย (บาท)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-piggy-bank-fill"></i></div>
                        <div class="stat-num"><?= number_format($raisedAp, 0, '.', ',') ?></div>
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
        </div>
    </article>
</div>
</body>
</html>
