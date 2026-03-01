<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['email'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'] ?? 'donor';
$keyword = trim($_GET['q'] ?? '');

// ---- สร้าง SQL ตาม role ----
// donor: เห็นเฉพาะ approved
// foundation: เห็นเฉพาะ approved
// admin: เห็นทุกสถานะ
$whereStatus = "";
$params = [];
$types = "";

// เงื่อนไขค้นหา
$searchWhere = "(project_name LIKE ? OR project_desc LIKE ?)";
$kwLike = "%{$keyword}%";
$params[] = $kwLike;
$params[] = $kwLike;
$types .= "ss";

if ($role === 'admin') {
    // admin เห็นทั้งหมด ไม่ต้องกรอง status
    $whereStatus = "";
} else {
    // donor + foundation เห็นเฉพาะ approved
    $whereStatus = " AND status = 'approved' ";
}

// เตรียม statement
$sql = "SELECT * FROM project WHERE {$searchWhere} {$whereStatus} ORDER BY project_id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>โครงการ</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
      .top-actions{ display:flex; justify-content:space-between; align-items:center; gap:15px; flex-wrap:wrap; }
      .badge{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; margin-top:6px; }
      .pending{ background:#fff3cd; color:#856404; }
      .approved{ background:#d4edda; color:#155724; }
      .rejected{ background:#f8d7da; color:#721c24; }
      .btn-mini{ padding:8px 14px; border-radius:12px; display:inline-block; text-decoration:none; }
      .btn-foundation{ background:#e5c24c; color:#000; }
      .btn-admin{ background:#1e2f97; color:#fff; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">

    <div class="top-actions">
        <h2>โครงการที่ใช่ ในวันที่คุณอยากให้</h2>

        <div style="display:flex; gap:10px; align-items:center;">
            <?php if ($role === 'foundation'): ?>
                <a href="p2_2addproject.php" class="btn-mini btn-foundation">+ เสนอโครงการ</a>
            <?php endif; ?>

            <?php if ($role === 'admin'): ?>
                <a href="admin_projects.php" class="btn-mini btn-admin">อนุมัติโครงการ</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ค้นหา -->
    <form method="get" class="search-box">
        <input type="text" name="q" placeholder="ค้นหาโครงการ" value="<?= htmlspecialchars($keyword) ?>">
        <button type="submit">ค้นหา</button>
    </form>

    <div class="project-grid">
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="project-card">
                    <img src="uploads/<?= htmlspecialchars($row['project_image']) ?>" alt="">
                    <h3><?= htmlspecialchars($row['project_name']) ?></h3>
                    <p><?= htmlspecialchars($row['project_desc']) ?></p>

                    <?php if ($role === 'admin'): ?>
                        <?php
                          $st = $row['status'] ?? 'pending';
                          $cls = ($st === 'approved') ? 'approved' : (($st === 'rejected') ? 'rejected' : 'pending');
                        ?>
                        <div class="badge <?= $cls ?>"><?= htmlspecialchars($st) ?></div>
                    <?php endif; ?>

                    <a href="#" class="donate-btn">บริจาค</a>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>ไม่พบโครงการ</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>