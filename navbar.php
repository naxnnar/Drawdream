<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
?>

<nav class="navbar">

  <div class="nav-left">
    <a href="homepage.php">หน้าแรก</a>
    <a href="donation.php">บริจาค</a>
    <a href="p2_project.php">โครงการ</a>
    <a href="foundation.php">มูลนิธิ</a>
    <a href="about.php">เกี่ยวกับเรา</a>
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