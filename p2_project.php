<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

$is_verified = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && isset($_SESSION['account_verified']) && $_SESSION['account_verified'] == 1);

if (!isset($_SESSION['email'])) {
    header("Location: index.php");
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
    $where[] = "status = 'approved'";
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>โครงการที่ใช่ ในวันที่จุดคุณอยากให้ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/projects.css?v=5">
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
            <a href="p2_2addproject.php" class="btn-mini btn-foundation">เสนอโครงการ</a>
        <?php else: ?>
            <span style="color:#E8A020; font-size:13px;">รอการอนุมัติก่อนจึงจะเสนอโครงการได้</span>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
        <a href="admin_projects.php" class="btn-mini btn-admin">อนุมัติโครงการ</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="container">
    <div class="project-grid">
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <?php
                    $goal     = !empty($row['project_goal']) ? floatval($row['project_goal']) : 100000;
                    $raised   = 0; // TODO: ดึงจากตารางบริจาคจริงตอนเชื่อม Omise
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
                                $st  = $row['status'] ?? 'pending';
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

</body>
</html>