<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

$is_verified = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && isset($_SESSION['account_verified']) && $_SESSION['account_verified'] == 1);

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$role    = $_SESSION['role'] ?? 'donor';
$keyword = trim($_GET['q'] ?? '');
$cat     = $_GET['cat'] ?? 'all';

$categories = ['เด็กเล็ก', 'เด็กพิการ', 'เด็กด้อยโอกาส', 'เด็กป่วย', 'การศึกษา', 'อาหารและโภชนาการ'];
if (!in_array($cat, $categories, true)) $cat = 'all';

// สร้าง SQL
$params = [];
$types  = "";
$where  = [];

$kwLike = "%{$keyword}%";
$where[]  = "(project_name LIKE ? OR project_desc LIKE ?)";
$params[] = $kwLike;
$params[] = $kwLike;
$types   .= "ss";

if ($role !== 'admin') {
    $where[] = "project_status = 'approved'";
}

if ($cat !== 'all') {
    $where[]  = "category = ?";
    $params[] = $cat;
    $types   .= "s";
}

$sql  = "SELECT * FROM project WHERE " . implode(" AND ", $where) . " ORDER BY project_id DESC";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// ดึงโครงการที่ completed (แยก section)
$completed_params = [];
$completed_types  = "";
$completed_where  = ["project_status IN ('completed','done')"];
$kwLike2 = "%{$keyword}%";
$completed_where[] = "(project_name LIKE ? OR project_desc LIKE ?)";
$completed_params[] = $kwLike2;
$completed_params[] = $kwLike2;
$completed_types .= "ss";
if ($cat !== 'all') {
    $completed_where[] = "category = ?";
    $completed_params[] = $cat;
    $completed_types .= "s";
}
$sql_completed = "SELECT * FROM project WHERE " . implode(" AND ", $completed_where) . " ORDER BY project_id DESC";
$stmt_c = $conn->prepare($sql_completed);
if (!empty($completed_params)) $stmt_c->bind_param($completed_types, ...$completed_params);
$stmt_c->execute();
$result_completed = $stmt_c->get_result();

// ดึง project_updates ทั้งหมด จัดกลุ่มตาม project_id (dedup ด้วย update_id)
$updates_map = [];
$uq = mysqli_query($conn, "
    SELECT pu.*
    FROM project_updates pu
    INNER JOIN (
        SELECT project_id, MAX(update_id) as max_id
        FROM project_updates
        GROUP BY project_id, title
    ) latest ON pu.project_id = latest.project_id AND pu.update_id = latest.max_id
    ORDER BY pu.update_id DESC
");
if ($uq) {
    while ($u = mysqli_fetch_assoc($uq)) {
        $updates_map[(int)$u['project_id']][] = $u;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>โครงการ | DrawDream</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/projects.css">
    <style>
    .section-header {
        max-width: 1200px;
        margin: 40px auto 20px;
        padding: 0 20px;
        font-size: 20px;
        font-weight: 700;
        color: #333;
        border-left: 4px solid #4A5BA8;
        padding-left: 16px;
    }
    .section-header.completed-header { border-left-color: #4CAF50; }

    .updates-section {
        margin-top: 16px;
        border-top: 1px solid #f0f0f0;
        padding-top: 14px;
    }
    .updates-label {
        font-size: 12px;
        font-weight: 700;
        color: #4A5BA8;
        margin-bottom: 10px;
    }
    .update-item {
        background: #f8f9ff;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 8px;
        border-left: 3px solid #4A5BA8;
    }
    .update-item-title { font-size: 13px; font-weight: 700; color: #222; margin-bottom: 4px; }
    .update-item-desc  { font-size: 12px; color: #555; line-height: 1.5; margin-bottom: 6px; }
    .update-item-img   { width: 100%; max-height: 160px; object-fit: cover; border-radius: 6px; margin-bottom: 6px; }
    .update-item-date  { font-size: 11px; color: #aaa; }

    .completed-badge {
        display: inline-block;
        background: #e8f5e9;
        color: #2e7d32;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 20px;
        margin-bottom: 8px;
    }
    </style>

</head>
<body class="projects-page">

<?php include 'navbar.php'; ?>

<div class="hero-section">
    <div class="hero-content">
        <h1 class="hero-title">โครงการที่ใช่ <span class="highlight">ในวันที่คุณอยากให้</span></h1>
        <p class="hero-subtitle">บริจาคให้โครงการที่ใช่</p>
        <form method="get" class="search-box">
            <input type="hidden" name="cat" value="<?= htmlspecialchars($cat) ?>">
            <input type="text" name="q" placeholder="พิมพ์คำค้นหา" value="<?= htmlspecialchars($keyword) ?>">
            <button type="submit">ค้นหา</button>
        </form>
    </div>

    <!-- Filter Chips -->
    <div class="filter-row">
        <a class="chip <?= $cat === 'all' ? 'active' : '' ?>" href="?cat=all&q=<?= urlencode($keyword) ?>">ทั้งหมด</a>
        <?php foreach ($categories as $c): ?>
            <a class="chip <?= $cat === $c ? 'active' : '' ?>" href="?cat=<?= urlencode($c) ?>&q=<?= urlencode($keyword) ?>"><?= $c ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($role === 'foundation' || $role === 'admin'): ?>
<div class="top-actions">
    <?php if ($role === 'foundation'): ?>
        <?php if ($is_verified): ?>
            <a href="foundation_add_project.php" class="btn-mini btn-foundation">เสนอโครงการ</a>
        <?php else: ?>
            <span style="color:#E8A020; font-size:13px;">รอการอนุมัติก่อนจึงจะเสนอโครงการได้</span>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
        <a href="admin+approve_projects.php" class="btn-mini btn-admin">อนุมัติโครงการ</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="container">
    <div class="project-grid">
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <?php
                    $goal     = !empty($row['goal_amount']) ? floatval($row['goal_amount']) : 100000;
                    $raised = (float)($row['current_donate'] ?? 0); // TODO: ดึงจากตารางบริจาคจริงตอนเชื่อม Omise
                    $progress = ($goal > 0) ? min(100, ($raised / $goal) * 100) : 0;
                ?>
                <div class="project-card">
                    <img src="uploads/<?= htmlspecialchars($row['project_image']) ?>"
                         alt="<?= htmlspecialchars($row['project_name']) ?>">

                    <h3><?= htmlspecialchars($row['project_name']) ?></h3>

                    <div class="project-content">
                        <?php if (!empty($row['category'])): ?>
                            <div class="category-tag"><?= htmlspecialchars($row['category']) ?></div>
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
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-projects">
                <div class="no-projects-icon"></div>
                <p>ไม่พบโครงการ<?= $cat !== 'all' ? "ในหมวด \"$cat\"" : '' ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ======== Section: โครงการที่สำเร็จแล้ว ======== -->
<?php if ($result_completed && $result_completed->num_rows > 0): ?>
<div class="section-header completed-header">✅ โครงการที่สำเร็จแล้ว</div>
<div class="container">
    <div class="project-grid">
        <?php while ($row = $result_completed->fetch_assoc()): ?>
            <?php
                $goal     = !empty($row['goal_amount']) ? floatval($row['goal_amount']) : 0;
                $raised   = (float)($row['current_donate'] ?? 0);
                $progress = ($goal > 0) ? min(100, ($raised / $goal) * 100) : 100;
                $pid      = (int)$row['project_id'];
                $proj_updates = $updates_map[$pid] ?? [];
            ?>
            <div class="project-card" style="opacity:0.9;">
                <img src="uploads/<?= htmlspecialchars($row['project_image']) ?>"
                     alt="<?= htmlspecialchars($row['project_name']) ?>">
                <h3><?= htmlspecialchars($row['project_name']) ?></h3>
                <div class="project-content">
                    <div class="completed-badge">✅ สำเร็จแล้ว</div>
                    <?php if (!empty($row['category'])): ?>
                        <div class="category-tag"><?= htmlspecialchars($row['category']) ?></div>
                    <?php endif; ?>
                    <p><?= htmlspecialchars($row['project_desc']) ?></p>
                    <div class="progress-section">
                        <div class="progress-label">
                            <span class="progress-amount"><?= number_format($raised, 0) ?> THB</span>
                            <span class="progress-goal">เป้าหมาย <?= number_format($goal, 0) ?> THB</span>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width:100%; background: linear-gradient(90deg,#4CAF50,#81C784);">
                                100%
                            </div>
                        </div>
                    </div>

                    <!-- Project Updates -->
                    <?php if (!empty($proj_updates)): ?>
                        <div class="updates-section">
                            <div class="updates-label">📢 ความคืบหน้า (<?= count($proj_updates) ?> อัปเดต)</div>
                            <?php foreach (array_slice($proj_updates, 0, 2) as $u): ?>
                                <div class="update-item">
                                    <div class="update-item-title"><?= htmlspecialchars($u['title']) ?></div>
                                    <?php if (!empty($u['update_image'])): ?>
                                        <img src="uploads/updates/<?= htmlspecialchars($u['update_image']) ?>" class="update-item-img" alt="">
                                    <?php endif; ?>
                                    <div class="update-item-desc"><?= nl2br(htmlspecialchars($u['description'])) ?></div>
                                    <div class="update-item-date"><?= !empty($u['created_at']) ? date('d/m/Y H:i', strtotime($u['created_at'])) : '' ?></div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($proj_updates) > 2): ?>
                                <div style="text-align:center; font-size:12px; color:#4A5BA8; padding:4px 0;">
                                    + อีก <?= count($proj_updates) - 2 ?> อัปเดต
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:12px; color:#bbb; text-align:center; padding:10px 0;">
                            รอมูลนิธิอัปเดตความคืบหน้า...
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>
<?php endif; ?>

</body>
</html>