<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

$is_verified = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && isset($_SESSION['account_verified']) && $_SESSION['account_verified'] == 1);

$foundations = mysqli_query($conn, "SELECT * FROM foundation_profile ORDER BY foundation_id DESC");
if (!$foundations) die("Query foundations failed: " . mysqli_error($conn));

$donationTotals = [];
$q = mysqli_query($conn, "
    SELECT fp.foundation_id, COALESCE(SUM(d.amount),0) AS total
    FROM foundation_profile fp
    LEFT JOIN donate_category dc ON dc.needitem_donate IS NOT NULL
    LEFT JOIN donation d ON d.category_id = dc.category_id AND d.payment_status = 'completed'
    GROUP BY fp.foundation_id
");
if ($q) while ($r = mysqli_fetch_assoc($q)) $donationTotals[(int)$r['foundation_id']] = (float)$r['total'];

$goalTotals = [];
$q2 = mysqli_query($conn, "SELECT foundation_id, COALESCE(SUM(total_price),0) AS goal FROM foundation_needlist WHERE approve_item='approved' GROUP BY foundation_id");
if ($q2) while ($r = mysqli_fetch_assoc($q2)) $goalTotals[(int)$r['foundation_id']] = (float)$r['goal'];

$stmtAll = $conn->prepare("SELECT item_id, item_name, qty_needed, price_estimate, urgent, item_image FROM foundation_needlist WHERE foundation_id=? AND approve_item='approved' ORDER BY urgent DESC, item_id DESC LIMIT 3");
if (!$stmtAll) die("Prepare failed: " . $conn->error);

?>
<!DOCTYPE html>
<html lang="th">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>มูลนิธิ | DrawDream</title>
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/foundation.css?v=3">
</head>

<body class="foundation-page">

  <?php include 'navbar.php'; ?>

  <div class="page-wrap">

    <div class="top-bar">
      <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <a href="admin_approve_needlist.php" class="admin-btn">ไปหน้าอนุมัติรายการ</a>
      <?php endif; ?>
      <?php if (($_SESSION['role'] ?? '') === 'foundation'): ?>
        <?php if ($is_verified): ?>
          <a href="foundation_add_need.php" class="btn-propose">เสนอรายการสิ่งของ</a>
        <?php else: ?>
          <span style="color:#E8A020; font-size:13px;">รอการอนุมัติก่อนจึงจะเสนอรายการสิ่งของได้</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>

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
              <div class="bar">
                <div style="width:<?= (int)$percent ?>%"></div>
              </div>
              <div class="amount">ยอดปัจจุบัน <?= number_format($current, 0) ?> / <?= number_format($goal, 0) ?> บาท</div>
              <div class="items">
                <?php foreach ($items as $it): ?>
                  <div class="item">
                    <?php if ((int)$it['urgent'] === 1): ?>
                      <div class="urgent-tag">ต้องการด่วน</div>
                    <?php endif; ?>
                    <?php if (!empty($it['item_image'])): ?>
                      <img class="item-img" src="uploads/needs/<?= htmlspecialchars($it['item_image']) ?>" alt="">
                    <?php else: ?>
                      <div class="noimg">ไม่มีรูปภาพ</div>
                    <?php endif; ?>
                    <div class="item-name"><?= htmlspecialchars($it['item_name']) ?></div>
                    <div class="item-meta">
                      ต้องการ: <?= (int)$it['qty_needed'] ?> ชิ้น<br>
                      ราคา/หน่วย: <?= number_format((float)$it['price_estimate'], 0) ?> บาท
                    </div>
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
      <?php if (!$hasAny): ?>
        <p style="text-align:center; color:#666;">ยังไม่มีมูลนิธิที่มีรายการสิ่งของในระบบ</p>
      <?php endif; ?>
    </div>
  </div>

</body>

</html>