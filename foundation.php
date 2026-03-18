<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

$is_verified = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && isset($_SESSION['account_verified']) && $_SESSION['account_verified'] == 1);

// filter หมวดหมู่
$cat = $_GET['cat'] ?? 'all';
$allowedCats = ['all','เด็กเล็ก','เด็กพิการ'];
if (!in_array($cat, $allowedCats, true)) $cat = 'all';

// ดึงมูลนิธิทั้งหมด
$foundations = mysqli_query($conn, "SELECT * FROM foundation_profile ORDER BY foundation_id DESC");
if (!$foundations) die("Query foundations failed: " . mysqli_error($conn));

// ยอดเงินปัจจุบันของแต่ละมูลนิธิ
$donationTotals = [];
$q = mysqli_query($conn, "SELECT foundation_id, COALESCE(SUM(amount),0) AS total
                          FROM foundation_donations
                          WHERE status='verified'
                          GROUP BY foundation_id");
if ($q) {
  while($r = mysqli_fetch_assoc($q)) {
    $donationTotals[(int)$r['foundation_id']] = (float)$r['total'];
  }
}

// เป้าหมาย
$goalTotals = [];
$q2 = mysqli_query($conn, "SELECT foundation_id, COALESCE(SUM(total_price),0) AS goal
                           FROM foundation_needlist
                           WHERE status='approved'
                           GROUP BY foundation_id");
if ($q2) {
  while($r = mysqli_fetch_assoc($q2)) {
    $goalTotals[(int)$r['foundation_id']] = (float)$r['goal'];
  }
}

// เตรียม stmt ดึงรายการสิ่งของ
$sqlItemsAll = "SELECT item_id, item_name, qty_needed, price_estimate, urgent, item_image
                FROM foundation_needlist
                WHERE foundation_id=? AND status='approved'
                ORDER BY urgent DESC, item_id DESC
                LIMIT 3";

$sqlItemsByCat = "SELECT item_id, item_name, qty_needed, price_estimate, urgent, item_image
                  FROM foundation_needlist
                  WHERE foundation_id=? AND status='approved' AND category=?
                  ORDER BY urgent DESC, item_id DESC
                  LIMIT 3";

$stmtAll = $conn->prepare($sqlItemsAll);
$stmtCat = $conn->prepare($sqlItemsByCat);
if (!$stmtAll || !$stmtCat) die("Prepare failed: " . $conn->error);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>มูลนิธิ | DrawDream</title>
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/style.css?v=2">
  <style>
body {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: 100vh;
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.page-wrap {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 20px;
}

.foundation-actions {
    text-align: right;
    margin-bottom: 30px;
}

.btn-propose {
    background: #4A5BA8;
    color: white;
    padding: 12px 30px;
    border-radius: 25px;
    text-decoration: none;
    font-weight: 600;
    display: inline-block;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(74, 91, 168, 0.3);
}

.btn-propose:hover {
    background: #3d4d8f;
    transform: translateY(-2px);
}

.filter-row {
    display: flex;
    gap: 15px;
    margin-bottom: 40px;
    justify-content: center;
}

.chip {
    padding: 12px 30px;
    border: 2px solid #ddd;
    border-radius: 25px;
    text-decoration: none;
    color: #666;
    font-weight: 500;
    transition: all 0.3s;
    background: white;
}

.chip:hover {
    border-color: #4A5BA8;
    color: #4A5BA8;
}

.chip.active {
    background: #4A5BA8;
    color: white;
    border-color: #4A5BA8;
}

.foundation-list {
    display: flex;
    flex-direction: column;
    gap: 40px;
}

.foundation-card {
    background: white;
    border-radius: 20px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    overflow: hidden;
    display: grid;
    grid-template-columns: 1fr 500px;
    min-height: 400px;
}

.fc-left {
    background: #F5E6D3;
    padding: 40px;
}

.fc-right {
    background: white;
    padding: 40px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.fc-title {
    font-size: 32px;
    font-weight: bold;
    color: #333;
    margin: 0 0 15px 0;
}

.fc-desc {
    color: #666;
    line-height: 1.6;
    margin-bottom: 25px;
}

.bar {
    background: #E5D4C1;
    border-radius: 20px;
    height: 30px;
    overflow: hidden;
    margin-bottom: 10px;
}

.bar > div {
    background: linear-gradient(90deg, #E57373 0%, #EF5350 100%);
    height: 100%;
    transition: width 0.5s ease;
    border-radius: 20px;
}

.amount {
    font-weight: 600;
    color: #333;
    margin-bottom: 25px;
}

.items {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.item {
    background: white;
    border-radius: 15px;
    padding: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    position: relative;
    transition: transform 0.3s;
}

.item:hover {
    transform: translateY(-5px);
}

.urgent-tag {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #E57373;
    color: white;
    padding: 5px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
    z-index: 1;
}

.item-img {
    width: 100%;
    height: 120px;
    object-fit: contain;
    background: #f9f9f9;
    border-radius: 10px;
    margin-bottom: 12px;
}

.noimg {
    width: 100%;
    height: 120px;
    background: #f0f0f0;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
    margin-bottom: 12px;
}

.item-name {
    font-weight: 600;
    font-size: 14px;
    color: #333;
    margin-bottom: 8px;
}

.item-meta {
    font-size: 12px;
    color: #666;
    line-height: 1.5;
}

.btn-donate {
    background: #2196F3;
    color: white;
    padding: 18px 0;
    border-radius: 15px;
    font-size: 18px;
    font-weight: bold;
    text-align: center;
    text-decoration: none;
    display: block;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3);
}

.btn-donate:hover {
    background: #1976D2;
    transform: translateY(-2px);
}

.cover {
    max-width: 100%;
    width: 100%;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    max-height: 400px;
    object-fit: cover;
}

.cover-empty {
    width: 100%;
    height: 300px;
    background: linear-gradient(135deg, #e0e0e0 0%, #f5f5f5 100%);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
}

.fb {
    margin-top: 20px;
    color: #666;
    font-size: 14px;
    text-align: center;
}

.approve-container {
    text-align: center;
    margin: 30px 0;
}

.admin-btn {
    background: #4CAF50;
    color: white;
    padding: 12px 30px;
    border-radius: 25px;
    text-decoration: none;
    font-weight: 600;
    display: inline-block;
}

.admin-btn:hover {
    background: #45a049;
}

@media (max-width: 1024px) {
    .foundation-card {
        grid-template-columns: 1fr;
    }
    .items {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .items {
        grid-template-columns: 1fr;
    }
}
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
  <div class="approve-container">
    <a href="admin_needlist.php" class="admin-btn">ไปหน้าอนุมัติรายการ</a>
  </div>
<?php endif; ?>

<div class="page-wrap">
  <?php if (($_SESSION['role'] ?? '') === 'foundation'): ?>
    <div class="foundation-actions">
      <?php if ($is_verified): ?>
        <a href="foundation_add_need.php" class="btn-propose">เสนอรายการสิ่งของ</a>
      <?php else: ?>
        <span style="color:#E8A020; font-size:13px;"> รอการอนุมัติก่อนจึงจะเสนอรายการสิ่งของได้</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Filter -->
  <div class="filter-row">
    <a class="chip <?= ($cat==='all')?'active':'' ?>" href="foundation.php?cat=all">ทั้งหมด</a>
    <a class="chip <?= ($cat==='เด็กเล็ก')?'active':'' ?>" href="foundation.php?cat=เด็กเล็ก">เด็กเล็ก</a>
    <a class="chip <?= ($cat==='เด็กพิการ')?'active':'' ?>" href="foundation.php?cat=เด็กพิการ">เด็กพิการ</a>
  </div>

  <div class="foundation-list">
    <?php if ($foundations && mysqli_num_rows($foundations) > 0): ?>
      <?php while($f = mysqli_fetch_assoc($foundations)): ?>
        <?php
          $fid = (int)$f['foundation_id'];
          $current = $donationTotals[$fid] ?? 0;
          $goal = $goalTotals[$fid] ?? 0;
          $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;

          // ดึงรายการ
          $items = [];
          if ($cat === 'all') {
            $stmtAll->bind_param("i", $fid);
            $stmtAll->execute();
            $res = $stmtAll->get_result();
          } else {
            $stmtCat->bind_param("is", $fid, $cat);
            $stmtCat->execute();
            $res = $stmtCat->get_result();
          }
          while($row = $res->fetch_assoc()) $items[] = $row;

          // ดึงรูปโปรไฟล์มูลนิธิ (สำคัญ!)
          $foundationImage = $f['foundation_image'] ?? '';
          $facebookUrl = $f['facebook_url'] ?? '';
        ?>

        <div class="foundation-card" id="f<?= $fid ?>">
          <div class="fc-left">
            <h2 class="fc-title"><?= htmlspecialchars($f['foundation_name'] ?? 'มูลนิธิ') ?></h2>
            <p class="fc-desc"><?= htmlspecialchars($f['foundation_desc'] ?? '') ?></p>

            <div class="bar"><div style="width:<?= (int)$percent ?>%"></div></div>
            <div class="amount">ยอดปัจจุบัน <?= number_format($current,0) ?> / <?= number_format($goal,0) ?> บาท</div>

            <?php if (count($items) > 0): ?>
              <div class="items">
                <?php foreach($items as $it): ?>
                  <div class="item">
                    <?php if ((int)$it['urgent'] === 1): ?>
                      <div class="urgent-tag">ต้องการด่วน</div>
                    <?php endif; ?>

                    <?php if (!empty($it['item_image'])): ?>
                      <img class="item-img" src="uploads/needs/<?= htmlspecialchars($it['item_image']) ?>" alt="">
                    <?php else: ?>
                      <div class="noimg">📦</div>
                    <?php endif; ?>

                    <div class="item-name"><?= htmlspecialchars($it['item_name']) ?></div>
                    <div class="item-meta">
                      ต้องการ: <?= (int)$it['qty_needed'] ?> ชิ้น<br>
                      ราคา/หน่วย: <?= number_format((float)$it['price_estimate'],0) ?> บาท
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div style="margin-top:10px;color:#333;font-weight:700;">
                ยังไม่มีรายการที่อนุมัติในหมวดนี้
              </div>
            <?php endif; ?>

            <a class="btn-donate" href="foundation_donate.php?fid=<?= $fid ?>">บริจาค</a>
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
    <?php else: ?>
      <p style="text-align:center; color:#666;">ยังไม่มีมูลนิธิในระบบ</p>
    <?php endif; ?>
  </div>
</div>

</body>
</html>