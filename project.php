<?php
// project.php — รายการโครงการ

if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
require_once __DIR__ . '/includes/project_donation_dates.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';

$is_verified = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && isset($_SESSION['account_verified']) && $_SESSION['account_verified'] == 1);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role    = $_SESSION['role'] ?? 'donor';
$viewMode = $_GET['view'] ?? (($role === 'foundation') ? 'foundation' : 'donor');
if ($role !== 'foundation') $viewMode = 'donor';
$isFoundationOwnView = ($role === 'foundation' && $viewMode === 'foundation');
$keyword = trim($_GET['q'] ?? '');
$location = trim($_GET['loc'] ?? 'all');
$status = $_GET['status'] ?? 'all';
$sort = $_GET['sort'] ?? 'latest';

$foundationName = '';
if ($role === 'foundation' && isset($_SESSION['user_id'])) {
    $foundationStmt = $conn->prepare("SELECT foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1");
    $uid = (int)$_SESSION['user_id'];
    $foundationStmt->bind_param("i", $uid);
    $foundationStmt->execute();
    $foundationName = (string)($foundationStmt->get_result()->fetch_assoc()['foundation_name'] ?? '');
}

if ($role === 'foundation' && isset($_POST['delete_project_id'])) {
    $deleteProjectId = (int)($_POST['delete_project_id'] ?? 0);
    if ($deleteProjectId > 0 && $foundationName !== '') {
        mysqli_begin_transaction($conn);
        try {
            $deleteProjectStmt = $conn->prepare(
                "UPDATE foundation_project SET deleted_at = NOW(), project_delete_reason = NULL
                 WHERE project_id = ? AND foundation_name = ? AND project_status IN ('pending','rejected') AND deleted_at IS NULL"
            );
            $deleteProjectStmt->bind_param("is", $deleteProjectId, $foundationName);
            if (!$deleteProjectStmt->execute()) {
                throw new Exception($deleteProjectStmt->error ?: 'ลบโครงการไม่สำเร็จ');
            }
            if ($deleteProjectStmt->affected_rows < 1) {
                throw new Exception('ลบได้เฉพาะโครงการสถานะรอดำเนินการหรือไม่ผ่านการอนุมัติเท่านั้น');
            }

            mysqli_commit($conn);
            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
            echo "<script>Swal.fire({icon:'success',title:'ลบโครงการแล้ว (ข้อมูลยังเก็บในระบบ)',showConfirmButton:false,timer:1800}).then(()=>{window.location='project.php?view=foundation';});</script>";
            exit();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
            echo "<script>Swal.fire({icon:'error',title:'ลบโครงการไม่สำเร็จ',text:'" . addslashes($e->getMessage()) . "',showConfirmButton:true}).then(()=>{window.location='project.php?view=foundation';});</script>";
            exit();
        }
    }
}

$categories = ['การศึกษา', 'สุขภาพและอนามัย', 'อาหารและโภชนาการ', 'สิ่งอำนวยความสะดวก'];

/** @var list<string> */
$selectedCats = [];
if (isset($_GET['cat'])) {
    $rawCat = $_GET['cat'];
    if (is_array($rawCat)) {
        foreach ($rawCat as $c) {
            $c = trim((string)$c);
            if ($c !== '' && in_array($c, $categories, true)) {
                $selectedCats[] = $c;
            }
        }
        $selectedCats = array_values(array_unique($selectedCats));
    } else {
        $s = trim((string)$rawCat);
        if ($s !== '' && $s !== 'all') {
            if (strpos($s, ',') !== false) {
                foreach (explode(',', $s) as $part) {
                    $part = trim($part);
                    if ($part !== '' && in_array($part, $categories, true)) {
                        $selectedCats[] = $part;
                    }
                }
                $selectedCats = array_values(array_unique($selectedCats));
            } elseif (in_array($s, $categories, true)) {
                $selectedCats = [$s];
            }
        }
    }
}

$statusOptions = ['all', 'fundraising', 'completed'];
$sortOptions = ['latest', 'popular_desc', 'popular_asc', 'no_donation_latest'];
if (!in_array($status, $statusOptions, true)) $status = 'all';
if (!in_array($sort, $sortOptions, true)) $sort = 'latest';

$thaiRegions = [
    'ภาคเหนือ' => ['เชียงใหม่', 'เชียงราย', 'ลำปาง', 'ลำพูน', 'น่าน', 'พะเยา', 'แพร่', 'แม่ฮ่องสอน', 'อุตรดิตถ์'],
    'ภาคตะวันออกเฉียงเหนือ' => ['ขอนแก่น', 'นครราชสีมา', 'อุดรธานี', 'อุบลราชธานี', 'บุรีรัมย์', 'สุรินทร์', 'ศรีสะเกษ', 'ร้อยเอ็ด', 'มหาสารคาม', 'กาฬสินธุ์', 'เลย', 'หนองคาย', 'หนองบัวลำภู', 'สกลนคร', 'นครพนม', 'มุกดาหาร', 'ยโสธร', 'อำนาจเจริญ', 'ชัยภูมิ', 'บึงกาฬ'],
    'ภาคกลาง' => ['กรุงเทพมหานคร', 'นครปฐม', 'นนทบุรี', 'ปทุมธานี', 'พระนครศรีอยุธยา', 'ลพบุรี', 'สระบุรี', 'สิงห์บุรี', 'อ่างทอง', 'ชัยนาท', 'สุพรรณบุรี'],
    'ภาคตะวันออก' => ['ชลบุรี', 'ระยอง', 'จันทบุรี', 'ตราด', 'ฉะเชิงเทรา', 'ปราจีนบุรี', 'สระแก้ว'],
    'ภาคตะวันตก' => ['กาญจนบุรี', 'ราชบุรี', 'เพชรบุรี', 'ประจวบคีรีขันธ์', 'ตาก'],
    'ภาคใต้' => ['สุราษฎร์ธานี', 'ภูเก็ต', 'สงขลา', 'นครศรีธรรมราช', 'กระบี่', 'พังงา', 'ระนอง', 'ชุมพร', 'ตรัง', 'พัทลุง', 'สตูล', 'ปัตตานี', 'ยะลา', 'นราธิวาส']
];

$provinceOptions = [];
foreach ($thaiRegions as $provinces) {
    foreach ($provinces as $provinceName) {
        $provinceOptions[] = $provinceName;
    }
}
if ($location !== 'all' && !in_array($location, $provinceOptions, true)) {
    $location = 'all';
}

function detectProjectCategory($name, $desc) {
    $text = trim(($name ?? '') . ' ' . ($desc ?? ''));
    $map = [
        'การศึกษา' => ['การศึกษา', 'เรียน', 'ทุนการศึกษา', 'โรงเรียน', 'หนังสือ'],
        'สุขภาพและอนามัย' => ['ป่วย', 'รักษา', 'โรงพยาบาล', 'ยา'],
        'อาหารและโภชนาการ' => ['อาหาร', 'โภชนาการ', 'นม', 'ข้าวกลางวัน'],
        'สิ่งอำนวยความสะดวก' => ['ด้อยโอกาส', 'ยากไร้', 'ขาดแคลน', 'เปราะบาง'],
    ];

    foreach ($map as $label => $keywords) {
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                return $label;
            }
        }
    }
    return 'สิ่งอำนวยความสะดวก';
}

function formatTimeAgoThai($datetime) {
    if (empty($datetime)) return 'โครงการใหม่';
    $timestamp = strtotime($datetime);
    if ($timestamp === false) return 'โครงการใหม่';

    $diff = time() - $timestamp;
    // ถ้าน้อยกว่า 1 ชั่วโมง ให้แสดงว่าโครงการใหม่
    if ($diff < 3600) return 'โครงการใหม่';
    if ($diff < 86400) return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 2592000) return floor($diff / 86400) . ' วันที่แล้ว';
    return 'มากกว่า 30 วันที่แล้ว';
}

function projectStatusThai($status) {
    $map = [
        'pending' => ['label' => 'รอดำเนินการ', 'class' => 'st-pending'],
        'approved' => ['label' => 'กำลังระดมทุน', 'class' => 'st-approved'],
        'completed' => ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'],
        'done' => ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'],
        'purchasing' => ['label' => 'กำลังจัดซื้อ', 'class' => 'st-purchasing'],
        'rejected' => ['label' => 'ไม่ผ่านการอนุมัติ', 'class' => 'st-rejected'],
    ];
    return $map[$status] ?? ['label' => (string)$status, 'class' => 'st-pending'];
}

/**
 * สถานะที่ผู้บริจาคเห็น: fundraising | completed | closed (ซ่อนจาก donor ทั้งหมด)
 * DB status purchasing (กำลังจัดซื้อหลังแอดมิน escrow) นับเป็น completed ในมุมผู้บริจาค
 */
function donorProjectEffectiveState(array $row): string {
    $goal = !empty($row['goal_amount']) ? (float)$row['goal_amount'] : 0.0;
    $raised = (float)($row['current_donate'] ?? 0);
    $dbSt = (string)($row['project_status'] ?? '');

    $half = ($goal > 0) ? ($goal * 0.5) : 0.0;

    $endRaw = $row['end_date'] ?? null;
    $ended = false;
    if (!empty($endRaw)) {
        try {
            $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
            $endDay = substr((string)$endRaw, 0, 10);
            $ended = ($endDay !== '' && $endDay < $today);
        } catch (Exception $e) {
            $ended = false;
        }
    }

    if (in_array($dbSt, ['completed', 'done', 'purchasing'], true)) {
        return 'completed';
    }

    if ($raised >= $goal && $goal > 0) {
        return 'completed';
    }

    if ($ended) {
        if ($raised >= $half) {
            return 'completed';
        }
        return 'closed';
    }

    return 'fundraising';
}

/** ยังรับบริจาคได้ (วันสิ้นสุดตามเขตเวลาไทย — สอดคล้องหน้าชำระเงิน) */
function donorProjectStillAcceptingDonations(array $row): bool {
    $endRaw = $row['end_date'] ?? null;
    if ($endRaw === null || trim((string)$endRaw) === '') {
        return true;
    }
    try {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        $endDay = substr((string)$endRaw, 0, 10);
        return $endDay >= $today;
    } catch (Exception $e) {
        return true;
    }
}

/** แสดงในรายการผู้บริจาค: เฉพาะช่วงระดมทุน (ไม่ซ่อนเพราะวันเริ่มโครงการ) */
function donorProjectShowInBrowseList(array $row): bool
{
    return donorProjectEffectiveState($row) === 'fundraising';
}

/** แถบโครงการล่าสุด: แสดงทั้งที่ระดมทุนและที่ครบเป้า/กำลังจัดซื้อ (ซ่อนเฉพาะโครงการปิดเพราะยอดไม่ถึงครึ่งเป้า) */
function donorProjectShowInDonorLatestStrip(array $row): bool
{
    $eff = donorProjectEffectiveState($row);
    return $eff === 'fundraising' || $eff === 'completed';
}

function donorProjectProgressPct(array $row): float {
    $goal = !empty($row['goal_amount']) ? (float)$row['goal_amount'] : 0.0;
    $raised = (float)($row['current_donate'] ?? 0);
    if ($goal <= 0) {
        return 0.0;
    }
    return min(100.0, ($raised / $goal) * 100.0);
}

/** @param list<array<string,mixed>> $rows */
function donorFilterProjectRows(array $rows, string $status): array {
    $out = [];
    foreach ($rows as $row) {
        if (donorProjectEffectiveState($row) === 'closed') {
            continue;
        }
        $eff = donorProjectEffectiveState($row);
        $dbDone = in_array((string)($row['project_status'] ?? ''), ['completed', 'done', 'purchasing'], true);

        if ($status === 'completed') {
            if ($dbDone || $eff === 'completed') {
                $out[] = $row;
            }
            continue;
        }

        if ($status === 'all') {
            if ($eff === 'fundraising' || $eff === 'completed') {
                $out[] = $row;
            }
            continue;
        }

        // กำลังระดมทุน: เฉพาะที่สถานะระดมทุนและยังไม่เลยวันปิดรับ — ให้บริจาคได้จริง
        if ($status === 'fundraising') {
            if ($eff !== 'fundraising') {
                continue;
            }
            if (!donorProjectStillAcceptingDonations($row)) {
                continue;
            }
            $out[] = $row;
            continue;
        }

        if (!donorProjectShowInBrowseList($row)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

// สร้าง SQL

$params = [];
$types  = "";
$where  = [];
$where[] = 'p.deleted_at IS NULL';

$publicDonorStyle = (!$isFoundationOwnView && in_array($role, ['donor', 'foundation'], true));

// ปรับให้ค้นหาเฉพาะชื่อโครงการ (project_name) เท่านั้น
$kwLike = "%{$keyword}%";
if ($keyword !== '') {
    $where[]  = "p.project_name LIKE ?";
    $params[] = $kwLike;
    $types   .= "s";
}

if ($publicDonorStyle) {
    if ($status === 'fundraising') {
        $where[] = "p.project_status = 'approved'";
    } else {
        $where[] = "p.project_status IN ('approved', 'completed', 'done', 'purchasing')";
    }
} elseif ($role !== 'admin') {
    $where[] = "p.project_status IN ('approved', 'completed', 'done', 'purchasing')";
}

if (!empty($selectedCats)) {
    $placeholders = implode(', ', array_fill(0, count($selectedCats), '?'));
    $where[] = "p.category IN ($placeholders)";
    foreach ($selectedCats as $c) {
        $params[] = $c;
        $types .= "s";
    }
}

if ($location !== 'all') {
    $where[] = "(COALESCE(p.location, '') LIKE ? OR COALESCE(fp.address, '') LIKE ?)";
    $params[] = "%{$location}%";
    $params[] = "%{$location}%";
    $types .= "ss";
}

$orderBy = "p.project_id DESC";
if ($sort === 'popular_desc') {
    $orderBy = "COALESCE(p.current_donate, 0) DESC, p.project_id DESC";
} elseif ($sort === 'popular_asc') {
    $orderBy = "COALESCE(p.current_donate, 0) ASC, p.project_id DESC";
} elseif ($sort === 'no_donation_latest') {
    $orderBy = "CASE WHEN COALESCE(p.current_donate, 0) = 0 THEN 0 ELSE 1 END ASC, p.project_id DESC";
}

$projects = [];
$latestProjects = [];

if ($isFoundationOwnView) {
    $foundationSql = "
        SELECT p.*, fp.address AS foundation_address
        FROM foundation_project p
        LEFT JOIN foundation_profile fp ON fp.foundation_name = p.foundation_name
        WHERE p.foundation_name = ? AND p.deleted_at IS NULL
        ORDER BY p.project_id DESC
    ";
    $foundationProjStmt = $conn->prepare($foundationSql);
    $foundationProjStmt->bind_param("s", $foundationName);
    $foundationProjStmt->execute();
    $foundationResult = $foundationProjStmt->get_result();
    if ($foundationResult) {
        while ($row = $foundationResult->fetch_assoc()) {
            $projects[] = $row;
        }
    }
} else {
    $sql  = "
        SELECT p.*, fp.address AS foundation_address
        FROM foundation_project p
        LEFT JOIN foundation_profile fp ON fp.foundation_name = p.foundation_name
        WHERE " . implode(" AND ", $where) . "
        ORDER BY {$orderBy}
    ";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    }

    if ($publicDonorStyle) {
        $projects = donorFilterProjectRows($projects, $status);
        if (in_array($sort, ['popular_desc', 'popular_asc'], true)) {
            usort($projects, static function (array $a, array $b) use ($sort): int {
                $ca = (float)($a['current_donate'] ?? 0);
                $cb = (float)($b['current_donate'] ?? 0);
                if ($ca === $cb) {
                    return ((int)($b['project_id'] ?? 0)) <=> ((int)($a['project_id'] ?? 0));
                }
                return $sort === 'popular_desc' ? ($cb <=> $ca) : ($ca <=> $cb);
            });
        }
    }

    $latestWhere = [];
    $latestTypes = '';
    $latestParams = [];
    if ($publicDonorStyle) {
        $latestWhere[] = "p.project_status IN ('approved', 'completed', 'done', 'purchasing')";
    } elseif ($role !== 'admin') {
        $latestWhere[] = "p.project_status IN ('approved', 'completed', 'done', 'purchasing')";
    }
    $latestWhere[] = 'p.deleted_at IS NULL';
    $latestSql = "
        SELECT p.*, fp.address AS foundation_address
        FROM foundation_project p
        LEFT JOIN foundation_profile fp ON fp.foundation_name = p.foundation_name
    ";
    if (!empty($latestWhere)) {
        $latestSql .= " WHERE " . implode(' AND ', $latestWhere);
    }
    $latestSql .= " ORDER BY COALESCE(p.start_date, '1970-01-01') DESC, p.project_id DESC LIMIT 10";

    $latestStmt = $conn->prepare($latestSql);
    if (!empty($latestParams)) $latestStmt->bind_param($latestTypes, ...$latestParams);
    $latestStmt->execute();
    $latestResult = $latestStmt->get_result();
    if ($latestResult) {
        while ($latestRow = $latestResult->fetch_assoc()) {
            $latestProjects[] = $latestRow;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>โครงการ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/project.css?v=28">

</head>
<body class="projects-page">

<?php include 'navbar.php'; ?>

<?php if ($isFoundationOwnView): ?>

<div class="foundation-view-wrap">
    <div class="foundation-view-head">
        <h1>โครงการของเรา</h1>
        <p>ติดตามสถานะโครงการที่มูลนิธิของคุณสร้างและรอการอนุมัติได้จากหน้านี้</p>
        <div class="foundation-view-toolbar">
            <div class="foundation-view-actions">
                <?php if ($is_verified): ?>
                    <a class="foundation-manage-btn foundation-manage-btn-primary" href="foundation_add_project.php">+ เสนอโครงการ</a>
                <?php else: ?>
                    <span class="foundation-warn">รอการอนุมัติก่อนจึงจะเสนอโครงการได้</span>
                <?php endif; ?>
                <button type="button" id="toggleEditProjectBtn" class="foundation-manage-btn foundation-manage-btn-edit">แก้ไขโครงการ</button>
                <button type="button" id="toggleDeleteProjectBtn" class="foundation-manage-btn foundation-manage-btn-danger">ลบโครงการ</button>
            </div>
        </div>
    </div>

    <div class="foundation-project-list">
        <?php if (!empty($projects)): ?>
            <?php foreach ($projects as $row): ?>
                <?php
                    $goal = !empty($row['goal_amount']) ? floatval($row['goal_amount']) : 100000;
                    $raised = (float)($row['current_donate'] ?? 0);
                    $progress = ($goal > 0) ? min(100, ($raised / $goal) * 100) : 0;
                    $statusMeta = projectStatusThai($row['project_status'] ?? 'pending');
                    // ดึง remark กรณีถูกปฏิเสธ (ถ้ามี)
                    $remark = '';
                    if (($row['project_status'] ?? '') === 'rejected') {
                        $stmtR = $conn->prepare("SELECT remark FROM admin WHERE target_entity = 'project' AND notif_type IN ('ไม่อนุมัติ', 'project_rejected') AND target_id=? ORDER BY id DESC LIMIT 1");
                        $pid = (int)$row['project_id'];
                        $stmtR->bind_param("i", $pid);
                        $stmtR->execute();
                        $remarkRow = $stmtR->get_result()->fetch_assoc();
                        $remark = $remarkRow['remark'] ?? '';
                    }
                ?>
                <article class="foundation-project-item <?= htmlspecialchars($statusMeta['class']) ?>">
                    <?php if (!empty($row['project_image'])): ?>
                        <img class="foundation-project-thumb" src="uploads/<?= htmlspecialchars($row['project_image']) ?>" alt="<?= htmlspecialchars($row['project_name']) ?>">
                    <?php else: ?>
                        <div class="foundation-project-thumb empty"></div>
                    <?php endif; ?>

                    <div class="foundation-project-body">
                        <h3><?= htmlspecialchars($row['project_name']) ?></h3>
                        <span class="foundation-status-pill <?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span>
                        <?php
                        $isCompleted = in_array($row['project_status'], ['completed', 'done', 'purchasing'], true);
                        $isOwner = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && $row['foundation_name'] === $foundationName);
                        ?>
                        <?php if ($isCompleted): ?>
                            <?php if ($isOwner): ?>
                                <a href="foundation_post_update.php?project_id=<?= (int)$row['project_id'] ?>" class="foundation-manage-btn" style="background:#597D57;color:#fff;margin-left:8px;">อัปเดตผลลัพธ์โครงการ</a>
                            <?php else: ?>
                                <a href="foundation_post_update.php?project_id=<?= (int)$row['project_id'] ?>" class="foundation-manage-btn" style="background:#597D57;color:#fff;margin-left:8px;">ดูผลลัพธ์โครงการ</a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (($row['project_status'] ?? '') === 'pending'): ?>
                            <div class="foundation-status-alert st-pending">โครงการนี้รอแอดมินตรวจสอบ</div>
                        <?php elseif (($row['project_status'] ?? '') === 'rejected'): ?>
                            <div class="foundation-status-alert st-rejected">โครงการนี้ไม่ผ่านการอนุมัติ<?= $remark ? ': '.htmlspecialchars($remark) : '' ?></div>
                        <?php endif; ?>
                        <p class="foundation-project-desc"><?= htmlspecialchars($row['project_desc'] ?? '') ?></p>

                        <div class="foundation-progress-meta">
                            <span>ได้รับ <?= number_format($raised, 0) ?> บาท</span>
                            <span>เป้าหมาย <?= number_format($goal, 0) ?> บาท (<?= round($progress) ?>%)</span>
                        </div>
                        <div class="foundation-progress-bar">
                            <div class="foundation-progress-fill" style="width: <?= $progress ?>%"></div>
                        </div>

                        <?php
                            $pst = strtolower(trim((string)($row['project_status'] ?? '')));
                            if ($pst === '') {
                                $pst = 'pending';
                            }
                            // แก้ไขได้ทุกสถานะยกเว้นโครงการจบแล้ว (รองรับค่า DB ตัวพิมพ์เล็ก-ใหญ่)
                            $allowEditCard = !in_array($pst, ['completed', 'done', 'purchasing'], true);
                            $allowDeleteCard = in_array($pst, ['pending', 'rejected'], true);
                            $tzB = new DateTimeZone('Asia/Bangkok');
                            $endRaw = $row['end_date'] ?? null;
                            $ended = false;
                            if (!empty($endRaw)) {
                                try {
                                    $endD = new DateTimeImmutable(substr((string)$endRaw, 0, 10), $tzB);
                                    $ended = $endD->format('Y-m-d') < (new DateTimeImmutable('now', $tzB))->format('Y-m-d');
                                } catch (Exception $e) {
                                    $ended = false;
                                }
                            }
                            $halfGoal = ($goal > 0) ? ($goal * 0.5) : 0.0;
                            $mergedIntoId = (int)($row['merged_into_project_id'] ?? 0);
                            $canMergeFunds = ($pst === 'approved' && $ended && $goal > 0 && $raised > 0 && $raised < $halfGoal && $mergedIntoId <= 0);
                        ?>
                        <?php if ($mergedIntoId > 0): ?>
                            <div class="foundation-merge-hint" style="background:#ecfdf5;">
                                <span class="foundation-merge-note" style="color:#065f46;"><strong>สมทบยอดแล้ว</strong> — ยอดบริจาคถูกนำไปรวมกับโครงการหมายเลข <?= (int)$mergedIntoId ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($canMergeFunds): ?>
                            <div class="foundation-merge-hint">
                                <a class="foundation-manage-btn foundation-manage-btn-merge" href="foundation_merge_project.php?from=<?= (int)$row['project_id'] ?>">สมทบยอดที่ได้รับเข้าโครงการอื่น</a>
                                <span class="foundation-merge-note">ครบกำหนดแล้วแต่ยอดบริจาคยังไม่ถึง 50% ของเป้าหมาย — สามารถรวมยอดไปนับเป็นโครงการที่สำเร็จร่วมกันได้</span>
                            </div>
                        <?php endif; ?>
                        <div class="project-edit-wrap" data-allow-edit="<?= $allowEditCard ? '1' : '0' ?>">
                            <a class="foundation-project-pill-edit" href="foundation_add_project.php?edit=<?= (int)$row['project_id'] ?>">แก้ไขโครงการ</a>
                        </div>
                        <div class="project-delete-wrap" data-allow-delete="<?= $allowDeleteCard ? '1' : '0' ?>">
                            <form method="POST" class="foundation-delete-form">
                                <input type="hidden" name="delete_project_id" value="<?= (int)$row['project_id'] ?>">
                                <div class="foundation-delete-actions">
                                    <button type="submit" class="foundation-pill-confirm-delete">ยืนยันลบ</button>
                                    <button type="button" class="foundation-pill-cancel-delete">ยกเลิก</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="foundation-empty">ยังไม่มีโครงการของมูลนิธิในระบบ</div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>

<div class="hero-section">
    <div class="hero-content">
        <h1 class="hero-title">โครงการที่ใช่ <span class="highlight">ในวันที่คุณอยากให้</span></h1>
        <p class="hero-subtitle">บริจาคให้โครงการที่ใช่</p>
        <form method="get" class="search-box" id="project-filter-form">
            <div class="search-container">
                <input class="search-input" type="text" name="q" placeholder="พิมพ์คำค้นหา" value="<?= htmlspecialchars($keyword) ?>">
                <button type="submit" class="search-button" aria-label="ค้นหา">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
            </div>

            <div class="filter-pills">
                <?php
                    $statusLabels = ['all' => 'สถานะ', 'fundraising' => 'กำลังระดมทุน', 'completed' => 'เสร็จสิ้น'];
                    $sortLabels   = ['latest' => 'เรียงตาม', 'popular_desc' => 'ยอดบริจาค มาก→น้อย', 'popular_asc' => 'ยอดบริจาค น้อย→มาก'];
                ?>

                <!-- หมวดหมู่: เลือกได้หลายหมวด ส่งฟอร์มทันทีเมื่อคลิก (ไม่มีปุ่มนำไปใช้) -->
                <div class="cust-dropdown cat-multi-dropdown" id="cat-dropdown">
                    <button type="button" class="pill-btn cust-trigger<?= !empty($selectedCats) ? ' pill-active' : '' ?>" id="cat-trigger">
                        <span id="cat-label"><?php
                            if (empty($selectedCats)) {
                                echo 'หมวดหมู่';
                            } elseif (count($selectedCats) === 1) {
                                echo htmlspecialchars($selectedCats[0]);
                            } else {
                                echo htmlspecialchars($selectedCats[0]) . ' +' . (count($selectedCats) - 1);
                            }
                        ?></span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="cust-panel cat-multi-panel" id="cat-panel">
                        <div class="cust-option cat-all-opt<?= empty($selectedCats) ? ' cat-picked' : '' ?>" data-cat-action="all" data-default="1">ทั้งหมด</div>
                        <?php foreach ($categories as $c): ?>
                            <div class="cust-option cat-opt<?= in_array($c, $selectedCats, true) ? ' cat-picked' : '' ?>" data-cat-val="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div id="cat-hidden-group" aria-hidden="true">
                        <?php foreach ($selectedCats as $c): ?>
                            <input type="hidden" name="cat[]" value="<?= htmlspecialchars($c) ?>">
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ตำแหน่งที่ตั้ง: Custom two-level dropdown -->
                <div class="loc-dropdown" id="loc-dropdown">
                    <button type="button" class="pill-btn loc-trigger<?= $location !== 'all' ? ' pill-active' : '' ?>" id="loc-trigger">
                        <span id="loc-label"><?= $location !== 'all' ? htmlspecialchars($location) : 'ตำแหน่งที่ตั้ง' ?></span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <input type="hidden" name="loc" id="loc-value" value="<?= htmlspecialchars($location) ?>">
                    <div class="loc-panel" id="loc-panel">
                        <div class="loc-col-left" id="loc-col-regions">
                            <div class="loc-region-item<?= $location === 'all' ? ' active' : '' ?>" data-region="__all__">ทั้งหมด</div>
                            <?php foreach ($thaiRegions as $regionName => $provinces): ?>
                                <?php $isActiveRegion = in_array($location, $provinces, true); ?>
                                <div class="loc-region-item<?= $isActiveRegion ? ' active' : '' ?>" data-region="<?= htmlspecialchars($regionName) ?>"><?= htmlspecialchars($regionName) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="loc-col-right" id="loc-col-provinces"></div>
                    </div>
                </div>

                <!-- สถานะ custom dropdown -->
                <div class="cust-dropdown" id="status-dropdown">
                    <button type="button" class="pill-btn cust-trigger<?= $status !== 'all' ? ' pill-active' : '' ?>" id="status-trigger">
                        <span id="status-label"><?= htmlspecialchars($statusLabels[$status] ?? 'สถานะ') ?></span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <input type="hidden" name="status" id="status-value" value="<?= htmlspecialchars($status) ?>">
                    <div class="cust-panel" id="status-panel">
                        <div class="cust-option<?= $status === 'all' ? ' selected' : '' ?>" data-val="all" data-label="สถานะ" data-default="1">ทั้งหมด</div>
                        <div class="cust-option<?= $status === 'fundraising' ? ' selected' : '' ?>" data-val="fundraising" data-label="กำลังระดมทุน">กำลังระดมทุน</div>
                        <div class="cust-option<?= $status === 'completed' ? ' selected' : '' ?>" data-val="completed" data-label="เสร็จสิ้น">เสร็จสิ้น</div>
                    </div>
                </div>

                <!-- เรียงตาม custom dropdown -->
                <div class="cust-dropdown" id="sort-dropdown">
                    <button type="button" class="pill-btn cust-trigger<?= $sort !== 'latest' ? ' pill-active' : '' ?>" id="sort-trigger">
                        <span id="sort-label"><?= htmlspecialchars($sortLabels[$sort] ?? 'เรียงตาม') ?></span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <input type="hidden" name="sort" id="sort-value" value="<?= htmlspecialchars($sort) ?>">
                    <div class="cust-panel" id="sort-panel">
                        <div class="cust-option<?= $sort === 'latest' ? ' selected' : '' ?>" data-val="latest" data-label="เรียงตาม" data-default="1">ล่าสุด</div>
                        <div class="cust-option<?= $sort === 'popular_desc' ? ' selected' : '' ?>" data-val="popular_desc" data-label="ยอดบริจาค มาก→น้อย">ยอดบริจาค มาก→น้อย</div>
                        <div class="cust-option<?= $sort === 'popular_asc' ? ' selected' : '' ?>" data-val="popular_asc" data-label="ยอดบริจาค น้อย→มาก">ยอดบริจาค น้อย→มาก</div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php 
// แสดง top-actions เฉพาะ admin
if ($role === 'admin'): 
?>
<div class="top-actions">
    <a href="admin_approve_projects.php" class="btn-mini btn-admin">อนุมัติโครงการ</a>
</div>
<?php endif; ?>

<div class="container">
    <?php
    $projectDonationCategoryId = drawdream_donate_category_id_for_project($conn);
    if ($projectDonationCategoryId <= 0) {
        $projectDonationCategoryId = drawdream_get_or_create_project_donate_category_id($conn);
    }
    ?>
    <?php if (!empty($latestProjects)): ?>
        <section class="latest-projects-wrap">
            <button type="button" class="latest-nav latest-prev" id="latest-prev" aria-label="เลื่อนไปซ้าย">&#10094;</button>
            <div class="latest-track-outer">
            <div class="latest-track" id="latest-track">
                <?php foreach ($latestProjects as $latest): ?>
                    <?php
                        if (!donorProjectShowInDonorLatestStrip($latest)) {
                            continue;
                        }
                        $latestGoal = !empty($latest['goal_amount']) ? floatval($latest['goal_amount']) : 100000;
                        $latestRaised = (float)($latest['current_donate'] ?? 0);
                        $latestProgress = donorProjectProgressPct($latest);
                        $latestBlurb = trim((string)($latest['project_quote'] ?? ''));
                        if ($latestBlurb === '') {
                            $latestBlurb = trim((string)($latest['project_desc'] ?? ''));
                        }
                        $latestDonorCount = 0;
                        $latestDaysLeft = null;
                        $stmtLatestDonor = $conn->prepare("SELECT COUNT(DISTINCT donor_id) AS cnt FROM donation WHERE category_id=? AND target_id=? AND payment_status='completed'");
                        $latestPid = (int)$latest['project_id'];
                        $stmtLatestDonor->bind_param("ii", $projectDonationCategoryId, $latestPid);
                        $stmtLatestDonor->execute();
                        $latestDonorRow = $stmtLatestDonor->get_result()->fetch_assoc();
                        if ($latestDonorRow) {
                            $latestDonorCount = (int)$latestDonorRow['cnt'];
                        }
                        $latestEnd = !empty($latest['end_date']) ? new DateTime($latest['end_date']) : null;
                        if ($latestEnd) {
                            $todayLatest = new DateTime('today');
                            $intervalLatest = $todayLatest->diff($latestEnd);
                            $latestDaysLeft = (int)$intervalLatest->format('%r%a');
                        }
                        $latestEff = donorProjectEffectiveState($latest);
                        $latestShowResults = ($latestEff === 'completed');
                        $latestCardLink = $latestShowResults
                            ? 'project_result.php?project_id=' . (int)$latest['project_id']
                            : 'payment/payment_project.php?project_id=' . (int)$latest['project_id'];
                    ?>
                    <article class="project-card latest-card clickable-card<?= $latestShowResults ? ' project-card--completed' : '' ?>" data-href="<?= htmlspecialchars($latestCardLink) ?>">
                        <div class="project-card-media">
                            <img src="uploads/<?= htmlspecialchars($latest['project_image']) ?>" alt="<?= htmlspecialchars($latest['project_name']) ?>">
                        </div>
                        <span class="latest-badge"><?= htmlspecialchars(formatTimeAgoThai($latest['start_date'] ?? null)) ?></span>
                        <div class="project-card-title-row project-card-title-row--latest">
                            <div class="project-title-meta">
                                <span class="project-stat project-stat-donors">
                                    <svg class="icon-person-outline" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    <?= $latestDonorCount ?> คน
                                </span>
                                <span class="project-stat project-stat-deadline<?= $latestShowResults ? ' is-done' : '' ?>">
                                    <?php if ($latestShowResults): ?>
                                        <svg class="icon-calendar-end" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        เสร็จสิ้น
                                    <?php elseif ($latestDaysLeft !== null): ?>
                                        <svg class="icon-calendar-end" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <?= $latestDaysLeft >= 0 ? 'อีก ' . $latestDaysLeft . ' วัน' : 'ปิดโครงการ' ?>
                                    <?php else: ?>
                                        <svg class="icon-calendar-end" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        —
                                    <?php endif; ?>
                                </span>
                            </div>
                            <h3><?= htmlspecialchars($latest['project_name']) ?></h3>
                        </div>
                        <div class="project-content">
                            <div class="category-tag"><?= htmlspecialchars(($latest['category'] ?? '') !== '' ? (string)$latest['category'] : detectProjectCategory($latest['project_name'] ?? '', $latest['project_desc'] ?? '')) ?></div>
                            <p class="project-blurb"><?= htmlspecialchars($latestBlurb) ?></p>
                            <div class="progress-section latest-progress-section">
                                <div class="latest-progress-amounts">
                                    <span class="latest-progress-current"><?= number_format($latestRaised, 0) ?> <span class="latest-progress-unit">THB</span></span>
                                    <span class="latest-progress-slash" aria-hidden="true">/</span>
                                    <span class="latest-progress-target-line">
                                        <svg class="goal-icon goal-icon--compact" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                        <?= number_format($latestGoal, 0) ?> <span class="latest-progress-unit latest-progress-unit--goal">THB</span>
                                    </span>
                                </div>
                                <div class="progress-bar-bg latest-progress-bar-bg">
                                    <div class="progress-bar-fill" style="width: <?= $latestProgress ?>%"></div>
                                </div>
                            </div>
                            <?php if (!$latestShowResults): ?>
                            <a href="payment/payment_project.php?project_id=<?= (int)$latest['project_id'] ?>" class="donate-btn">บริจาค</a>
                            <?php else: ?>
                            <a href="project_result.php?project_id=<?= (int)$latest['project_id'] ?>" class="donate-btn donate-btn--results">ผลลัพธ์ของโครงการ</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            </div>
            <button type="button" class="latest-nav latest-next" id="latest-next" aria-label="เลื่อนไปขวา">&#10095;</button>
        </section>
    <?php endif; ?>

    <section class="all-projects-head">
        <h2>โครงการรับบริจาค</h2>
        <p>สนับสนุนโครงการ เด็กที่ต้องการความช่วยเหลือทั้งหมด</p>
    </section>

    <div class="project-grid">
        <?php if (!empty($projects)): ?>
            <?php foreach ($projects as $row): ?>
                <?php
                    $goal = !empty($row['goal_amount']) ? floatval($row['goal_amount']) : 100000;
                    $raised = (float)($row['current_donate'] ?? 0);
                    $progress = donorProjectProgressPct($row);
                    $effState = donorProjectEffectiveState($row);
                    $showResults = ($effState === 'completed');
                    $cardLink = $showResults
                        ? 'project_result.php?project_id=' . (int)$row['project_id']
                        : 'payment/payment_project.php?project_id=' . (int)$row['project_id'];
                    $blurb = trim((string)($row['project_quote'] ?? ''));
                    if ($blurb === '') {
                        $blurb = trim((string)($row['project_desc'] ?? ''));
                    }
                    $donorCount = 0;
                    $daysLeft = null;
                    $stmtDonor = $conn->prepare("SELECT COUNT(DISTINCT donor_id) AS cnt FROM donation WHERE category_id=? AND target_id=? AND payment_status='completed'");
                    $pid = (int)$row['project_id'];
                    $stmtDonor->bind_param("ii", $projectDonationCategoryId, $pid);
                    $stmtDonor->execute();
                    $donorRow = $stmtDonor->get_result()->fetch_assoc();
                    if ($donorRow) {
                        $donorCount = (int)$donorRow['cnt'];
                    }
                    $endDate = !empty($row['end_date']) ? new DateTime($row['end_date']) : null;
                    if ($endDate) {
                        $today = new DateTime('today');
                        $interval = $today->diff($endDate);
                        $daysLeft = (int)$interval->format('%r%a');
                    }
                ?>
                <div class="project-card clickable-card<?= $showResults ? ' project-card--completed' : '' ?>" data-href="<?= htmlspecialchars($cardLink) ?>">
                    <div class="project-card-media">
                        <img src="uploads/<?= htmlspecialchars($row['project_image']) ?>"
                             alt="<?= htmlspecialchars($row['project_name']) ?>">
                    </div>

                    <div class="project-card-title-row">
                        <div class="project-title-meta">
                            <span class="project-stat project-stat-donors">
                                <svg class="icon-person-outline" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <?= $donorCount ?> คน
                            </span>
                            <span class="project-stat project-stat-deadline <?= $showResults ? 'is-done' : '' ?>">
                                <?php if ($showResults): ?>
                                    <svg class="icon-calendar-end" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    เสร็จสิ้น
                                <?php elseif ($daysLeft !== null): ?>
                                    <svg class="icon-calendar-end" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?= $daysLeft >= 0 ? 'อีก ' . $daysLeft . ' วัน' : 'ปิดโครงการ' ?>
                                <?php else: ?>
                                    <svg class="icon-calendar-end" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>
                        <h3><?= htmlspecialchars($row['project_name']) ?></h3>
                    </div>

                    <div class="project-content">
                        <div class="category-tag"><?= htmlspecialchars(($row['category'] ?? '') !== '' ? (string)$row['category'] : detectProjectCategory($row['project_name'] ?? '', $row['project_desc'] ?? '')) ?></div>

                        <?php if ($role === 'admin'): ?>
                            <?php
                                $st = $row['project_status'] ?? 'pending';
                                $cls = ($st === 'approved') ? 'approved' : (($st === 'rejected') ? 'rejected' : 'pending');
                            ?>
                            <div class="badge <?= $cls ?>"><?= htmlspecialchars($st) ?></div>
                        <?php endif; ?>

                        <p class="project-blurb"><?= htmlspecialchars($blurb) ?></p>

                        <div class="progress-section">
                            <div class="progress-label progress-label-rows">
                                <div class="progress-col progress-col-left">
                                    <span class="progress-sublabel">ยอดบริจาคปัจจุบัน</span>
                                    <span class="progress-amount"><?= number_format($raised, 0) ?> THB</span>
                                </div>
                                <div class="progress-col progress-col-right">
                                    <span class="progress-sublabel progress-sublabel-dim">&nbsp;</span>
                                    <span class="progress-goal">
                                        <svg class="goal-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                        <?= number_format($goal, 0) ?> THB
                                    </span>
                                </div>
                            </div>
                            <div class="progress-bar-wrap">
                                <!-- ลบ % ออก ไม่ต้องแสดง -->
                                <div class="progress-bar-bg">
                                    <div class="progress-bar-fill" style="width: <?= $progress ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <?php if (!$showResults): ?>
                            <a href="payment/payment_project.php?project_id=<?= (int)$row['project_id'] ?>" class="donate-btn">บริจาค</a>
                        <?php else: ?>
                            <a href="project_result.php?project_id=<?= (int)$row['project_id'] ?>" class="donate-btn donate-btn--results">ผลลัพธ์ของโครงการ</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-projects">
                <div class="no-projects-icon"></div>
                <p>ไม่พบโครงการ<?= !empty($selectedCats) ? ' ในหมวดที่เลือก' : '' ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<script>
// โหมดแก้ไข/ลบแบบเดียวกับหน้าเด็ก: ใช้ class บน body ควบคุม (เลี่ยงปัญหา display ถูก override)
(function() {
    const editBtn = document.getElementById('toggleEditProjectBtn');
    const deleteBtn = document.getElementById('toggleDeleteProjectBtn');
    if (!editBtn || !deleteBtn) return;

    function syncToolbarActive() {
        editBtn.classList.toggle('btn-mode-active', document.body.classList.contains('mode-edit-project'));
        deleteBtn.classList.toggle('btn-mode-active', document.body.classList.contains('mode-delete-project'));
    }

    editBtn.addEventListener('click', function() {
        const turnOn = !document.body.classList.contains('mode-edit-project');
        document.body.classList.remove('mode-delete-project');
        document.body.classList.toggle('mode-edit-project', turnOn);
        syncToolbarActive();
    });

    deleteBtn.addEventListener('click', function() {
        const turnOn = !document.body.classList.contains('mode-delete-project');
        document.body.classList.remove('mode-edit-project');
        document.body.classList.toggle('mode-delete-project', turnOn);
        syncToolbarActive();
    });

    document.querySelectorAll('.foundation-pill-cancel-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.body.classList.remove('mode-delete-project');
            syncToolbarActive();
        });
    });
})();

// ===== Clickable project cards =====
(function() {
    document.querySelectorAll('.clickable-card').forEach(function(card) {
        card.addEventListener('click', function(e) {
            // ถ้าคลิกปุ่ม "บริจาค" ให้ผ่าน link ปกติ
            if (e.target.closest('a.donate-btn')) return;
            // ถ้าคลิก link หรือ button อื่นให้ผ่าน
            if (e.target.closest('a, button, input, textarea, select, form')) return;
            // มิฉะนั้น redirect ไปหน้า detail
            const href = this.dataset.href;
            if (href) window.location.href = href;
        });
    });
})();

// ข้อมูลภาค-จังหวัด สำหรับ location dropdown
const thaiRegionsData = <?= json_encode($thaiRegions, JSON_UNESCAPED_UNICODE) ?>;
const currentLocValue = <?= json_encode($location, JSON_UNESCAPED_UNICODE) ?>;
const mainForm = document.querySelector('.search-box');

// ===== Generic Simple Dropdown (หมวดหมู่, สถานะ, เรียงตาม) =====
function initSimpleDropdown(id, defaultVal) {
    const wrap    = document.getElementById(id + '-dropdown');
    const trigger = document.getElementById(id + '-trigger');
    const panel   = document.getElementById(id + '-panel');
    const hidden  = document.getElementById(id + '-value');
    const label   = document.getElementById(id + '-label');
    if (!wrap) return;

    trigger.addEventListener('click', function(e) {
        e.stopPropagation();
        // ปิด dropdown อื่นที่เปิดอยู่ก่อน
        document.querySelectorAll('.cust-panel.open, .loc-panel.open').forEach(function(p) {
            if (p !== panel) p.classList.remove('open');
        });
        panel.classList.toggle('open');
    });

    panel.querySelectorAll('.cust-option').forEach(function(opt) {
        opt.addEventListener('click', function() {
            const val = this.dataset.val;
            const lbl = this.dataset.label;
            const isDefault = this.dataset.default === '1';
            hidden.value = val;
            label.textContent = lbl;
            // เปลี่ยนสีปุ่ม
            if (isDefault) {
                trigger.classList.remove('pill-active');
            } else {
                trigger.classList.add('pill-active');
            }
            // mark selected
            panel.querySelectorAll('.cust-option').forEach(o => o.classList.remove('selected'));
            this.classList.add('selected');
            panel.classList.remove('open');
            mainForm.submit();
        });
    });
}

// หมวดหมู่หลายค่า: คลิกแล้วส่งฟอร์มทันที (ไม่มีปุ่มนำไปใช้)
(function() {
    const wrap = document.getElementById('cat-dropdown');
    const trigger = document.getElementById('cat-trigger');
    const panel = document.getElementById('cat-panel');
    const hiddenGroup = document.getElementById('cat-hidden-group');
    const label = document.getElementById('cat-label');
    const form = document.getElementById('project-filter-form');
    const allRow = panel ? panel.querySelector('.cat-all-opt') : null;
    if (!wrap || !trigger || !panel || !hiddenGroup || !form || !allRow) return;

    function rebuildHidden() {
        hiddenGroup.innerHTML = '';
        panel.querySelectorAll('.cat-opt.cat-picked').forEach(function(row) {
            const v = row.getAttribute('data-cat-val');
            if (!v) return;
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'cat[]';
            inp.value = v;
            hiddenGroup.appendChild(inp);
        });
    }

    function syncLabel() {
        const picked = Array.from(panel.querySelectorAll('.cat-opt.cat-picked')).map(function(r) {
            return r.getAttribute('data-cat-val');
        }).filter(Boolean);
        if (picked.length === 0) {
            label.textContent = 'หมวดหมู่';
            trigger.classList.remove('pill-active');
        } else if (picked.length === 1) {
            label.textContent = picked[0];
            trigger.classList.add('pill-active');
        } else {
            label.textContent = picked[0] + ' +' + (picked.length - 1);
            trigger.classList.add('pill-active');
        }
    }

    function submitCategoryFilter() {
        if (allRow.classList.contains('cat-picked')) {
            hiddenGroup.innerHTML = '';
        } else {
            rebuildHidden();
        }
        syncLabel();
        panel.classList.remove('open');
        form.submit();
    }

    trigger.addEventListener('click', function(e) {
        e.stopPropagation();
        document.querySelectorAll('.cust-panel.open, .loc-panel.open').forEach(function(p) {
            if (p !== panel) p.classList.remove('open');
        });
        panel.classList.toggle('open');
    });

    allRow.addEventListener('click', function(e) {
        e.stopPropagation();
        panel.querySelectorAll('.cat-opt').forEach(function(r) { r.classList.remove('cat-picked'); });
        allRow.classList.add('cat-picked');
        hiddenGroup.innerHTML = '';
        label.textContent = 'หมวดหมู่';
        trigger.classList.remove('pill-active');
        panel.classList.remove('open');
        form.submit();
    });

    panel.querySelectorAll('.cat-opt').forEach(function(row) {
        row.addEventListener('click', function(e) {
            e.stopPropagation();
            allRow.classList.remove('cat-picked');
            row.classList.toggle('cat-picked');
            var any = panel.querySelector('.cat-opt.cat-picked');
            if (!any) {
                allRow.classList.add('cat-picked');
            }
            submitCategoryFilter();
        });
    });
})();

initSimpleDropdown('status', 'all');
initSimpleDropdown('sort', 'latest');

// ===== Latest projects horizontal slider =====
(function() {
    const track = document.getElementById('latest-track');
    const prevBtn = document.getElementById('latest-prev');
    const nextBtn = document.getElementById('latest-next');
    if (!track || !prevBtn || !nextBtn) return;

    const getStep = function() {
        const firstCard = track.querySelector('.latest-card');
        if (!firstCard) return 320;
        const style = window.getComputedStyle(track);
        const gap = parseInt(style.columnGap || style.gap || '20', 10);
        return firstCard.offsetWidth + (Number.isNaN(gap) ? 20 : gap);
    };

    prevBtn.addEventListener('click', function() {
        track.scrollBy({ left: -getStep(), behavior: 'smooth' });
    });

    nextBtn.addEventListener('click', function() {
        track.scrollBy({ left: getStep(), behavior: 'smooth' });
    });
})();

// ===== Custom Location Dropdown =====
(function() {
    const trigger  = document.getElementById('loc-trigger');
    const panel    = document.getElementById('loc-panel');
    const locValue = document.getElementById('loc-value');
    const locLabel = document.getElementById('loc-label');
    const rightCol = document.getElementById('loc-col-provinces');

    trigger.addEventListener('click', function(e) {
        e.stopPropagation();
        // ปิด dropdown อื่น
        document.querySelectorAll('.cust-panel.open').forEach(p => p.classList.remove('open'));
        panel.classList.toggle('open');
        if (panel.classList.contains('open') && currentLocValue !== 'all') {
            for (const [region, provinces] of Object.entries(thaiRegionsData)) {
                if (provinces.includes(currentLocValue)) {
                    populateProvinces(region, provinces);
                    break;
                }
            }
        }
    });

    // ปิดเมื่อคลิกข้างนอก
    document.addEventListener('click', function(e) {
        if (!document.getElementById('loc-dropdown').contains(e.target)) {
            panel.classList.remove('open');
        }
        document.querySelectorAll('.cust-panel.open').forEach(function(p) {
            const wrap = p.closest('.cust-dropdown');
            if (wrap && !wrap.contains(e.target)) p.classList.remove('open');
        });
    });

    document.querySelectorAll('.loc-region-item').forEach(function(item) {
        item.addEventListener('mouseenter', function() {
            document.querySelectorAll('.loc-region-item').forEach(r => r.classList.remove('active'));
            this.classList.add('active');
            const region = this.dataset.region;
            if (region === '__all__') { rightCol.innerHTML = ''; return; }
            populateProvinces(region, thaiRegionsData[region] || []);
        });

        if (item.dataset.region === '__all__') {
            item.addEventListener('click', function() {
                locValue.value = 'all';
                locLabel.textContent = 'ตำแหน่งที่ตั้ง';
                trigger.classList.remove('pill-active');
                panel.classList.remove('open');
                mainForm.submit();
            });
        }
    });

    function populateProvinces(region, provinces) {
        rightCol.innerHTML = provinces.map(function(p) {
            const sel = (locValue.value === p) ? ' selected' : '';
            return '<div class="loc-province-item' + sel + '" data-val="' + p + '">' + p + '</div>';
        }).join('');
        rightCol.querySelectorAll('.loc-province-item').forEach(function(pi) {
            pi.addEventListener('click', function() {
                locValue.value = this.dataset.val;
                locLabel.textContent = this.dataset.val;
                trigger.classList.add('pill-active');
                panel.classList.remove('open');
                mainForm.submit();
            });
        });
    }
})();
</script>

</body>
</html>