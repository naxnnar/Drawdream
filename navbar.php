<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
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
    <a href="p1_home.php">
      <img src="img/logobig.png" class="nav-logo" alt="DrawDream Logo">
    </a>
  </div>

  <div class="nav-right">
    <?php if (isset($_SESSION['email'])): ?>
      <a href="profile.php" class="profile-btn">👤</a>
      <a href="logout.php" class="logout-btn">ออกจากระบบ</a>
    <?php else: ?>
      <a href="login.php?as=donor" class="logout-btn">เข้าสู่ระบบ</a>
    <?php endif; ?>
  </div>

</nav>