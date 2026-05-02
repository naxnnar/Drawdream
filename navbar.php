<?php
// navbar.php — แถบนำทางร่วมทุกหน้า

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน navbar

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

  $pending_projects = 0;
  require_once __DIR__ . '/includes/drawdream_project_status.php';
  $pendingProjExpr = drawdream_sql_project_is_pending('project_status');
  $r2 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_project WHERE {$pendingProjExpr}");
  if ($r2) {
    $pending_projects = (int)mysqli_fetch_assoc($r2)['cnt'];
  }
  $r3 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_needlist WHERE approve_item = 'pending'");
  if ($r3) $pending_needs = mysqli_fetch_assoc($r3)['cnt'];

  $r4 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_children WHERE COALESCE(approve_profile, 'รอดำเนินการ') IN ('รอดำเนินการ', 'กำลังดำเนินการ') AND deleted_at IS NULL");
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

    // แจ้งเตือนเสริมสำหรับมูลนิธิ: เด็กมีผู้อุปการะแล้ว แต่ยังไม่อัปเดตผลลัพธ์ให้ผู้บริจาค
    if (($_SESSION['role'] ?? '') === 'foundation') {
      require_once __DIR__ . '/includes/donate_category_resolve.php';
      require_once __DIR__ . '/includes/payment_transaction_schema.php';
      require_once __DIR__ . '/includes/notification_audit.php';
      drawdream_ensure_notifications_table($conn);
      drawdream_payment_transaction_ensure_schema($conn);
      $stmtFid = $conn->prepare("SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1");
      if ($stmtFid) {
        $stmtFid->bind_param("i", $uid);
        $stmtFid->execute();
        $fidRow = $stmtFid->get_result()->fetch_assoc();
        $fid = (int)($fidRow['foundation_id'] ?? 0);
        if ($fid > 0) {
            // แจ้งเตือนรอบรายการสิ่งของใหม่: ระบบปิดรับอัตโนมัติที่ 1 เดือน และเปิดให้เสนอรอบถัดไป
            $openRoundCount = 0;
            $stOpenRound = $conn->prepare(
              "SELECT COUNT(*) AS cnt
               FROM foundation_needlist
               WHERE foundation_id = ?
                 AND approve_item = 'approved'
                 AND donate_window_end_at IS NOT NULL
                 AND donate_window_end_at > NOW()"
            );
            if ($stOpenRound) {
              $stOpenRound->bind_param("i", $fid);
              $stOpenRound->execute();
              $openRoundCount = (int)(($stOpenRound->get_result()->fetch_assoc()['cnt'] ?? 0));
            }
            $pendingNeedCount = 0;
            $stPendingNeed = $conn->prepare("SELECT COUNT(*) AS cnt FROM foundation_needlist WHERE foundation_id = ? AND approve_item = 'pending'");
            if ($stPendingNeed) {
              $stPendingNeed->bind_param("i", $fid);
              $stPendingNeed->execute();
              $pendingNeedCount = (int)(($stPendingNeed->get_result()->fetch_assoc()['cnt'] ?? 0));
            }
            $latestClosedNeed = null;
            $stClosedNeed = $conn->prepare(
              "SELECT donate_window_end_at
               FROM foundation_needlist
               WHERE foundation_id = ?
                 AND approve_item = 'approved'
                 AND donate_window_end_at IS NOT NULL
                 AND donate_window_end_at <= NOW()
               ORDER BY donate_window_end_at DESC
               LIMIT 1"
            );
            if ($stClosedNeed) {
              $stClosedNeed->bind_param("i", $fid);
              $stClosedNeed->execute();
              $latestClosedNeed = $stClosedNeed->get_result()->fetch_assoc();
            }
            $closedNeedRaw = trim((string)($latestClosedNeed['donate_window_end_at'] ?? ''));
            if ($openRoundCount === 0 && $pendingNeedCount === 0 && $closedNeedRaw !== '') {
              $closedNeedTs = strtotime($closedNeedRaw);
              if ($closedNeedTs !== false) {
                $entityKey = 'fdn_need_round_open:' . date('YmdHis', $closedNeedTs);
                $alreadyHas = false;
                $stHasNotif = $conn->prepare("SELECT notif_id FROM notifications WHERE user_id = ? AND entity_key = ? LIMIT 1");
                if ($stHasNotif) {
                  $stHasNotif->bind_param("is", $uid, $entityKey);
                  $stHasNotif->execute();
                  $alreadyHas = (bool)$stHasNotif->get_result()->fetch_assoc();
                }
                if (!$alreadyHas) {
                  drawdream_send_notification(
                    $conn,
                    $uid,
                    'need_round_open',
                    'ถึงเวลาเสนอรายการสิ่งของรอบใหม่',
                    'รอบก่อนหน้าปิดรับครบ 1 เดือนแล้ว ตอนนี้คุณสามารถเสนอรายการสิ่งของรอบใหม่ได้',
                    'foundation_add_need.php',
                    $entityKey
                  );
                }
              }
            }

            $childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
            $existExpr = "(EXISTS (SELECT 1 FROM donation d WHERE d.category_id = {$childCategoryId} AND d.target_id = c.child_id AND d.payment_status = 'completed' AND d.donor_id IS NOT NULL)
              OR EXISTS (SELECT 1 FROM donation ds WHERE ds.target_id = c.child_id AND ds.donate_type = 'child_subscription' AND ds.recurring_status IN ('active','paused') AND ds.donor_id IS NOT NULL))";
            $stmtPending = $conn->prepare("
              SELECT COUNT(*) AS cnt
              FROM foundation_children c
              WHERE c.foundation_id = ?
                AND c.deleted_at IS NULL
                AND ({$existExpr})
                AND COALESCE(TRIM(c.update_text), '') = ''
                AND COALESCE(NULLIF(c.update_images, ''), '[]') IN ('[]', '')
            ");
          if ($stmtPending) {
            $stmtPending->bind_param("i", $fid);
            $stmtPending->execute();
            $pendingCnt = (int)(($stmtPending->get_result()->fetch_assoc()['cnt'] ?? 0));
            if ($pendingCnt > 0) {
              array_unshift($user_notifs, [
                'notif_id' => 0,
                'title' => 'อัปเดตผลลัพธ์เด็ก',
                'message' => "มีเด็กที่มีผู้อุปการะแล้ว {$pendingCnt} รายการ รออัปเดตผลลัพธ์",
                'link' => 'children_.php',
                'is_read' => 0,
                'created_at' => date('Y-m-d H:i:s'),
              ]);
              $user_notif_count += 1;
            }
          }
        }
      }
    }
  } catch (Throwable $e) {
    $user_notifs = [];
    $user_notif_count = 0;
  }
}

$_nav_depth = substr_count(str_replace(dirname(str_replace('\\','/',__FILE__)), '', str_replace('\\','/',dirname($_SERVER['SCRIPT_FILENAME']))), '/');
$_nav_base  = str_repeat('../', max(0, $_nav_depth));

// รูปโปรไฟล์มุมขวาบน (ผู้บริจาค / มูลนิธิ)
$nav_profile_img = $_nav_base . 'img/donor-avatar-placeholder.svg';
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
        }
      }
    }
  }
}

// โหมดดูตัวอย่างผู้บริจาค (foundation เท่านั้น)
if (isset($_GET['preview_mode'])) {
  if ($_GET['preview_mode'] === 'donor' && ($_SESSION['real_role'] ?? $_SESSION['role'] ?? '') === 'foundation') {
    $_SESSION['real_role'] = 'foundation';
    $_SESSION['role']      = 'donor';
  } elseif ($_GET['preview_mode'] === 'exit' && isset($_SESSION['real_role'])) {
    $_SESSION['role']      = $_SESSION['real_role'];
    unset($_SESSION['real_role']);
  }
  $redirect = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '/';
  while (ob_get_level() > 0) {
    ob_end_clean();
  }
  if (!headers_sent()) {
    header('Location: ' . $redirect);
    exit();
  }
  // หน้าที่ส่ง HTML ก่อน include navbar (เช่น about.php) — redirect ฝั่ง client (อย่าใส่ DOCTYPE ซ้ำ)
  $loc = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
  $json = json_encode($redirect, JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    $json = '"/"';
  }
  echo '<meta http-equiv="refresh" content="0;url=' . $loc . '"><script>location.replace(' . $json . ');</script>';
  exit();
}

$is_donor_preview = isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'foundation';
$foundation_account_pending = false;
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'foundation') {
  include_once __DIR__ . '/db.php';
  require_once __DIR__ . '/includes/foundation_account_verified.php';
  $foundation_account_pending = !drawdream_foundation_account_is_verified($conn);
}
$is_admin_mode = ($_SESSION['role'] ?? '') === 'admin';
$current_page = basename($_SERVER['PHP_SELF']);
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
<link rel="stylesheet" href="<?= $_nav_base ?>css/navbar.css?v=6">
<link rel="stylesheet" href="<?= $_nav_base ?>css/notif.css?v=3">
<?php if ($is_admin_mode): ?>
<script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>
<button type="button" class="admin-sidebar-show-btn" id="adminSidebarShowBtn" aria-label="แสดงเมนูแอดมิน">☰</button>
<aside class="admin-sidebar-nav">
  <div class="admin-sidebar-head-actions">
    <button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="ซ่อนเมนูแอดมิน">✕</button>
    <a href="<?= $_nav_base ?>admin_notifications.php" class="admin-sidebar-notif" title="คำขอรออนุมัติ — ศูนย์รวมคิว">
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<?php if ($is_donor_preview): ?>
<div class="donor-preview-banner">
  <span>&#128065; กำลังดูในโหมด <strong>มุมมองผู้บริจาค</strong> &mdash; เห็นเหมือนผู้บริจาคทั่วไป</span>
  <a href="?preview_mode=exit" class="donor-preview-exit">✕ ออกจากโหมดดูตัวอย่าง</a>
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
      <a href="<?= $_nav_base ?>children_.php" <?= basename($_SERVER['PHP_SELF']) == 'children_.php' ? 'class="active"' : '' ?>>บริจาค</a>
      <a href="<?= $_nav_base ?>project.php" <?= basename($_SERVER['PHP_SELF']) == 'project.php' ? 'class="active"' : '' ?>>โครงการ</a>
      <a href="<?= $_nav_base ?>foundation.php" <?= basename($_SERVER['PHP_SELF']) == 'foundation.php' ? 'class="active"' : '' ?>>มูลนิธิ</a>
      <a href="<?= $_nav_base ?>about.php" <?= basename($_SERVER['PHP_SELF']) == 'about.php' ? 'class="active"' : '' ?>>เกี่ยวกับเรา</a>
    </div>
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
          <button type="button" class="notif-btn" onclick="toggleNotif(event)" aria-label="แจ้งเตือน">
            <i class="bi bi-bell-fill nav-bell-icon" aria-hidden="true"></i>
            <?php if ($user_notif_count > 0): ?>
              <span class="notif-badge"><?= $user_notif_count ?></span>
            <?php endif; ?>
          </button>
          <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-header">
              <span class="notif-header-title">การแจ้งเตือน</span>
              <div class="notif-header-actions">
                <?php if ($user_notif_count > 0): ?>
                  <a href="<?= $_nav_base ?>mark_notif_read.php?all=1" class="notif-mark-all">อ่านทั้งหมด</a>
                <?php endif; ?>
                <?php if (in_array(($_SESSION['role'] ?? ''), ['foundation', 'donor'], true)): ?>
                  <a href="<?= $_nav_base ?>notifications.php" class="notif-see-all-header">ดูทั้งหมด</a>
                <?php endif; ?>
              </div>
            </div>
            <div class="notif-body-scroll">
            <?php if (empty($user_notifs)): ?>
              <div class="notif-empty">ยังไม่มีการแจ้งเตือน</div>
            <?php else: ?>
              <?php foreach ($user_notifs as $n): ?>
                <?php
                  $rawNotifLink = (string)($n['link'] ?? 'profile.php');
                  if (($n['title'] ?? '') === 'อัปเดตผลลัพธ์เด็กที่คุณอุปการะ'
                      && preg_match('#^children_donate\.php\?#', $rawNotifLink)
                      && strpos($rawNotifLink, 'view=outcome') === false) {
                      $rawNotifLink .= (str_contains($rawNotifLink, '?') ? '&' : '?') . 'view=outcome';
                  }
                ?>
                <a href="<?= htmlspecialchars($_nav_base . $rawNotifLink, ENT_QUOTES, 'UTF-8') ?>"
                   class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>"
                   onclick="<?php echo ((int)($n['notif_id'] ?? 0) > 0) ? 'markRead(' . (int)$n['notif_id'] . ')' : ''; ?>">
                  <div class="notif-item-title"><?= htmlspecialchars($n['title']) ?></div>
                  <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                  <div class="notif-item-time"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="nav-profile-wrap">
        <a href="<?= $_nav_base ?>profile.php" class="profile-btn nav-profile-desktop-only" title="โปรไฟล์">
          <img src="<?= htmlspecialchars($nav_profile_img, ENT_QUOTES, 'UTF-8') ?>" alt="โปรไฟล์" class="nav-icon nav-profile-photo" width="28" height="28" loading="lazy">
        </a>
        <button type="button" class="profile-btn nav-profile-mobile-only" id="navProfileMenuBtn" aria-expanded="false" aria-haspopup="true" aria-controls="navProfileMenu" title="เมนูบัญชี">
          <img src="<?= htmlspecialchars($nav_profile_img, ENT_QUOTES, 'UTF-8') ?>" alt="" class="nav-icon nav-profile-photo" width="28" height="28" loading="lazy">
        </button>
        <div class="nav-profile-menu" id="navProfileMenu" role="menu" hidden>
          <a href="<?= $_nav_base ?>profile.php" class="nav-profile-menu-item" role="menuitem">โปรไฟล์</a>
          <a href="<?= $_nav_base ?>logout.php" class="nav-profile-menu-item nav-profile-menu-logout" role="menuitem">ออกจากระบบ</a>
        </div>
      </div>
      <a href="<?= $_nav_base ?>logout.php" class="logout-btn nav-logout-desktop-only">ออกจากระบบ</a>

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
      if (window.innerWidth > 600) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
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
      if (window.innerWidth > 768 && typeof closeNavProfileMenu === 'function') closeNavProfileMenu();
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
  if (w) w.classList.toggle('open');
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
  if (w) w.classList.remove('open');
  closeNavProfileMenu();
});
function markRead(id) {
  fetch('<?= $_nav_base ?>mark_notif_read.php?id=' + id);
}
</script>