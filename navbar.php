<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// นับมูลนิธิที่รออนุมัติ (เฉพาะ admin)
$pending_count = 0;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
  include_once 'db.php';
  $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_profile WHERE account_verified = 0");
  if ($r) $pending_count = mysqli_fetch_assoc($r)['cnt'];
}
?>
<link rel="stylesheet" href="css/navbar.css">
<nav class="navbar">

  <div class="nav-left">
    <a href="homepage.php" <?= basename($_SERVER['PHP_SELF']) == 'homepage.php' ? 'class="active"' : '' ?>>หน้าแรก</a>
    <a href="donation.php" <?= basename($_SERVER['PHP_SELF']) == 'donation.php' ? 'class="active"' : '' ?>>บริจาค</a>
    <a href="p2_project.php" <?= basename($_SERVER['PHP_SELF']) == 'p2_project.php' ? 'class="active"' : '' ?>>โครงการ</a>
    <a href="foundation.php" <?= basename($_SERVER['PHP_SELF']) == 'foundation.php' ? 'class="active"' : '' ?>>มูลนิธิ</a>
    <a href="about.php" <?= basename($_SERVER['PHP_SELF']) == 'about.php' ? 'class="active"' : '' ?>>เกี่ยวกับเรา</a>
  </div>

  <div class="nav-center">
    <a href="homepage.php">
      <img src="img/logobig.png" class="nav-logo" alt="DrawDream Logo">
    </a>
  </div>

  <div class="nav-right">
    <?php if (isset($_SESSION['email'])): ?>

      <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="admin_approve_foundation.php" class="notif-btn" title="มูลนิธิรออนุมัติ">
          <img src="img/bell.png" alt="แจ้งเตือน" class="nav-icon">
          <?php if ($pending_count > 0): ?>
            <span class="notif-badge"><?= $pending_count ?></span>
          <?php endif; ?>
        </a>
      <?php endif; ?>

      <a href="profile.php" class="profile-btn">
        <img src="img/user.png" alt="โปรไฟล์" class="nav-icon">
      </a>
      <a href="logout.php" class="logout-btn">ออกจากระบบ</a>

    <?php else: ?>
      <a href="index.php" class="logout-btn">เข้าสู่ระบบ</a>
    <?php endif; ?>
  </div>

</nav>