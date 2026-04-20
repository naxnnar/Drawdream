<?php
// admin_notifications.php — ศูนย์รวมงานรออนุมัติและลิงก์คิว

if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
require_once __DIR__ . '/includes/drawdream_project_status.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: login.php');
    exit();
}

$foundationPendings = [];
$childrenPendings = [];
$projectPendings = [];
$needPendings = [];

// มูลนิธิ/มูลนิธิโปรไฟล์
$qFoundation = mysqli_query($conn, "
    SELECT foundation_id, foundation_name, created_at
    FROM foundation_profile
    WHERE account_verified = 0
    ORDER BY created_at DESC, foundation_id DESC
    LIMIT 50
");
if ($qFoundation) {
    while ($row = mysqli_fetch_assoc($qFoundation)) {
        $foundationPendings[] = $row;
    }
}

// โปรไฟล์เด็ก
$qChildren = mysqli_query($conn, "
    SELECT c.child_id, c.child_name, c.foundation_name, c.approve_profile, c.status
    FROM foundation_children c
    WHERE COALESCE(c.approve_profile, 'รอดำเนินการ') IN ('รอดำเนินการ', 'กำลังดำเนินการ') AND c.deleted_at IS NULL
    ORDER BY c.child_id DESC
    LIMIT 50
");
if ($qChildren) {
    while ($row = mysqli_fetch_assoc($qChildren)) {
        $childrenPendings[] = $row;
    }
}

// โครงการ (รองรับทั้ง pending กับข้อความไทยรอดำเนินการ — db.php normalize แล้ว แต่คงเงื่อนไขคู่เพื่อความปลอดภัย)
$pendingExpr = drawdream_sql_project_is_pending('project_status');
$qProjects = mysqli_query($conn, "
    SELECT project_id, project_name, foundation_name, end_date, start_date
    FROM foundation_project
    WHERE {$pendingExpr} AND deleted_at IS NULL
    ORDER BY project_id DESC
    LIMIT 50
");
if ($qProjects) {
    while ($row = mysqli_fetch_assoc($qProjects)) {
        $projectPendings[] = $row;
    }
}

// มูลนิธิสิ่งของที่ต้องการ
$qNeeds = mysqli_query($conn, "
    SELECT nl.item_id, nl.item_name, nl.urgent, nl.created_at, fp.foundation_name
    FROM foundation_needlist nl
    LEFT JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
    WHERE nl.approve_item = 'pending'
    ORDER BY nl.urgent DESC, nl.item_id DESC
    LIMIT 50
");
if ($qNeeds) {
    while ($row = mysqli_fetch_assoc($qNeeds)) {
        $needPendings[] = $row;
    }
}

$totalAll = count($foundationPendings) + count($childrenPendings) + count($projectPendings) + count($needPendings);

$doneBanner = '';
if (isset($_GET['done'])) {
    if ($_GET['done'] === 'project') {
        $doneBanner = 'ดำเนินการโครงการแล้ว';
    } elseif ($_GET['done'] === 'foundation') {
        $doneBanner = 'ดำเนินการคำขอมูลนิธิแล้ว';
    } elseif ($_GET['done'] === 'need') {
        $m = isset($_GET['msg']) ? trim((string)$_GET['msg']) : '';
        $doneBanner = $m !== '' ? $m : 'ดำเนินการรายการสิ่งของแล้ว';
    }
}
if (isset($_GET['err'])) {
    $doneBanner = 'ไม่สามารถดำเนินการได้ หรือรายการถูกจัดการแล้ว';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>คำขอรออนุมัติ | DrawDream Admin</title>
  <link rel="stylesheet" href="css/navbar.css">
  <style>
    .admin-notif-wrap {
      width: 100%;
      max-width: 1200px;
      margin: 14px auto 24px;
      font-family: 'Prompt', sans-serif;
    }
    .admin-notif-head {
      background: #fff;
      border-radius: 16px;
      padding: 18px 20px;
      box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
      margin-bottom: 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .admin-notif-title {
      font-size: 1.45rem;
      font-weight: 700;
      color: #1f2937;
      margin: 0;
    }
    .admin-notif-title i {
      color: #4e3b84;
      margin-right: 6px;
      font-size: 1.2rem;
    }
    .admin-notif-total {
      background: #4e3b84;
      color: #fff;
      border-radius: 999px;
      padding: 8px 14px;
      font-size: .95rem;
      font-weight: 700;
    }
    .admin-notif-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 14px;
    }
    .admin-notif-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
      overflow: hidden;
      border: 1px solid #ece8ff;
    }
    .admin-notif-card-head {
      padding: 12px 14px;
      background: linear-gradient(90deg, rgba(78,59,132,0.1), rgba(78,59,132,0.03));
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }
    .admin-notif-card-title {
      margin: 0;
      font-size: 1rem;
      font-weight: 700;
      color: #31245b;
    }
    .admin-notif-card-title i {
      margin-right: 6px;
      color: #4e3b84;
      font-size: .98rem;
    }
    .admin-notif-count {
      min-width: 28px;
      height: 28px;
      padding: 0 8px;
      border-radius: 999px;
      background: #ff8468;
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: .86rem;
      font-weight: 700;
    }
    .admin-notif-list {
      list-style: none;
      margin: 0;
      padding: 10px;
      display: grid;
      gap: 8px;
      max-height: 420px;
      overflow: auto;
    }
    .admin-notif-item {
      border: 1px solid #ede9ff;
      border-radius: 12px;
      padding: 10px;
      background: #fbfaff;
    }
    .admin-notif-item strong {
      color: #1f2937;
      font-size: .96rem;
      display: block;
      margin-bottom: 4px;
    }
    .admin-notif-meta {
      color: #6b7280;
      font-size: .84rem;
      margin-bottom: 7px;
      line-height: 1.4;
    }
    .admin-notif-link {
      display: inline-block;
      text-decoration: none;
      color: #fff;
      background: #4e3b84;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: .84rem;
      font-weight: 600;
    }
    .admin-notif-item-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      margin-top: 6px;
    }
    .admin-notif-empty {
      color: #6b7280;
      font-size: .93rem;
      padding: 16px 12px;
    }
    .admin-notif-card[id] {
      scroll-margin-top: 88px;
    }
    .admin-notif-done-banner {
      max-width: 1200px;
      margin: 0 auto 14px;
      padding: 12px 16px;
      border-radius: 12px;
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
      font-size: .95rem;
      font-weight: 600;
    }
    .admin-notif-done-banner--err {
      background: #fef2f2;
      color: #b91c1c;
      border-color: #fecaca;
    }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-notif-wrap">
  <div class="admin-notif-head">
    <h1 class="admin-notif-title"><i class="bi bi-bell-fill" aria-hidden="true"></i> คำขอรออนุมัติ</h1>
    <span class="admin-notif-total">ทั้งหมด <?php echo (int)$totalAll; ?> รายการ</span>
  </div>
  <?php if ($doneBanner !== ''): ?>
    <div class="admin-notif-done-banner<?= isset($_GET['err']) ? ' admin-notif-done-banner--err' : '' ?>"><?= htmlspecialchars($doneBanner) ?></div>
  <?php endif; ?>

  <div class="admin-notif-grid">
    <section class="admin-notif-card" id="admin-pending-foundations">
      <div class="admin-notif-card-head">
        <h2 class="admin-notif-card-title"><i class="bi bi-building" aria-hidden="true"></i> มูลนิธิ / มูลนิธิโปรไฟล์</h2>
        <span class="admin-notif-count"><?php echo count($foundationPendings); ?></span>
      </div>
      <ul class="admin-notif-list">
        <?php if (empty($foundationPendings)): ?>
          <li class="admin-notif-empty">ไม่มีคำขอใหม่</li>
        <?php else: ?>
          <?php foreach ($foundationPendings as $f): ?>
            <li class="admin-notif-item">
              <strong><?php echo htmlspecialchars($f['foundation_name'] ?? 'ไม่ระบุชื่อมูลนิธิ'); ?></strong>
              <div class="admin-notif-meta">สมัครเมื่อ: <?php echo !empty($f['created_at']) ? date('d/m/Y H:i', strtotime($f['created_at'])) : '-'; ?></div>
              <div class="admin-notif-item-actions">
                <a class="admin-notif-link" href="admin_approve_foundation.php?id=<?php echo (int)$f['foundation_id']; ?>">ตรวจสอบ</a>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </section>

    <section class="admin-notif-card" id="admin-pending-children">
      <div class="admin-notif-card-head">
        <h2 class="admin-notif-card-title"><i class="bi bi-person-badge" aria-hidden="true"></i> โปรไฟล์เด็ก</h2>
        <span class="admin-notif-count"><?php echo count($childrenPendings); ?></span>
      </div>
      <ul class="admin-notif-list">
        <?php if (empty($childrenPendings)): ?>
          <li class="admin-notif-empty">ไม่มีโปรไฟล์เด็กรออนุมัติ</li>
        <?php else: ?>
          <?php foreach ($childrenPendings as $c): ?>
            <li class="admin-notif-item">
              <strong><?php echo htmlspecialchars($c['child_name'] ?? 'ไม่ระบุชื่อ'); ?></strong>
              <div class="admin-notif-meta">มูลนิธิ: <?php echo htmlspecialchars($c['foundation_name'] ?? '-'); ?> | สถานะ: <?php echo htmlspecialchars($c['approve_profile'] ?? 'รอดำเนินการ'); ?></div>
              <div class="admin-notif-item-actions">
                <a class="admin-notif-link" href="children_donate.php?id=<?php echo (int)$c['child_id']; ?>">ตรวจสอบ</a>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </section>

    <section class="admin-notif-card" id="admin-pending-projects">
      <div class="admin-notif-card-head">
        <h2 class="admin-notif-card-title"><i class="bi bi-kanban" aria-hidden="true"></i> โครงการ</h2>
        <span class="admin-notif-count"><?php echo count($projectPendings); ?></span>
      </div>
      <ul class="admin-notif-list">
        <?php if (empty($projectPendings)): ?>
          <li class="admin-notif-empty">ไม่มีโครงการรออนุมัติ</li>
        <?php else: ?>
          <?php foreach ($projectPendings as $p): ?>
            <li class="admin-notif-item">
              <strong><?php echo htmlspecialchars($p['project_name'] ?? '-'); ?></strong>
              <div class="admin-notif-meta">มูลนิธิ: <?php echo htmlspecialchars($p['foundation_name'] ?? '-'); ?> | ปิดรับ: <?php echo htmlspecialchars($p['end_date'] ?? '-'); ?></div>
              <div class="admin-notif-item-actions">
                <a class="admin-notif-link" href="admin_approve_projects.php?id=<?php echo (int)$p['project_id']; ?>">ตรวจสอบ</a>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </section>

    <section class="admin-notif-card" id="admin-pending-needs">
      <div class="admin-notif-card-head">
        <h2 class="admin-notif-card-title"><i class="bi bi-box-seam" aria-hidden="true"></i> มูลนิธิสิ่งของที่ต้องการ</h2>
        <span class="admin-notif-count"><?php echo count($needPendings); ?></span>
      </div>
      <ul class="admin-notif-list">
        <?php if (empty($needPendings)): ?>
          <li class="admin-notif-empty">ไม่มีรายการสิ่งของรออนุมัติ</li>
        <?php else: ?>
          <?php foreach ($needPendings as $n): ?>
            <li class="admin-notif-item">
              <strong><?php echo htmlspecialchars($n['item_name'] ?? '-'); ?></strong>
              <div class="admin-notif-meta">มูลนิธิ: <?php echo htmlspecialchars($n['foundation_name'] ?? '-'); ?><?php echo ((int)($n['urgent'] ?? 0) === 1) ? ' | ด่วน' : ''; ?><?php
                $needCAt = trim((string)($n['created_at'] ?? ''));
                if ($needCAt !== '' && $needCAt !== '0000-00-00 00:00:00') {
                    echo ' | เสนอเมื่อ: ' . htmlspecialchars(date('d/m/Y H:i', strtotime($needCAt)));
                }
              ?></div>
              <div class="admin-notif-item-actions">
                <a class="admin-notif-link" href="admin_needlist_view.php?item_id=<?php echo (int)$n['item_id']; ?>">ตรวจสอบ</a>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </section>
  </div>
</div>

</body>
</html>
