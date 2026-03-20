<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
  session_start();
}

// นับรายการรออนุมัติ (เฉพาะ admin)
$pending_count    = 0;
$pending_projects = 0;
$pending_needs    = 0;

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
  include_once 'db.php';

  $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_profile WHERE account_verified = 0");
  if ($r) $pending_count = mysqli_fetch_assoc($r)['cnt'];

  $r2 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM project WHERE project_status = 'pending'");
  if ($r2) $pending_projects = mysqli_fetch_assoc($r2)['cnt'];

  $r3 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_needlist WHERE approve_item = 'pending'");
  if ($r3) $pending_needs = mysqli_fetch_assoc($r3)['cnt'];
}

$total_pending = $pending_count + $pending_projects + $pending_needs;

$_nav_depth = substr_count(str_replace(dirname(str_replace('\\','/',__FILE__)), '', str_replace('\\','/',dirname($_SERVER['SCRIPT_FILENAME']))), '/');
$_nav_base  = str_repeat('../', max(0, $_nav_depth));

// สลับโหมด admin/ปกติ
if (isset($_GET['admin_mode'])) {
  $_SESSION['admin_mode'] = $_GET['admin_mode'] === '1';
  // redirect ออก param
  $redirect = strtok($_SERVER['REQUEST_URI'], '?');
  header("Location: $redirect");
  exit();
}

$is_admin_mode = ($_SESSION['role'] ?? '') === 'admin' && ($_SESSION['admin_mode'] ?? true);
?>
<link rel="stylesheet" href="<?= $_nav_base ?>css/navbar.css">
<nav class="navbar">

  <div class="nav-left">
    <?php if ($is_admin_mode): ?>
      <!-- เมนู Admin -->
      <a href="<?= $_nav_base ?>admin_dashboard.php" <?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'class="active"' : '' ?>>Dashboard</a>
      <a href="<?= $_nav_base ?>admin_approve_foundation.php" <?= basename($_SERVER['PHP_SELF']) == 'admin_approve_foundation.php' ? 'class="active"' : '' ?>>
        อนุมัติมูลนิธิ<?php if ($pending_count > 0): ?> <span class="menu-badge"><?= $pending_count ?></span><?php endif; ?>
      </a>
      <a href="<?= $_nav_base ?>admin_approve_projects.php" <?= basename($_SERVER['PHP_SELF']) == 'admin_approve_projects.php' ? 'class="active"' : '' ?>>
        อนุมัติโครงการ<?php if ($pending_projects > 0): ?> <span class="menu-badge"><?= $pending_projects ?></span><?php endif; ?>
      </a>
      <a href="<?= $_nav_base ?>admin_approve_needlist.php" <?= basename($_SERVER['PHP_SELF']) == 'admin_approve_needlist.php' ? 'class="active"' : '' ?>>
        อนุมัติสิ่งของ<?php if ($pending_needs > 0): ?> <span class="menu-badge"><?= $pending_needs ?></span><?php endif; ?>
      </a>
      <a href="<?= $_nav_base ?>admin_escrow.php" <?= basename($_SERVER['PHP_SELF']) == 'admin_escrow.php' ? 'class="active"' : '' ?>>Escrow</a>
    <?php else: ?>
      <!-- เมนูปกติ -->
      <a href="<?= $_nav_base ?>homepage.php" <?= basename($_SERVER['PHP_SELF']) == 'homepage.php' ? 'class="active"' : '' ?>>หน้าแรก</a>
      <a href="<?= $_nav_base ?>children_.php" <?= basename($_SERVER['PHP_SELF']) == 'children_.php' ? 'class="active"' : '' ?>>บริจาค</a>
      <a href="<?= $_nav_base ?>project.php" <?= basename($_SERVER['PHP_SELF']) == 'project.php' ? 'class="active"' : '' ?>>โครงการ</a>
      <a href="<?= $_nav_base ?>foundation.php" <?= basename($_SERVER['PHP_SELF']) == 'foundation.php' ? 'class="active"' : '' ?>>มูลนิธิ</a>
      <a href="<?= $_nav_base ?>about.php" <?= basename($_SERVER['PHP_SELF']) == 'about.php' ? 'class="active"' : '' ?>>เกี่ยวกับเรา</a>
    <?php endif; ?>
  </div>

  <div class="nav-center">
    <a href="<?= $_nav_base ?>homepage.php">
      <img src="<?= $_nav_base ?>img/logobig.png" class="nav-logo" alt="DrawDream Logo">
    </a>
  </div>

  <div class="nav-right">
    <?php if (isset($_SESSION['email'])): ?>

      <?php if ($_SESSION['role'] === 'admin'): ?>
        <!-- ปุ่มสลับโหมด -->
        <?php if ($is_admin_mode): ?>
          <a href="?admin_mode=0" class="mode-toggle-btn">โหมดปกติ</a>
        <?php else: ?>
          <a href="?admin_mode=1" class="mode-toggle-btn mode-admin">โหมดแอดมิน</a>
        <?php endif; ?>

        <!-- ไอคอนแจ้งเตือนรวม -->
        <?php if ($total_pending > 0): ?>
          <a href="<?= $_nav_base ?>admin_dashboard.php" class="notif-btn" title="มีรายการรออนุมัติ">
            <img src="<?= $_nav_base ?>img/bell.png" alt="แจ้งเตือน" class="nav-icon">
            <span class="notif-badge"><?= $total_pending ?></span>
          </a>
        <?php endif; ?>
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