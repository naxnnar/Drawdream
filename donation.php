<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php'; // เชื่อมต่อฐานข้อมูล

$role = $_SESSION['role'] ?? 'donor';
$user_id = $_SESSION['user_id'] ?? 0;

$sqls = "SELECT * FROM `foundation_profile` WHERE `user_id` = ?";
$stmtFP = $conn->prepare($sqls);
$stmtFP->bind_param("i", $_SESSION['user_id']);
$stmtFP->execute();

$result = $stmtFP->get_result(); 
$FPArr = $result->fetch_assoc();

if ($role == 'donor') {
  $sql = "SELECT * FROM Children WHERE  approve_profile = 'อนุมัติ' ORDER BY child_id DESC";
}elseif ($role == 'foundation') {
  $sql = "SELECT * FROM Children WHERE  foundation_id = ".$FPArr['foundation_id']." ORDER BY child_id DESC";
}else{
  $sql = "SELECT * FROM Children ORDER BY child_id DESC";
}
$result = $conn->query($sql);

// แยกกลุ่มเด็กตามสถานะเพื่อนำไปแสดงผล
$unadopted = [];
$adopted = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($row['status'] == 'มีผู้อุปการะแล้ว') {
            $adopted[] = $row;
        } else {
            $unadopted[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>บริจาคให้เด็กรายบุคคล | DrawDream</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/navbar.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <!-- link css -->
  <link rel="stylesheet" href="css/style.css">
  <style>
    /* FIX: footer row */
    .footer-wrap .row {
      display: flex !important;
      flex-wrap: wrap !important;
      flex-direction: row !important;
      gap: 0 !important;
    }
    .footer-wrap .container {
      background: transparent !important;
    }
    .footer-wrap p,
    .footer-wrap h5,
    .footer-wrap span,
    .footer-wrap div {
      color: rgba(255,255,255,0.9);
    }

    body.donation-page {
      background: #fff;
      overflow-x: hidden;
    }

    .donation-shell {
      max-width: 1400px;
      margin: 0 auto;
      padding: 12px 24px 56px;
      width: 100%;
      box-sizing: border-box;
    }

    .donation-shell .container {
      max-width: 100% !important;
      margin: 0;
      padding-left: 0;
      padding-right: 0;
      background: transparent !important;
      border-radius: 0;
      box-shadow: none;
    }

    .donation-band {
      width: 100vw;
      margin-left: calc(50% - 50vw);
      margin-right: calc(50% - 50vw);
      border-radius: 0;
      margin-bottom: 0;
    }

    .donation-top-actions {
      display: flex;
      justify-content: flex-end;
      margin-bottom: 12px;
    }

    .donation-grid {
      display: grid !important;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 22px 14px;
      justify-content: flex-start;
      margin-left: 0;
      margin-right: 0;
    }

    .donation-grid > [class*="col-"] {
      display: flex;
      width: auto;
      max-width: none;
      padding-left: 0;
      padding-right: 0;
    }

    .donation-grid .child-card {
      width: 100%;
    }

    .donation-grid .card-info {
      padding-top: 6px;
    }

    .donation-grid .card-img {
      border-radius: 16px;
    }

    .donation-grid .card-info h3 {
      font-size: 1.08rem;
      margin-top: 10px;
      margin-bottom: 6px;
    }

    .donation-grid .card-info p {
      font-size: 0.92rem;
      margin: 3px 0;
    }

    .child-status-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 6px 16px;
      margin-top: 8px;
      border-radius: 999px;
      font-size: 15px;
      font-weight: 700;
      line-height: 1;
      letter-spacing: 0.2px;
      width: auto;
      min-width: 136px;
      box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
    }

    .child-status-pill.status-approved {
      background: linear-gradient(135deg, #799677, #597D57);
      color: #fff;
    }

    .child-status-pill.status-pending {
      background: linear-gradient(135deg, #f7cc47, #e8b923);
      color: #3b2f09;
    }

    .child-status-pill.status-rejected {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: #fff;
    }

    @media (max-width: 1199.98px) {
      .donation-grid {
        grid-template-columns: repeat(5, minmax(0, 1fr));
      }
    }

    @media (max-width: 991.98px) {
      .donation-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }
    }

    @media (max-width: 767.98px) {
      .donation-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px 10px;
      }

      .donation-grid .card-info h3 {
        font-size: 1rem;
      }

      .donation-grid .card-info p {
        font-size: 0.86rem;
      }
    }

    @media (max-width: 575.98px) {
      .donation-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

  </style>

</head>
 
<body class="donation-page donation-role-<?php echo htmlspecialchars($role); ?>">

<?php include 'navbar.php'; ?>

<div class="donation-shell">

<div class="donation-top-actions">
  <div style="display:flex; gap:10px; align-items:center;">
      <?php if ($role === 'foundation'): ?>
          <a href="p2_2addprofile.php" class="btn btn-success">+ เพิ่มโปรไฟล์เด็ก</a>
      <?php endif; ?>
  </div>
</div>

<h2 class="section-title danger donation-band">เด็กที่ยังไม่มีผู้อุปการะ</h2>

<div class="container py-4">
  <div class="row donation-grid row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-6 g-4">
    <?php foreach ($unadopted as $child): ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <a href="profile-child.php?id=<?php echo $child['child_id']; ?>" class="child-card">
        <div class="card-img danger-bg">
          <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="รูปเด็ก">
        </div>
        <div class="card-info">
            <h3><?php echo htmlspecialchars($child['child_name']); ?></h3>
            <p><i class="bi bi-cake-fill"></i> <?php echo $child['age']; ?> ปี</p>
            <p><i class="bi bi-briefcase-fill"></i> <?php echo htmlspecialchars($child['dream']); ?></p>
            <p><i class="bi bi-building"></i> <?php echo htmlspecialchars($child['foundation_name'] ?? '-'); ?></p>
            <?php if ($role === 'foundation' || $role === 'admin'): ?>
                <?php
                  $statusText = $child['approve_profile'] ?? 'รอดำเนินการ';
                  if ($statusText === 'กำลังดำเนินการ') {
                    $statusText = 'รอดำเนินการ';
                  }
                  $statusClass = 'status-pending';
                  if ($statusText == 'อนุมัติ') {
                    $statusClass = 'status-approved';
                    $statusText = 'อนุมัติแล้ว';
                  } elseif ($statusText == 'ไม่อนุมัติ') {
                    $statusClass = 'status-rejected';
                  }
                ?>
                <div class="child-status-pill <?php echo $statusClass; ?>">
                  <?php echo $statusText; ?>
                </div>
            <?php endif; ?>
        </div>
        
      </a>
    </div>
    <?php endforeach; ?>
    
  </div>
</div>

<h2 class="section-title success donation-band">เด็กที่มีผู้อุปการะ</h2>

<div class="container py-4">
  <div class="row donation-grid row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-6 g-4">
    <?php foreach ($adopted as $child): ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <a href="profile-renni.html" class="child-card">
        <div class="card-img success-bg">
          <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="รูปเด็ก">
        </div>
        <div class="card-info">
            <h3><?php echo htmlspecialchars($child['child_name']); ?></h3>
            <p><i class="bi bi-cake-fill"></i> <?php echo $child['age']; ?> ปี</p>
            <p><i class="bi bi-briefcase-fill"></i> <?php echo htmlspecialchars($child['dream']); ?></p>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <a href="profile-renni.html" class="child-card">
      <!-- <a href="profile-focus.html" class="child-card"> -->
        <div class="card-img success-bg"><img src="img/focus.png" alt="โฟกัส"></div>
        <div class="card-info">
            <h3>โฟกัส</h3>
            <p><i class="bi bi-cake-fill"></i> 9 ปี</p>
            <p><i class="bi bi-briefcase-fill"></i> ดารา</p>
        </div>
      </a>
    </div>
    

  </div>
</div>
</div>
<div class="footer-wrap page-section" style="background-color:#3f4f9a;">
  <footer style="background-color:#3f4f9a;">
    <div class="container py-4" style="background-color:#3f4f9a;">
      <div class="row text-light">
        <div class="col-md-6 mb-4">
          <img src="img/logobanner.png" alt="DrawDream logo" class="mb-3">
          <p class="text-light">
            ร่วมบริจาคเพื่อช่วยเหลือเด็กได้ที่<br>
            ธนาคารไทยพาณิชย์<br>
            เลขที่บัญชี <span style="color:#f4c948; font-weight:bold;">011-1-11111-1</span>
          </p>
        </div>
        <div class="col-md-6 mb-4">
          <h5 class="text-center mb-3 text-light">ติดต่อเรา</h5>
          <p class="text-light">
            <i class="bi bi-geo-alt-fill me-2"></i>
            ชั้น 3 อาคาร Drawdream ถนนพหลโยธิน แขวงพญาไท เขตพญาไท กรุงเทพมหานคร 10400
          </p>
          <div class="d-flex justify-content-center gap-4 mb-3">
            <span class="text-light"><i class="bi bi-telephone-fill me-1"></i> 0949278518</span>
            <span class="text-light"><i class="bi bi-printer-fill me-1"></i> 0123456789</span>
          </div>
          <div class="d-flex justify-content-center gap-2">
            <a href="#" class="btn btn-light btn-sm"><i class="bi bi-facebook"></i></a>
            <a href="#" class="btn btn-light btn-sm"><i class="bi bi-tiktok"></i></a>
            <a href="#" class="btn btn-light btn-sm"><i class="bi bi-instagram"></i></a>
            <a href="#" class="btn btn-light btn-sm"><i class="bi bi-youtube"></i></a>
          </div>
        </div>
      </div>
      <hr style="border-color:rgba(255,255,255,0.25);">
      <p class="text-center text-light mb-0 small" style="opacity:0.7;">&copy; All right reserved 2025 WVFT</p>
    </div>
  </footer>
</div>

<script src="Drawdream/main.js"></script>
</body>
</html>
