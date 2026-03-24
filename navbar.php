<?php
// ไฟล์นี้: navbar.php
// หน้าที่: คอมโพเนนต์เมนูนำทางและแจ้งเตือน
if (session_status() === PHP_SESSION_NONE) {
  @session_start();
}

// นับรายการรออนุมัติ (เฉพาะ admin)
$pending_count    = 0;
$pending_children = 0;
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

  $r4 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM Children WHERE COALESCE(approve_profile, 'รอดำเนินการ') IN ('รอดำเนินการ', 'กำลังดำเนินการ')");
  if ($r4) $pending_children = mysqli_fetch_assoc($r4)['cnt'];
}

$total_pending = $pending_count + $pending_children + $pending_projects + $pending_needs;

// ===== แจ้งเตือนสำหรับ foundation และ donor =====
$user_notif_count = 0;
$user_notifs      = [];
$is_logged_in = isset($_SESSION['user_id']);
if (isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['foundation', 'donor'])) {
  include_once 'db.php';
  $uid = (int)$_SESSION['user_id'];
  try {
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    $hasNotificationsTable = $tableCheck && mysqli_num_rows($tableCheck) > 0;

    if ($hasNotificationsTable) {
      $stmtNotif = $conn->prepare(
        "SELECT notif_id, title, message, link, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 10"
      );

      if ($stmtNotif) {
        $stmtNotif->bind_param("i", $uid);
        $stmtNotif->execute();
        $notifResult = $stmtNotif->get_result();
        while ($n = mysqli_fetch_assoc($notifResult)) {
          $user_notifs[] = $n;
        }
        $user_notif_count = count(array_filter($user_notifs, fn($n) => !$n['is_read']));
      }
    }
  } catch (Throwable $e) {
    $user_notifs = [];
    $user_notif_count = 0;
  }
}

$_nav_depth = substr_count(str_replace(dirname(str_replace('\\','/',__FILE__)), '', str_replace('\\','/',dirname($_SERVER['SCRIPT_FILENAME']))), '/');
$_nav_base  = str_repeat('../', max(0, $_nav_depth));

// โหมดดูตัวอย่างผู้บริจาค (foundation เท่านั้น)
if (isset($_GET['preview_mode'])) {
  if ($_GET['preview_mode'] === 'donor' && ($_SESSION['real_role'] ?? $_SESSION['role'] ?? '') === 'foundation') {
    $_SESSION['real_role'] = 'foundation';
    $_SESSION['role']      = 'donor';
  } elseif ($_GET['preview_mode'] === 'exit' && isset($_SESSION['real_role'])) {
    $_SESSION['role']      = $_SESSION['real_role'];
    unset($_SESSION['real_role']);
  }
  $redirect = strtok($_SERVER['REQUEST_URI'], '?');
  header("Location: $redirect");
  exit();
}

$is_donor_preview = isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'foundation';
$is_admin_mode = ($_SESSION['role'] ?? '') === 'admin';
$current_page = basename($_SERVER['PHP_SELF']);
$adminDashboardActive = in_array($current_page, ['admin_dashboard.php'], true);
$adminFoundationActive = in_array($current_page, ['admin_approve_foundation.php'], true);
$adminChildrenActive = in_array($current_page, ['children_.php', 'children_donate.php', 'admin_approve_children.php', 'admin_children.php'], true);
$adminProjectActive = in_array($current_page, ['admin_approve_projects.php', 'admin_projects.php'], true);
$adminNeedlistActive = in_array($current_page, ['admin_approve_needlist.php', 'foundation.php', 'foundation_add_need.php'], true);
$adminEscrowActive = in_array($current_page, ['admin_escrow.php'], true);
?>
<link rel="stylesheet" href="<?= $_nav_base ?>css/navbar.css">
<link rel="stylesheet" href="<?= $_nav_base ?>css/notif.css">
<?php if ($is_admin_mode): ?>
<script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>
<button type="button" class="admin-sidebar-show-btn" id="adminSidebarShowBtn" aria-label="แสดงเมนูแอดมิน">☰</button>
<aside class="admin-sidebar-nav">
  <div class="admin-sidebar-head-actions">
    <button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="ซ่อนเมนูแอดมิน">✕</button>
    <a href="<?= $_nav_base ?>admin_notifications.php" class="admin-sidebar-notif" title="รายการแจ้งเตือนแอดมิน">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:bell-bold-duotone"></iconify-icon></span>
      <?php if ($total_pending > 0): ?><span class="admin-nav-badge"><?= $total_pending ?></span><?php endif; ?>
    </a>
  </div>

  <a href="<?= $_nav_base ?>admin_dashboard.php" class="admin-brand-card">
    <img src="<?= $_nav_base ?>img/logobig.png" class="admin-brand-logo" alt="DrawDream Admin">
    <div class="admin-brand-text">
      <strong>DrawDream</strong>
      <span>Admin Panel</span>
    </div>
  </a>

  <div class="admin-nav-links">
    <a href="<?= $_nav_base ?>admin_dashboard.php" class="admin-nav-link<?= $adminDashboardActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:home-2-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Dashboard</span>
    </a>
    <a href="<?= $_nav_base ?>admin_approve_foundation.php" class="admin-nav-link<?= $adminFoundationActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:buildings-2-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Foundation</span>
    </a>
    <a href="<?= $_nav_base ?>children_.php" class="admin-nav-link<?= $adminChildrenActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:users-group-two-rounded-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Profilechildren</span>
    </a>
    <a href="<?= $_nav_base ?>admin_approve_projects.php" class="admin-nav-link<?= $adminProjectActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:book-bookmark-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Project</span>
    </a>
    <a href="<?= $_nav_base ?>admin_approve_needlist.php" class="admin-nav-link<?= $adminNeedlistActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:gift-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Needlist</span>
    </a>
    <a href="<?= $_nav_base ?>admin_escrow.php" class="admin-nav-link<?= $adminEscrowActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:wallet-money-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Escrow</span>
    </a>
  </div>

  <div class="admin-sidebar-footer">
    <a href="<?= $_nav_base ?>profile.php" class="admin-side-utility">โปรไฟล์</a>
    <a href="<?= $_nav_base ?>logout.php" class="admin-side-utility admin-side-logout">ออกจากระบบ</a>
  </div>
</aside>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('admin-sidebar-page');
    const sidebar = document.querySelector('.admin-sidebar-nav');
    const toggleBtn = document.getElementById('adminSidebarToggle');
    const showBtn = document.getElementById('adminSidebarShowBtn');
    const storageKey = 'drawdream-admin-sidebar-collapsed';

    function setCollapsed(collapsed) {
      document.body.classList.toggle('admin-sidebar-collapsed', collapsed);
      if (showBtn) showBtn.style.display = collapsed ? 'inline-flex' : 'none';
      localStorage.setItem(storageKey, collapsed ? '1' : '0');
    }

    const saved = localStorage.getItem(storageKey) === '1';
    setCollapsed(saved);

    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        setCollapsed(true);
      });
    }
    if (showBtn) {
      showBtn.addEventListener('click', function () {
        setCollapsed(false);
      });
    }
  });
</script>
<?php else: ?>
<nav class="navbar">

  <div class="nav-left">
    <?php if ($is_admin_mode): ?>
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
    <?php if ($is_logged_in): ?>

      <?php if (in_array($_SESSION['role'] ?? '', ['foundation', 'donor'])): ?>
        <?php if (($_SESSION['real_role'] ?? '') === 'foundation'): ?>
          <!-- กำลังอยู่ในโหมดดูตัวอย่างผู้บริจาค -->
        <?php elseif (($_SESSION['role'] ?? '') === 'foundation'): ?>
          <a href="?preview_mode=donor" class="donor-view-btn" title="ดูหน้าเว็บในมุมมองผู้บริจาค">
            <span class="donor-view-icon">&#128065;</span> มุมมองผู้บริจาค
          </a>
        <?php endif; ?>
        <!-- ระฆังแจ้งเตือน -->
        <div class="notif-wrap" id="notifWrap">
          <button class="notif-btn" onclick="toggleNotif(event)" style="background:none;border:none;cursor:pointer;position:relative;padding:0;">
            <img src="<?= $_nav_base ?>img/bell.png" alt="แจ้งเตือน" class="nav-icon">
            <?php if ($user_notif_count > 0): ?>
              <span class="notif-badge"><?= $user_notif_count ?></span>
            <?php endif; ?>
          </button>
          <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-header">
              การแจ้งเตือน
              <?php if ($user_notif_count > 0): ?>
                <a href="<?= $_nav_base ?>mark_notif_read.php?all=1" class="notif-mark-all">อ่านทั้งหมด</a>
              <?php endif; ?>
            </div>
            <?php if (empty($user_notifs)): ?>
              <div class="notif-empty">ยังไม่มีการแจ้งเตือน</div>
            <?php else: ?>
              <?php foreach ($user_notifs as $n): ?>
                <a href="<?= $_nav_base . htmlspecialchars($n['link'] ?? 'profile.php') ?>"
                   class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>"
                   onclick="markRead(<?= $n['notif_id'] ?>)">
                  <div class="notif-item-title"><?= htmlspecialchars($n['title']) ?></div>
                  <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                  <div class="notif-item-time"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
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
<?php endif; ?>

<?php if ($is_donor_preview): ?>
<div class="donor-preview-banner">
  <span>&#128065; กำลังดูในโหมด <strong>มุมมองผู้บริจาค</strong> &mdash; เห็นเหมือนผู้บริจาคทั่วไป</span>
  <a href="?preview_mode=exit" class="donor-preview-exit">✕ ออกจากโหมดดูตัวอย่าง</a>
</div>
<?php endif; ?>

<script>
function toggleNotif(e) {
  e.stopPropagation();
  const w = document.getElementById('notifWrap');
  if (w) w.classList.toggle('open');
}
document.addEventListener('click', function() {
  const w = document.getElementById('notifWrap');
  if (w) w.classList.remove('open');
});
function markRead(id) {
  fetch('<?= $_nav_base ?>mark_notif_read.php?id=' + id);
}
</script>