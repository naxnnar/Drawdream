<?php
// navbar.php — แถบนำทางร่วมทุกหน้า

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน navbar

if (session_status() === PHP_SESSION_NONE) {
  require_once __DIR__ . '/includes/env_loader.php';
  drawdream_load_env_file(__DIR__ . '/.env');
  require_once __DIR__ . '/includes/session_init.php';
  drawdream_session_start();
}
require_once __DIR__ . '/includes/brand_logo.php';
require_once __DIR__ . '/includes/navbar_cache.php';

$_nav_depth = substr_count(str_replace(dirname(str_replace('\\','/',__FILE__)), '', str_replace('\\','/',dirname($_SERVER['SCRIPT_FILENAME']))), '/');
$_nav_base  = str_repeat('../', max(0, $_nav_depth));

// นับรายการรออนุมัติ (เฉพาะ admin)
$pending_count    = 0;
$pending_children = 0;
$pending_projects = 0;
$pending_needs    = 0;

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
  $adminPendingCache = drawdream_navbar_cache_get('admin_pending', 60);
  if ($adminPendingCache !== null) {
    $pending_count = (int)($adminPendingCache['foundation'] ?? 0);
    $pending_projects = (int)($adminPendingCache['projects'] ?? 0);
    $pending_needs = (int)($adminPendingCache['needs'] ?? 0);
    $pending_children = (int)($adminPendingCache['children'] ?? 0);
  } else {
  include_once 'db.php';

  $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_profile WHERE account_verified = 0");
  if ($r) $pending_count = mysqli_fetch_assoc($r)['cnt'];

  $pending_projects = 0;
  require_once __DIR__ . '/includes/drawdream_project_status.php';
  $pendingProjExpr = drawdream_sql_project_is_pending('project_status');
  $r2 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_project WHERE {$pendingProjExpr}");
  if ($r2) {
    $pending_projects = (int)mysqli_fetch_assoc($r2)['cnt'];
  }
  $r3 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_needlist WHERE approve_item = 'pending'");
  if ($r3) $pending_needs = mysqli_fetch_assoc($r3)['cnt'];

  $r4 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_children WHERE COALESCE(approve_profile, 'รอดำเนินการ') IN ('รอดำเนินการ', 'กำลังดำเนินการ')");
  if ($r4) $pending_children = mysqli_fetch_assoc($r4)['cnt'];

  drawdream_navbar_cache_set('admin_pending', [
    'foundation' => (int)$pending_count,
    'projects' => (int)$pending_projects,
    'needs' => (int)$pending_needs,
    'children' => (int)$pending_children,
  ]);
  }
}

$total_pending = $pending_count + $pending_children + $pending_projects + $pending_needs;

// ===== แจ้งเตือนสำหรับ foundation และ donor =====
$user_notif_count = 0;
$user_notifs      = [];
$user_notifs_html = '';
$notif_ssr_fresh  = false;
$is_logged_in = isset($_SESSION['user_id']);
$_nav_csrf_token = '';
if ($is_logged_in) {
  require_once __DIR__ . '/includes/csrf.php';
  $_nav_csrf_token = drawdream_csrf_token();
}
if (isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['foundation', 'donor'])) {
  $uid = (int)$_SESSION['user_id'];
  $userRoleNav = (string)($_SESSION['role'] ?? '');
  $notifCacheKey = 'user_notifs_' . $userRoleNav . '_' . $uid;
  $notifCache = drawdream_navbar_cache_get($notifCacheKey, 90);
  if ($notifCache !== null) {
    $notif_ssr_fresh = true;
    $user_notifs = is_array($notifCache['notifs'] ?? null) ? $notifCache['notifs'] : [];
    $user_notif_count = (int)($notifCache['count'] ?? 0);
    $user_notifs_html = (string)($notifCache['html'] ?? '');
  } else {
    include_once 'db.php';
    require_once __DIR__ . '/includes/navbar_notifications.php';
    try {
      $bundle = drawdream_navbar_notifications_bundle($conn, $uid, $userRoleNav, $_nav_base, false);
      $user_notifs = $bundle['notifs'];
      $user_notif_count = (int)$bundle['count'];
      $user_notifs_html = (string)$bundle['html'];
      drawdream_navbar_cache_set($notifCacheKey, [
        'notifs' => $user_notifs,
        'count' => $user_notif_count,
        'html' => $user_notifs_html,
      ]);
    } catch (Throwable $e) {
      $user_notifs = [];
      $user_notif_count = 0;
      $user_notifs_html = '';
    }
  }
  if ($user_notifs_html === '' && $user_notifs !== []) {
    require_once __DIR__ . '/includes/navbar_notifications.php';
    $user_notifs_html = drawdream_navbar_notification_list_html($user_notifs, $_nav_base);
  }
}

// รูปโปรไฟล์มุมขวาบน (ผู้บริจาค / มูลนิธิ)
$nav_profile_img = $_nav_base . 'img/donor-avatar-placeholder.svg';
$nav_profile_is_custom = false;
if ($is_logged_in && in_array($_SESSION['role'] ?? '', ['donor', 'foundation'], true)) {
  if (!isset($conn) || !($conn instanceof mysqli)) {
    include_once __DIR__ . '/db.php';
  }
  if (isset($conn) && $conn instanceof mysqli) {
    $uidNav = (int)$_SESSION['user_id'];
    $roleNav = $_SESSION['role'] ?? '';
    if ($roleNav === 'donor') {
      $stNav = $conn->prepare('SELECT profile_image FROM donor WHERE user_id = ? LIMIT 1');
      if ($stNav) {
        $stNav->bind_param('i', $uidNav);
        $stNav->execute();
        $rowNav = $stNav->get_result()->fetch_assoc();
        $fn = isset($rowNav['profile_image']) ? basename((string)$rowNav['profile_image']) : '';
        if ($fn !== '') {
          $nav_profile_img = $_nav_base . 'uploads/profiles/' . rawurlencode($fn);
          $nav_profile_is_custom = true;
        }
      }
    } elseif ($roleNav === 'foundation') {
      $stNav = $conn->prepare('SELECT foundation_image FROM foundation_profile WHERE user_id = ? LIMIT 1');
      if ($stNav) {
        $stNav->bind_param('i', $uidNav);
        $stNav->execute();
        $rowNav = $stNav->get_result()->fetch_assoc();
        $fn = isset($rowNav['foundation_image']) ? basename((string)$rowNav['foundation_image']) : '';
        if ($fn !== '') {
          $nav_profile_img = $_nav_base . 'uploads/profiles/' . rawurlencode($fn);
          $nav_profile_is_custom = true;
        }
      }
    }
  }
}

// โหมดดูตัวอย่างผู้บริจาค — ลิงก์หลักไป preview_mode.php; รองรับ ?preview_mode= บนหน้าเดิม
require_once __DIR__ . '/includes/foundation_donor_preview.php';
if (isset($_GET['preview_mode'])) {
  drawdream_foundation_preview_process_request();
}

$is_donor_preview = isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'foundation';
$foundation_account_pending = false;
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'foundation') {
  $uidFdn = (int)$_SESSION['user_id'];
  $fdnPendingCache = drawdream_navbar_cache_get('foundation_verified_' . $uidFdn, 60);
  if ($fdnPendingCache !== null) {
    $foundation_account_pending = empty($fdnPendingCache['verified']);
  } else {
  include_once __DIR__ . '/db.php';
  require_once __DIR__ . '/includes/foundation_account_verified.php';
  $verified = drawdream_foundation_account_is_verified($conn);
  $foundation_account_pending = !$verified;
  drawdream_navbar_cache_set('foundation_verified_' . $uidFdn, ['verified' => $verified]);
  }
}
$is_admin_mode = ($_SESSION['role'] ?? '') === 'admin';
$current_page = basename($_SERVER['PHP_SELF']);
$aboutNavActive = in_array($current_page, ['about.php', 'about_support.php'], true);
$adminDashboardActive = in_array($current_page, [
    'admin_dashboard.php',
    'admin_donors.php',
    'admin_donor_email.php',
    'admin_foundations_overview.php',
    'admin_children_overview.php',
    'admin_notifications.php',
], true);
$adminFoundationActive = in_array($current_page, [
    'admin_foundations_overview.php',
    'admin_approve_foundation.php',
    'admin_view_foundation.php',
    'admin_foundation_totals.php',
    'admin_foundation_analytics_pdf.php',
    'admin_foundation_analytics_view.php',
], true);
$adminChildrenActive = in_array($current_page, ['children_.php', 'children_donate.php', 'admin_approve_children.php', 'admin_children.php'], true);
$adminProjectActive = in_array($current_page, [
    'admin_projects_directory.php',
    'admin_approve_projects.php',
    'admin_project_totals.php',
    'admin_projects.php',
], true);
$adminNeedlistActive = in_array($current_page, [
    'admin_needlist_directory.php',
    'admin_needlist_view.php',
    'admin_needlist_totals.php',
    'admin_approve_needlist.php',
], true);
$adminEscrowActive = in_array($current_page, ['admin_escrow.php'], true);
?>
<link rel="stylesheet" href="<?= $_nav_base ?>css/navbar.css?v=17">
<link rel="stylesheet" href="<?= htmlspecialchars($_nav_base . 'css/brand_logo.css?v=2', ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($_nav_base . 'css/notif.css?v=7', ENT_QUOTES, 'UTF-8') ?>">
<script>
document.addEventListener('touchstart', function () {}, { passive: true });
</script>
<?php if ($is_admin_mode): ?>
<script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>
<button type="button" class="admin-sidebar-show-btn" id="adminSidebarShowBtn" aria-label="แสดงเมนูแอดมิน">☰</button>
<button type="button" class="admin-sidebar-backdrop" id="adminSidebarBackdrop" aria-label="ปิดเมนูแอดมิน" tabindex="-1"></button>
<aside class="admin-sidebar-nav">
  <div class="admin-sidebar-head-actions">
    <button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="ซ่อนเมนูแอดมิน">✕</button>
    <a href="<?= $_nav_base ?>admin_notifications.php" class="admin-sidebar-notif" title="คำขอรออนุมัติ — ศูนย์รวมคิว">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:bell-bold-duotone"></iconify-icon></span>
      <?php if ($total_pending > 0): ?><span class="admin-nav-badge"><?= $total_pending ?></span><?php endif; ?>
    </a>
  </div>

  <a href="<?= $_nav_base ?>admin_dashboard.php" class="admin-brand-card">
    <img src="<?= htmlspecialchars(drawdream_admin_brand_logo_src($_nav_base), ENT_QUOTES, 'UTF-8') ?>" class="admin-brand-logo" alt="DrawDream Admin">
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
    <a href="<?= $_nav_base ?>admin_foundations_overview.php" class="admin-nav-link<?= $adminFoundationActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:buildings-2-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Foundation</span>
    </a>
    <a href="<?= $_nav_base ?>children_.php" class="admin-nav-link<?= $adminChildrenActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:users-group-two-rounded-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Children</span>
    </a>
    <a href="<?= $_nav_base ?>admin_projects_directory.php" class="admin-nav-link<?= $adminProjectActive ? ' active' : '' ?>">
      <span class="admin-nav-emoji"><iconify-icon icon="solar:book-bookmark-bold-duotone"></iconify-icon></span>
      <span class="admin-nav-label">Project</span>
    </a>
    <a href="<?= $_nav_base ?>admin_needlist_directory.php" class="admin-nav-link<?= $adminNeedlistActive ? ' active' : '' ?>">
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
    const backdrop = document.getElementById('adminSidebarBackdrop');
    const storageKey = 'drawdream-admin-sidebar-collapsed';
    const mqAdminMobile = window.matchMedia('(max-width: 991px)');

    function isAdminMobile() {
      return mqAdminMobile.matches;
    }

    function setCollapsed(collapsed) {
      document.body.classList.toggle('admin-sidebar-collapsed', collapsed);
      document.body.classList.toggle('admin-sidebar-drawer-open', isAdminMobile() && !collapsed);
      if (showBtn) {
        showBtn.style.display = collapsed ? 'inline-flex' : 'none';
      }
      if (!isAdminMobile()) {
        localStorage.setItem(storageKey, collapsed ? '1' : '0');
      }
    }

    const saved = localStorage.getItem(storageKey) === '1';
    setCollapsed(isAdminMobile() ? true : saved);

    mqAdminMobile.addEventListener('change', function () {
      setCollapsed(isAdminMobile() ? true : (localStorage.getItem(storageKey) === '1'));
    });

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
    if (backdrop) {
      backdrop.addEventListener('click', function () {
        setCollapsed(true);
      });
    }
    if (sidebar) {
      sidebar.querySelectorAll('.admin-nav-link, .admin-side-utility, .admin-brand-card').forEach(function (el) {
        el.addEventListener('click', function () {
          if (isAdminMobile()) {
            setCollapsed(true);
          }
        });
      });
    }
  });
</script>
<?php else: ?>
<?php
if (!function_exists('drawdream_bootstrap_icons_link')) {
    require_once __DIR__ . '/includes/vendor_assets.php';
}
echo drawdream_bootstrap_icons_link($_nav_base);
?>
<?php if ($is_donor_preview): ?>
<div class="donor-preview-banner">
  <span>&#128065; กำลังดูในโหมด <strong>มุมมองผู้บริจาค</strong> &mdash; เห็นเหมือนผู้บริจาคทั่วไป</span>
  <a href="<?= htmlspecialchars(drawdream_foundation_preview_exit_href($_nav_base), ENT_QUOTES, 'UTF-8') ?>" class="donor-preview-exit">✕ ออกจากโหมดดูตัวอย่าง</a>
</div>
<?php endif; ?>
<?php if (!empty($foundation_account_pending)): ?>
<div class="foundation-pending-account-banner" role="status">
  <span>บัญชีมูลนิธิของคุณยังรอการตรวจสอบจากผู้ดูแลระบบ — จึงยังไม่สามารถสร้างหรือจัดการโปรไฟล์ โครงการ หรือรายการสิ่งของได้จนกว่าจะได้รับการอนุมัติ</span>
</div>
<?php endif; ?>
<nav class="navbar navbar-public">
  <div class="nav-left">
    <button type="button" class="nav-mobile-menu-btn" id="navMobileMenuBtn" aria-label="เปิดเมนูนำทาง" aria-expanded="false" aria-controls="navMainLinks">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <div class="nav-main-links" id="navMainLinks" role="navigation" aria-label="เมนูหลัก">
      <a href="<?= $_nav_base ?>homepage.php" <?= basename($_SERVER['PHP_SELF']) == 'homepage.php' ? 'class="active"' : '' ?>>หน้าแรก</a>
      <a href="<?= $_nav_base ?>children_.php" <?= in_array(basename($_SERVER['PHP_SELF']), ['children_.php', 'children_donate.php'], true) ? 'class="active"' : '' ?>>อุปการะ</a>
      <a href="<?= $_nav_base ?>project.php" <?= basename($_SERVER['PHP_SELF']) == 'project.php' ? 'class="active"' : '' ?>>โครงการ</a>
      <a href="<?= $_nav_base ?>foundation.php" <?= basename($_SERVER['PHP_SELF']) == 'foundation.php' ? 'class="active"' : '' ?>>มูลนิธิ</a>
      <div class="nav-about-dropdown<?= $aboutNavActive ? ' is-active' : '' ?>" id="navAboutDropdown">
        <button type="button"
                class="nav-about-dropdown__trigger<?= $aboutNavActive ? ' active' : '' ?>"
                id="navAboutDropdownBtn"
                aria-expanded="false"
                aria-controls="navAboutDropdownMenu"
                aria-haspopup="true">
          เกี่ยวกับเรา
          <i class="bi bi-chevron-down nav-about-dropdown__chevron" aria-hidden="true"></i>
        </button>
        <div class="nav-about-dropdown__menu" id="navAboutDropdownMenu" role="menu" hidden>
          <a href="<?= $_nav_base ?>about.php" role="menuitem" <?= $current_page === 'about.php' ? 'class="active"' : '' ?>>เกี่ยวกับเรา</a>
          <a href="<?= $_nav_base ?>about_support.php" role="menuitem" <?= $current_page === 'about_support.php' ? 'class="active"' : '' ?>>สนับสนุนเรา</a>
        </div>
      </div>
    </div>
  </div>

  <div class="nav-center">
    <a href="<?= $_nav_base ?>homepage.php">
      <img src="<?= htmlspecialchars(drawdream_brand_logo_src($_nav_base), ENT_QUOTES, 'UTF-8') ?>" class="nav-logo" alt="DrawDream Logo">
    </a>
  </div>

  <div class="nav-right">
    <?php if ($is_logged_in): ?>

      <?php if (in_array($_SESSION['role'] ?? '', ['foundation', 'donor'])): ?>
        <?php if (($_SESSION['real_role'] ?? '') === 'foundation'): ?>
          <!-- กำลังอยู่ในโหมดดูตัวอย่างผู้บริจาค -->
        <?php elseif (($_SESSION['role'] ?? '') === 'foundation'): ?>
          <a href="<?= htmlspecialchars(drawdream_foundation_preview_enter_href($_nav_base), ENT_QUOTES, 'UTF-8') ?>" class="donor-view-btn" title="ดูหน้าเว็บในมุมมองผู้บริจาค">
            <span class="donor-view-icon">&#128065;</span> มุมมองผู้บริจาค
          </a>
        <?php endif; ?>
        <!-- ระฆังแจ้งเตือน -->
        <div class="notif-wrap" id="notifWrap" data-feed-url="<?= htmlspecialchars($_nav_base . 'notifications_feed.php', ENT_QUOTES, 'UTF-8') ?>" data-feed-fresh="<?= $notif_ssr_fresh ? '1' : '0' ?>">
          <button type="button" class="notif-btn" onclick="toggleNotif(event)" aria-label="แจ้งเตือน" aria-expanded="false" aria-controls="notifDropdown">
            <i class="bi bi-bell-fill nav-bell-icon" aria-hidden="true"></i>
            <span class="notif-badge" id="notifBadge"<?= $user_notif_count <= 0 ? ' hidden' : '' ?>><?= $user_notif_count ?></span>
          </button>
          <div class="notif-dropdown" id="notifDropdown" onclick="event.stopPropagation()">
            <div class="notif-header">
              <span class="notif-header-title">การแจ้งเตือน</span>
              <div class="notif-header-actions">
                <?php if ($user_notif_count > 0): ?>
                  <button type="button" class="notif-mark-all" id="notifMarkAll">อ่านทั้งหมด</button>
                <?php else: ?>
                  <button type="button" class="notif-mark-all" id="notifMarkAll" hidden>อ่านทั้งหมด</button>
                <?php endif; ?>
                <?php if (in_array(($_SESSION['role'] ?? ''), ['foundation', 'donor'], true)): ?>
                  <a href="<?= $_nav_base ?>notifications.php" class="notif-see-all-header">ดูทั้งหมด</a>
                <?php endif; ?>
              </div>
            </div>
            <div class="notif-body-scroll" id="notifBody">
            <?= $user_notifs_html !== '' ? $user_notifs_html : '<div class="notif-empty">ยังไม่มีการแจ้งเตือน</div>' ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="nav-profile-wrap">
        <button type="button" class="profile-btn" id="navProfileMenuBtn" aria-expanded="false" aria-haspopup="true" aria-controls="navProfileMenu" title="เมนูบัญชี">
          <img src="<?= htmlspecialchars($nav_profile_img, ENT_QUOTES, 'UTF-8') ?>" alt="โปรไฟล์" class="nav-icon nav-profile-photo<?= $nav_profile_is_custom ? '' : ' nav-profile-photo--placeholder' ?>" width="28" height="28" loading="lazy">
        </button>
        <div class="nav-profile-menu" id="navProfileMenu" role="menu" hidden>
          <a href="<?= $_nav_base ?>profile.php" class="nav-profile-menu-item" role="menuitem">โปรไฟล์</a>
          <?php if (($_SESSION['role'] ?? '') === 'foundation' && !$is_donor_preview): ?>
          <?php if (empty($foundation_account_pending)): ?>
          <a href="<?= $_nav_base ?>foundation_dashboard.php" class="nav-profile-menu-item" role="menuitem">แดชบอร์ดมูลนิธิ</a>
          <?php else: ?>
          <span class="nav-profile-menu-item nav-profile-menu-item--disabled" role="menuitem" title="รอแอดมินอนุมัติบัญชีก่อน">แดชบอร์ดมูลนิธิ (รออนุมัติ)</span>
          <?php endif; ?>
          <?php endif; ?>
          <a href="<?= $_nav_base ?>logout.php" class="nav-profile-menu-item nav-profile-menu-logout" role="menuitem">ออกจากระบบ</a>
        </div>
      </div>

    <?php else: ?>
      <a href="<?= $_nav_base ?>login.php" class="logout-btn">เข้าสู่ระบบ</a>
    <?php endif; ?>
  </div>

</nav>
<div class="nav-mobile-overlay" id="navMobileOverlay" aria-hidden="true"></div>
<script>
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('navMobileMenuBtn');
    var overlay = document.getElementById('navMobileOverlay');
    if (!btn || !overlay) return;

    function setOpen(open) {
      document.body.classList.toggle('nav-public-drawer-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.setAttribute('aria-label', open ? 'ปิดเมนูนำทาง' : 'เปิดเมนูนำทาง');
      overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (open) {
        document.body.style.overflow = 'hidden';
      } else {
        document.body.style.overflow = '';
      }
    }

    function close() {
      setOpen(false);
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!document.body.classList.contains('nav-public-drawer-open'));
    });
    overlay.addEventListener('click', close);
    var panel = document.getElementById('navMainLinks');
    if (panel) {
      panel.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', close);
      });
    }
    window.addEventListener('resize', function () {
      if (window.innerWidth > 1024) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });
  });
})();
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.getElementById('navAboutDropdown');
    var btn = document.getElementById('navAboutDropdownBtn');
    var menu = document.getElementById('navAboutDropdownMenu');
    if (!wrap || !btn || !menu) return;

    function setOpen(open) {
      wrap.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        menu.removeAttribute('hidden');
      } else {
        menu.setAttribute('hidden', '');
      }
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!wrap.classList.contains('is-open'));
    });

    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        setOpen(false);
      });
    });

    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        setOpen(false);
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 600) {
        setOpen(false);
      }
    });
  });
})();
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('navProfileMenuBtn');
    var wrap = document.querySelector('.nav-profile-wrap');
    var menu = document.getElementById('navProfileMenu');
    if (!btn || !wrap || !menu) return;
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = !wrap.classList.contains('nav-profile-open');
      wrap.classList.toggle('nav-profile-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        menu.removeAttribute('hidden');
        var dashLink = menu.querySelector('a[href*="foundation_dashboard.php"]');
        if (dashLink && !document.querySelector('link[rel="prefetch"][href*="foundation_dashboard.php"]')) {
          var pf = document.createElement('link');
          pf.rel = 'prefetch';
          pf.href = dashLink.getAttribute('href') || 'foundation_dashboard.php';
          document.head.appendChild(pf);
        }
      } else {
        menu.setAttribute('hidden', '');
      }
      var nw = document.getElementById('notifWrap');
      if (nw) nw.classList.remove('open');
    });
    menu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        if (typeof closeNavProfileMenu === 'function') closeNavProfileMenu();
      });
    });
    window.addEventListener('resize', function () {
      if (window.innerWidth > 1024 && typeof closeNavProfileMenu === 'function') closeNavProfileMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && typeof closeNavProfileMenu === 'function') closeNavProfileMenu();
    });
  });
})();
</script>
<?php endif; ?>

<script>
function toggleNotif(e) {
  e.stopPropagation();
  const w = document.getElementById('notifWrap');
  const btn = w ? w.querySelector('.notif-btn') : null;
  if (w) {
    const open = !w.classList.contains('open');
    w.classList.toggle('open', open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && typeof window.drawdreamRefreshNotifFeed === 'function') {
      window.drawdreamRefreshNotifFeed();
    }
  }
  closeNavProfileMenu();
}
function closeNavProfileMenu() {
  var wrap = document.querySelector('.nav-profile-wrap');
  var btn = document.getElementById('navProfileMenuBtn');
  var menu = document.getElementById('navProfileMenu');
  if (!wrap || !btn || !menu) return;
  wrap.classList.remove('nav-profile-open');
  btn.setAttribute('aria-expanded', 'false');
  menu.setAttribute('hidden', '');
}
document.addEventListener('click', function() {
  const w = document.getElementById('notifWrap');
  const btn = w ? w.querySelector('.notif-btn') : null;
  if (w) {
    w.classList.remove('open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }
  closeNavProfileMenu();
});

(function () {
  var wrap = document.getElementById('notifWrap');
  if (!wrap) return;
  var feedUrl = wrap.getAttribute('data-feed-url') || 'notifications_feed.php';
  var feedFresh = wrap.getAttribute('data-feed-fresh') === '1';
  var bodyEl = document.getElementById('notifBody');
  var badgeEl = document.getElementById('notifBadge');
  var markAllEl = document.getElementById('notifMarkAll');
  var markReadBase = <?= json_encode($_nav_base . 'mark_notif_read.php', JSON_UNESCAPED_SLASHES) ?>;
  var csrfToken = <?= json_encode($_nav_csrf_token, JSON_UNESCAPED_UNICODE) ?>;
  var feedLoaded = feedFresh;

  function updateBadge(count) {
    if (!badgeEl) return;
    var n = parseInt(count, 10) || 0;
    if (n > 0) {
      badgeEl.textContent = String(n);
      badgeEl.hidden = false;
      if (markAllEl) markAllEl.hidden = false;
    } else {
      badgeEl.hidden = true;
      if (markAllEl) markAllEl.hidden = true;
    }
  }

  function bindNotifClicks(root) {
    if (!root) return;
    root.querySelectorAll('.notif-item[data-notif-id]').forEach(function (item) {
      if (item.dataset.bound === '1') return;
      item.dataset.bound = '1';
      item.addEventListener('click', function (e) {
        var id = parseInt(item.getAttribute('data-notif-id') || '0', 10);
        var href = item.getAttribute('data-notif-link') || item.getAttribute('href') || '';
        if (id > 0) {
          var markUrl = markReadBase + '?id=' + encodeURIComponent(String(id));
          if (navigator.sendBeacon) {
            navigator.sendBeacon(markUrl);
          } else {
            fetch(markUrl, { credentials: 'same-origin', keepalive: true }).catch(function () {});
          }
        }
        if (href) {
          e.preventDefault();
          window.location.href = href;
        }
      });
    });
  }

  function applyFeedData(data) {
    if (!data || typeof data.html !== 'string') return;
    if (bodyEl) {
      bodyEl.innerHTML = data.html;
      bindNotifClicks(bodyEl);
    }
    if (typeof data.count !== 'undefined') updateBadge(data.count);
    feedLoaded = true;
  }

  window.drawdreamRefreshNotifFeed = function () {
    return fetch(feedUrl, { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        applyFeedData(data);
        return data;
      })
      .catch(function () { return null; });
  };

  bindNotifClicks(bodyEl);

  if (!feedFresh) {
    window.drawdreamRefreshNotifFeed();
  }

  if (markAllEl) {
    markAllEl.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (markAllEl.disabled) return;
      markAllEl.disabled = true;
      fetch(markReadBase, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: 'mark_all=1&ajax=1&csrf=' + encodeURIComponent(csrfToken || '')
      })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          if (data && data.ok) {
            updateBadge(0);
            if (bodyEl) {
              bodyEl.innerHTML = '<div class="notif-empty">ยังไม่มีการแจ้งเตือน</div>';
            }
          } else {
            window.drawdreamRefreshNotifFeed();
          }
        })
        .catch(function () {})
        .finally(function () {
          markAllEl.disabled = false;
        });
    });
  }
})();
</script>
<?php if (($_SESSION['role'] ?? '') === 'foundation'): ?>
<script src="<?= htmlspecialchars(($_nav_base ?? '') . 'js/foundation_manage_nav.js?v=2', ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>