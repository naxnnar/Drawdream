<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
  session_start();
}

// นับมูลนิธิที่รออนุมัติ (เฉพาะ admin)
$pending_count = 0;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
  include_once 'db.php';
  $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_profile WHERE account_verified = 0");
  if ($r) $pending_count = mysqli_fetch_assoc($r)['cnt'];
}
$_nav_depth = substr_count(str_replace(dirname(str_replace('\\','/',__FILE__)), '', str_replace('\\','/',dirname($_SERVER['SCRIPT_FILENAME']))), '/');
$_nav_base = str_repeat('../', max(0, $_nav_depth));
?>
<link rel="stylesheet" href="<?= $_nav_base ?>css/navbar.css">
<nav class="navbar">

  <div class="nav-left">
    <a href="<?= $_nav_base ?>homepage.php" <?= basename($_SERVER['PHP_SELF']) == 'homepage.php' ? 'class="active"' : '' ?>>หน้าแรก</a>
    <a href="<?= $_nav_base ?>children_.php" <?= basename($_SERVER['PHP_SELF']) == 'children_.php' ? 'class="active"' : '' ?>>บริจาค</a>
    <a href="<?= $_nav_base ?>project.php" <?= basename($_SERVER['PHP_SELF']) == 'project.php' ? 'class="active"' : '' ?>>โครงการ</a>
    <a href="<?= $_nav_base ?>foundation.php" <?= basename($_SERVER['PHP_SELF']) == 'foundation.php' ? 'class="active"' : '' ?>>มูลนิธิ</a>
    <a href="<?= $_nav_base ?>about.php" <?= basename($_SERVER['PHP_SELF']) == 'about.php' ? 'class="active"' : '' ?>>เกี่ยวกับเรา</a>
  </div>

  <div class="nav-center">
    <a href="<?= $_nav_base ?>homepage.php">
      <img src="<?= $_nav_base ?>img/logobig.png" class="nav-logo" alt="DrawDream Logo">
    </a>
  </div>

  <div class="nav-right">
    <?php if (isset($_SESSION['email'])): ?>

      <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="<?= $_nav_base ?>admin_approve_foundation.php" class="notif-btn" title="มูลนิธิรออนุมัติ">
          <img src="<?= $_nav_base ?>img/bell.png" alt="แจ้งเตือน" class="nav-icon">
          <?php if ($pending_count > 0): ?>
            <span class="notif-badge"><?= $pending_count ?></span>
          <?php endif; ?>
        </a>
      <?php endif; ?>

      <a href="<?= $_nav_base ?>profile.php" class="profile-btn">
        <img src="<?= $_nav_base ?>img/user.png" alt="โปรไฟล์" class="nav-icon">
      </a>
      <a href="<?= $_nav_base ?>logout.php" class="logout-btn">ออกจากระบบ</a>

    <?php else: ?>
      <a href="<?= $_nav_base ?>login.php" class="logout-btn">เข้าสู่ระบบ</a>
    <?php endif; ?>
  </div>

</nav>