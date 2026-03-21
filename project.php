<?php
// ไฟล์นี้: project.php
// หน้าที่: หน้ารวมโครงการพร้อมระบบค้นหาและกรอง
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

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
$cat     = $_GET['cat'] ?? 'all';
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
            $deleteDetailStmt = $conn->prepare("DELETE FROM project_detail WHERE project_id = ?");
            $deleteDetailStmt->bind_param("i", $deleteProjectId);
            if (!$deleteDetailStmt->execute()) {
                throw new Exception($deleteDetailStmt->error ?: 'ลบรายละเอียดโครงการไม่สำเร็จ');
            }

            $deleteProjectStmt = $conn->prepare("DELETE FROM project WHERE project_id = ? AND foundation_name = ?");
            $deleteProjectStmt->bind_param("is", $deleteProjectId, $foundationName);
            if (!$deleteProjectStmt->execute()) {
                throw new Exception($deleteProjectStmt->error ?: 'ลบโครงการไม่สำเร็จ');
            }
            if ($deleteProjectStmt->affected_rows < 1) {
                throw new Exception('ไม่พบโครงการที่ต้องการลบ');
            }

            mysqli_commit($conn);
            echo "<script>alert('ลบโครงการเรียบร้อยแล้ว'); window.location='project.php?view=foundation';</script>";
            exit();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            echo "<script>alert('ลบโครงการไม่สำเร็จ: " . addslashes($e->getMessage()) . "'); window.location='project.php?view=foundation';</script>";
            exit();
        }
    }
}

$categories = ['การศึกษา', 'สุขภาพและอนามัย', 'อาหารและโภชนาการ', 'สิ่งอำนวยความสะดวก'];
$statusOptions = ['all', 'fundraising', 'completed'];
$sortOptions = ['latest', 'popular_desc', 'popular_asc', 'no_donation_latest'];
if (!in_array($cat, $categories, true)) $cat = 'all';
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
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 2592000) return floor($diff / 86400) . ' วันที่แล้ว';
    return 'มากกว่า 30 วันที่แล้ว';
}

function projectStatusThai($status) {
    $map = [
        'pending' => ['label' => 'รออนุมัติ', 'class' => 'st-pending'],
        'approved' => ['label' => 'กำลังระดมทุน', 'class' => 'st-approved'],
        'completed' => ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'],
        'done' => ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'],
        'rejected' => ['label' => 'ไม่ผ่านการอนุมัติ', 'class' => 'st-rejected'],
    ];
    return $map[$status] ?? ['label' => (string)$status, 'class' => 'st-pending'];
}

// สร้าง SQL
$params = [];
$types  = "";
$where  = [];

$kwLike = "%{$keyword}%";
$where[]  = "(p.project_name LIKE ? OR p.project_desc LIKE ? OR p.foundation_name LIKE ? OR COALESCE(fp.address, '') LIKE ?)";
$params[] = $kwLike;
$params[] = $kwLike;
$params[] = $kwLike;
$params[] = $kwLike;
$types   .= "ssss";

if ($role !== 'admin') {
    $where[] = "p.project_status IN ('approved', 'completed', 'done')";
}

if ($cat !== 'all') {
    $where[] = "pd.category = ?";
    $params[] = $cat;
    $types .= "s";
}

$statusWhere = [
    'fundraising' => "p.project_status = 'approved'",
    'completed' => "p.project_status IN ('completed', 'done')",
];
if (isset($statusWhere[$status])) {
    $where[] = $statusWhere[$status];
}

if ($location !== 'all') {
    $where[] = "COALESCE(fp.address, '') LIKE ?";
    $params[] = "%{$location}%";
    $types .= "s";
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
        SELECT p.*, pd.category, fp.address AS foundation_address
        FROM project p
        LEFT JOIN project_detail pd ON pd.project_id = p.project_id
        LEFT JOIN foundation_profile fp ON fp.foundation_name = p.foundation_name
        WHERE p.foundation_name = ?
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
        SELECT p.*, pd.category, fp.address AS foundation_address
        FROM project p
        LEFT JOIN project_detail pd ON pd.project_id = p.project_id
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

    $latestWhere = [];
    $latestTypes = '';
    $latestParams = [];
    if ($role !== 'admin') {
        $latestWhere[] = "p.project_status IN ('approved', 'completed', 'done')";
    }
    $latestSql = "
        SELECT p.*, pd.category, fp.address AS foundation_address
        FROM project p
        LEFT JOIN project_detail pd ON pd.project_id = p.project_id
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
    <link rel="stylesheet" href="css/project.css?v=10">

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
            <a class="foundation-manage-btn foundation-manage-btn-donor" href="project.php?view=donor">มุมมองผู้บริจาค</a>
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
                        <p class="foundation-project-desc"><?= htmlspecialchars($row['project_desc'] ?? '') ?></p>

                        <div class="foundation-progress-meta">
                            <span>ได้รับ <?= number_format($raised, 0) ?> บาท</span>
                            <span>เป้าหมาย <?= number_format($goal, 0) ?> บาท (<?= round($progress) ?>%)</span>
                        </div>
                        <div class="foundation-progress-bar">
                            <div class="foundation-progress-fill" style="width: <?= $progress ?>%"></div>
                        </div>

                        <div class="project-edit-wrap">
                            <a class="foundation-manage-btn foundation-manage-btn-edit" href="foundation_add_project.php?edit=<?= (int)$row['project_id'] ?>">แก้ไข</a>
                        </div>
                        <div class="project-delete-wrap">
                            <form method="POST" class="foundation-delete-form" onsubmit="return confirm('ยืนยันการลบโครงการนี้?');">
                                <input type="hidden" name="delete_project_id" value="<?= (int)$row['project_id'] ?>">
                                <button type="submit" class="foundation-manage-btn foundation-manage-btn-danger">ลบ</button>
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
        <form method="get" class="search-box">
            <div class="search-container">
                <input class="search-input" type="text" name="q" placeholder="พิมพ์คำค้นหา" value="<?= htmlspecialchars($keyword) ?>">
                <button type="submit" class="search-button" aria-label="ค้นหา">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
            </div>

            <div class="filter-pills">
                <?php
                    $statusLabels = ['all' => 'สถานะ', 'fundraising' => 'กำลังระดมทุน', 'completed' => 'เสร็จสิ้น'];
                    $sortLabels   = ['latest' => 'เรียงตาม', 'popular_desc' => 'บริจาค มากไปน้อย', 'popular_asc' => 'บริจาค น้อยไปมาก'];
                ?>

                <!-- หมวดหมู่ custom dropdown -->
                <div class="cust-dropdown" id="cat-dropdown">
                    <button type="button" class="pill-btn cust-trigger<?= $cat !== 'all' ? ' pill-active' : '' ?>" id="cat-trigger">
                        <span id="cat-label"><?= $cat !== 'all' ? htmlspecialchars($cat) : 'หมวดหมู่' ?></span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <input type="hidden" name="cat" id="cat-value" value="<?= htmlspecialchars($cat) ?>">
                    <div class="cust-panel" id="cat-panel">
                        <div class="cust-option<?= $cat === 'all' ? ' selected' : '' ?>" data-val="all" data-label="หมวดหมู่" data-default="1">ทั้งหมด</div>
                        <?php foreach ($categories as $c): ?>
                            <div class="cust-option<?= $cat === $c ? ' selected' : '' ?>" data-val="<?= htmlspecialchars($c) ?>" data-label="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></div>
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
                        <div class="cust-option<?= $sort === 'popular_desc' ? ' selected' : '' ?>" data-val="popular_desc" data-label="บริจาค มากไปน้อย">บริจาค มากไปน้อย</div>
                        <div class="cust-option<?= $sort === 'popular_asc' ? ' selected' : '' ?>" data-val="popular_asc" data-label="บริจาค น้อยไปมาก">บริจาค น้อยไปมาก</div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($role === 'admin'): ?>
<div class="top-actions">
    <?php if ($role === 'admin'): ?>
        <a href="admin_approve_projects.php" class="btn-mini btn-admin">อนุมัติโครงการ</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="container">
    <?php if (!empty($latestProjects)): ?>
        <section class="latest-projects-wrap">
            <button type="button" class="latest-nav latest-prev" id="latest-prev" aria-label="เลื่อนไปซ้าย">&#10094;</button>
            <div class="latest-track" id="latest-track">
                <?php foreach ($latestProjects as $latest): ?>
                    <?php
                        $latestGoal = !empty($latest['goal_amount']) ? floatval($latest['goal_amount']) : 100000;
                        $latestRaised = (float)($latest['current_donate'] ?? 0);
                        $latestProgress = ($latestGoal > 0) ? min(100, ($latestRaised / $latestGoal) * 100) : 0;
                    ?>
                    <article class="project-card latest-card clickable-card" data-href="payment/payment_project.php?project_id=<?= (int)$latest['project_id'] ?>">
                        <img src="uploads/<?= htmlspecialchars($latest['project_image']) ?>" alt="<?= htmlspecialchars($latest['project_name']) ?>">
                        <span class="latest-badge"><?= htmlspecialchars(formatTimeAgoThai($latest['start_date'] ?? null)) ?></span>
                        <h3><?= htmlspecialchars($latest['project_name']) ?></h3>
                        <div class="project-content">
                            <div class="category-tag"><?= htmlspecialchars(detectProjectCategory($latest['project_name'] ?? '', $latest['project_desc'] ?? '')) ?></div>
                            <p><?= htmlspecialchars($latest['project_desc']) ?></p>
                            <div class="progress-section">
                                <div class="progress-label">
                                    <span class="progress-amount"><?= number_format($latestRaised, 0) ?> THB</span>
                                    <span class="progress-goal">เป้าหมาย <?= number_format($latestGoal, 0) ?> THB</span>
                                </div>
                                <div class="progress-bar-bg">
                                    <div class="progress-bar-fill" style="width: <?= $latestProgress ?>%"><?= round($latestProgress) ?>%</div>
                                </div>
                            </div>
                            <a href="payment/payment_project.php?project_id=<?= $latest['project_id'] ?>" class="donate-btn">บริจาค</a>
                        </div>
                    </article>
                <?php endforeach; ?>
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
                    $goal     = !empty($row['goal_amount']) ? floatval($row['goal_amount']) : 100000;
                    $raised = (float)($row['current_donate'] ?? 0); // TODO: ดึงจากตารางบริจาคจริงตอนเชื่อม Omise
                    $progress = ($goal > 0) ? min(100, ($raised / $goal) * 100) : 0;
                ?>
                <div class="project-card clickable-card" data-href="payment/payment_project.php?project_id=<?= (int)$row['project_id'] ?>">
                    <img src="uploads/<?= htmlspecialchars($row['project_image']) ?>"
                         alt="<?= htmlspecialchars($row['project_name']) ?>">

                    <h3><?= htmlspecialchars($row['project_name']) ?></h3>

                    <div class="project-content">
                        <div class="category-tag"><?= htmlspecialchars(detectProjectCategory($row['project_name'] ?? '', $row['project_desc'] ?? '')) ?></div>

                        <?php if (!empty($row['foundation_address'])): ?>
                            <div class="location-tag">ที่ตั้ง: <?= htmlspecialchars($row['foundation_address']) ?></div>
                        <?php endif; ?>

                        <?php if ($role === 'admin'): ?>
                            <?php
                                $st  = $row['project_status'] ?? 'pending';
                                $cls = ($st === 'approved') ? 'approved' : (($st === 'rejected') ? 'rejected' : 'pending');
                            ?>
                            <div class="badge <?= $cls ?>"><?= htmlspecialchars($st) ?></div>
                        <?php endif; ?>

                        <p><?= htmlspecialchars($row['project_desc']) ?></p>

                        <div class="progress-section">
                            <div class="progress-label">
                                <span class="progress-amount"><?= number_format($raised, 0) ?> THB</span>
                                <span class="progress-goal">เป้าหมาย <?= number_format($goal, 0) ?> THB</span>
                            </div>
                            <div class="progress-bar-bg">
                                <div class="progress-bar-fill" style="width: <?= $progress ?>%">
                                    <?= round($progress) ?>%
                                </div>
                            </div>
                        </div>

                        <a href="payment/payment_project.php?project_id=<?= $row['project_id'] ?>" class="donate-btn">บริจาค</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-projects">
                <div class="no-projects-icon"></div>
                <p>ไม่พบโครงการ<?= $cat !== 'all' ? "ในหมวด \"$cat\"" : '' ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<script>
// toggle edit/delete mode เหมือนหน้าเด็ก
(function() {
    const editBtn   = document.getElementById('toggleEditProjectBtn');
    const deleteBtn = document.getElementById('toggleDeleteProjectBtn');
    if (!editBtn || !deleteBtn) return;

    function setMode(mode) {
        const isEdit   = mode === 'edit';
        const isDelete = mode === 'delete';

        editBtn.classList.toggle('btn-mode-active', isEdit);
        deleteBtn.classList.toggle('btn-mode-active', isDelete);

        document.querySelectorAll('.project-edit-wrap').forEach(function(el) {
            el.style.display = isEdit ? 'block' : 'none';
        });
        document.querySelectorAll('.project-delete-wrap').forEach(function(el) {
            el.style.display = isDelete ? 'block' : 'none';
        });
    }

    var currentMode = null;
    editBtn.addEventListener('click', function() {
        currentMode = currentMode === 'edit' ? null : 'edit';
        setMode(currentMode);
    });
    deleteBtn.addEventListener('click', function() {
        currentMode = currentMode === 'delete' ? null : 'delete';
        setMode(currentMode);
    });
})();

// ===== Clickable project cards =====
(function() {
    document.querySelectorAll('.clickable-card').forEach(function(card) {
        card.addEventListener('click', function(e) {
            if (e.target.closest('a, button, input, textarea, select, form')) return;
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

initSimpleDropdown('cat', 'all');
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