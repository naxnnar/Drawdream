<?php
// ไฟล์นี้: foundation.php
// หน้าที่: หน้ารายการมูลนิธิและรายการสิ่งของที่ต้องการ
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

$is_verified = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && isset($_SESSION['account_verified']) && $_SESSION['account_verified'] == 1);

// ถ้า foundation role ให้ดึงเฉพาะมูลนิธิของตัวเอง ถ้าไม่ใช่ให้ดึงทั้งหมด
if (($_SESSION['role'] ?? '') === 'foundation') {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile WHERE user_id = $userId ORDER BY foundation_id DESC");
} else {
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile ORDER BY foundation_id DESC");
}
if (!$foundations) die("Query foundations failed: " . mysqli_error($conn));

// ✅ แก้แล้ว — ดึงยอด current_donate จาก foundation_needlist แยกตาม foundation_id
$donationTotals = [];
$q = mysqli_query($conn, "
    SELECT foundation_id, COALESCE(SUM(current_donate), 0) AS total
    FROM foundation_needlist
    WHERE approve_item = 'approved'
    GROUP BY foundation_id
");
if ($q) while ($r = mysqli_fetch_assoc($q)) $donationTotals[(int)$r['foundation_id']] = (float)$r['total'];

$goalTotals = [];
$q2 = mysqli_query($conn, "SELECT foundation_id, COALESCE(SUM(total_price),0) AS goal FROM foundation_needlist WHERE approve_item='approved' GROUP BY foundation_id");
if ($q2) while ($r = mysqli_fetch_assoc($q2)) $goalTotals[(int)$r['foundation_id']] = (float)$r['goal'];

$stmtAll = $conn->prepare("SELECT item_id, item_name, qty_needed, price_estimate, urgent, item_image FROM foundation_needlist WHERE foundation_id=? AND approve_item='approved' ORDER BY urgent DESC, item_id DESC LIMIT 3");
if (!$stmtAll) die("Prepare failed: " . $conn->error);

// ดึงรายการสิ่งของที่เสนอทั้งหมด (สำหรับ foundation role)
$myNeedlist = [];
if (($_SESSION['role'] ?? '') === 'foundation') {
    // ดึง foundation_id จาก foundation_profile ก่อน
    $rowFp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT foundation_id FROM foundation_profile WHERE user_id = $userId LIMIT 1"));
    $myFoundationId = (int)($rowFp['foundation_id'] ?? 0);

    if ($myFoundationId > 0) {
        $stmtMine = $conn->prepare("
            SELECT item_id, item_name, item_desc, brand, price_estimate, total_price, urgent, item_image, approve_item, note
            FROM foundation_needlist
            WHERE foundation_id = ?
            ORDER BY item_id DESC
        ");
        if ($stmtMine) {
            $stmtMine->bind_param("i", $myFoundationId);
            $stmtMine->execute();
            $myNeedlist = $stmtMine->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>มูลนิธิ | DrawDream</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/foundation.css?v=6">
</head>
<body class="foundation-page">

  <?php include 'navbar.php'; ?>

  <div class="page-wrap">

    <?php if (($_SESSION['role'] ?? '') === 'foundation'): ?>
      <div class="foundation-owner-head">
        <h1>มูลนิธิของเรา</h1>
        <p>จัดการรายการสิ่งของที่ต้องการได้จากหน้านี้</p>

        <div class="foundation-owner-toolbar">
          <?php if ($is_verified): ?>
            <a href="foundation_add_need.php" class="foundation-owner-btn foundation-owner-btn-need">เสนอรายการสิ่งของ</a>
          <?php else: ?>
            <span class="foundation-owner-warn">รอการอนุมัติก่อนจึงจะเสนอรายการสิ่งของได้</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="my-needlist-section">
        <h3 class="my-needlist-title">รายการสิ่งของที่เสนอทั้งหมด</h3>
        <?php if (empty($myNeedlist)): ?>
          <p style="color:#888; font-family:'Sarabun',sans-serif;">ยังไม่มีรายการที่เสนอ หรือยังไม่ถูกบันทึก</p>
        <?php endif; ?>
        <div class="my-needlist-grid">
          <?php foreach ($myNeedlist as $nl): ?>
            <?php
              $status = $nl['approve_item'] ?? 'pending';
              $nlImages = array_values(array_filter(explode('|', (string)($nl['item_image'] ?? ''))));
              $nlImg = $nlImages[0] ?? '';
              // ดึงระยะเวลาจาก note
              $nlNote = $nl['note'] ?? '';
              $nlPeriod = '';
              if (preg_match('/^ระยะเวลา:\s*(.+)/u', $nlNote, $pm)) {
                $nlPeriod = trim($pm[1]);
              }
              $statusLabel = ['pending' => 'รอการอนุมัติ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'][$status] ?? $status;
              $statusClass = ['pending' => 'status-pending', 'approved' => 'status-approved', 'rejected' => 'status-rejected'][$status] ?? 'status-pending';
            ?>
            <div class="need-card">
              <div class="need-card-img-wrap">
                <?php if ($nlImg): ?>
                  <img src="uploads/needs/<?= htmlspecialchars($nlImg) ?>" alt="" class="need-card-img">
                <?php else: ?>
                  <div class="need-card-noimg">ไม่มีรูป</div>
                <?php endif; ?>
                <?php if ((int)$nl['urgent'] === 1): ?>
                  <span class="need-urgent-badge">ต้องการด่วน</span>
                <?php endif; ?>
              </div>
              <div class="need-card-body">
                <span class="need-status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                <div class="need-card-name"><?= htmlspecialchars($nl['item_name']) ?></div>
                <?php if ($nl['brand']): ?>
                  <div class="need-card-cat"><?= htmlspecialchars($nl['brand']) ?></div>
                <?php endif; ?>
                <div class="need-card-goal">
                  เป้าหมาย: <?= number_format((float)($nl['total_price'] ?: $nl['price_estimate']), 0) ?> บาท
                  <?php if ($nlPeriod): ?><span class="need-period">/<?= htmlspecialchars($nlPeriod) ?></span><?php endif; ?>
                </div>
                <?php if ($nl['item_desc']): ?>
                  <div class="need-card-desc"><?= htmlspecialchars($nl['item_desc']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php else: ?>
      <div class="top-bar">
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          <a href="admin_approve_needlist.php" class="admin-btn">ไปหน้าอนุมัติรายการ</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (($_SESSION['role'] ?? '') !== 'foundation'): ?>
    <div class="foundation-list">
      <?php
      $hasAny = false;
      if ($foundations && mysqli_num_rows($foundations) > 0):
        while ($f = mysqli_fetch_assoc($foundations)):
          $fid = (int)$f['foundation_id'];
          $stmtAll->bind_param("i", $fid);
          $stmtAll->execute();
          $res = $stmtAll->get_result();
          $items = [];
          while ($row = $res->fetch_assoc()) $items[] = $row;
          if (count($items) === 0) continue;
          $hasAny = true;
          $current = $donationTotals[$fid] ?? 0;
          $goal = $goalTotals[$fid] ?? 0;
          $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
          $foundationImage = $f['foundation_image'] ?? '';
          $facebookUrl = $f['facebook_url'] ?? '';
      ?>
          <div class="foundation-card" id="f<?= $fid ?>">
            <div class="fc-left">
              <h2 class="fc-title"><?= htmlspecialchars($f['foundation_name'] ?? 'มูลนิธิ') ?></h2>
              <p class="fc-desc"><?= htmlspecialchars($f['foundation_desc'] ?? '') ?></p>
              <?php
                $urgentItems = array_filter($items, function($it) { return (int)($it['urgent'] ?? 0) === 1; });
                if (count($urgentItems) > 0):
              ?>
                <div class="urgent-list mb-3" style="color:#b84a34;font-weight:600;">
                  <span style="font-size:1.1em;">สิ่งของที่ต้องการด่วน:</span>
                  <ul style="margin:8px 0 0 18px;padding:0;">
                  <?php foreach ($urgentItems as $u): ?>
                    <li><?= htmlspecialchars($u['item_name']) ?></li>
                  <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <div class="bar">
                <div style="width:<?= (int)$percent ?>%"></div>
              </div>
              <div class="amount">ยอดปัจจุบัน <?= number_format($current, 0) ?> / <?= number_format($goal, 0) ?> บาท</div>
              <div class="items">
                <?php foreach ($items as $it): ?>
                  <?php
                    $itemImages = array_values(array_filter(explode('|', (string)($it['item_image'] ?? ''))));
                    $mainItemImage = $itemImages[0] ?? '';
                  ?>
                  <div class="item">
                    <?php if ($mainItemImage !== ''): ?>
                      <img class="item-img" src="uploads/needs/<?= htmlspecialchars($mainItemImage) ?>" alt="" style="width: 80px; height: 80px; object-fit: cover; display:block; margin:auto;">
                    <?php else: ?>
                      <div class="noimg" style="width:80px;height:80px;display:flex;align-items:center;justify-content:center;background:#f3f3f3;color:#aaa;">ไม่มีรูปภาพ</div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <a class="btn-donate" href="payment/foundation_donate.php?fid=<?= $fid ?>">บริจาค</a>
            </div>
            <div class="fc-right">
              <?php if (!empty($foundationImage)): ?>
                <img class="cover" src="uploads/profiles/<?= htmlspecialchars($foundationImage) ?>" alt="รูปมูลนิธิ">
              <?php else: ?>
                <div class="cover-empty">ยังไม่มีรูปมูลนิธิ</div>
              <?php endif; ?>
              <?php if (!empty($facebookUrl)): ?>
                <div class="fb">Facebook: <?= htmlspecialchars($facebookUrl) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</body>
</html>