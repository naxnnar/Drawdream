<?php
// admin_notifications.php — ศูนย์รวมงานรออนุมัติและลิงก์คิว

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน notifications

include 'db.php';
require_once __DIR__ . '/includes/drawdream_project_status.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: login.php');
    exit();
}

$foundationPendings = [];
$childrenPendings = [];
$projectPendings = [];
$needPendings = [];

// มูลนิธิ/มูลนิธิโปรไฟล์
$qFoundation = mysqli_query($conn, "
    SELECT foundation_id, foundation_name, created_at
    FROM foundation_profile
    WHERE account_verified = 0
    ORDER BY created_at DESC, foundation_id DESC
    LIMIT 50
");
if ($qFoundation) {
    while ($row = mysqli_fetch_assoc($qFoundation)) {
        $foundationPendings[] = $row;
    }
}

// โปรไฟล์เด็ก
$qChildren = mysqli_query($conn, "
    SELECT c.child_id, c.child_name, c.foundation_name, c.approve_profile, c.status
    FROM foundation_children c
    WHERE COALESCE(c.approve_profile, 'รอดำเนินการ') IN ('รอดำเนินการ', 'กำลังดำเนินการ')
    ORDER BY c.child_id DESC
    LIMIT 50
");
if ($qChildren) {
    while ($row = mysqli_fetch_assoc($qChildren)) {
        $childrenPendings[] = $row;
    }
}

// โครงการ (รองรับทั้ง pending กับข้อความไทยรอดำเนินการ — db.php normalize แล้ว แต่คงเงื่อนไขคู่เพื่อความปลอดภัย)
$pendingExpr = drawdream_sql_project_is_pending('project_status');
$qProjects = mysqli_query($conn, "
    SELECT project_id, project_name, foundation_name, end_date, start_date
    FROM foundation_project
    WHERE {$pendingExpr}
    ORDER BY project_id DESC
    LIMIT 50
");
if ($qProjects) {
    while ($row = mysqli_fetch_assoc($qProjects)) {
        $projectPendings[] = $row;
    }
}

// มูลนิธิสิ่งของที่ต้องการ
$qNeeds = mysqli_query($conn, "
    SELECT nl.item_id, nl.item_name, nl.urgent, nl.created_at, fp.foundation_name
    FROM foundation_needlist nl
    LEFT JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
    WHERE nl.approve_item = 'pending'
    ORDER BY nl.urgent DESC, nl.item_id DESC
    LIMIT 50
");
if ($qNeeds) {
    while ($row = mysqli_fetch_assoc($qNeeds)) {
        $needPendings[] = $row;
    }
}

$totalAll = count($foundationPendings) + count($childrenPendings) + count($projectPendings) + count($needPendings);

$doneBanner = '';
if (isset($_GET['bulk']) && isset($_GET['msg'])) {
    $doneBanner = trim((string)$_GET['msg']);
} elseif (isset($_GET['done'])) {
    if ($_GET['done'] === 'project') {
        $doneBanner = 'ดำเนินการโครงการแล้ว';
    } elseif ($_GET['done'] === 'foundation') {
        $doneBanner = 'ดำเนินการคำขอมูลนิธิแล้ว';
    } elseif ($_GET['done'] === 'need') {
        $m = isset($_GET['msg']) ? trim((string)$_GET['msg']) : '';
        $doneBanner = $m !== '' ? $m : 'ดำเนินการรายการสิ่งของแล้ว';
    }
}
if (isset($_GET['err'])) {
    $doneBanner = 'ไม่สามารถดำเนินการได้ หรือรายการถูกจัดการแล้ว';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>คำขอรออนุมัติ | DrawDream Admin</title>
  <link rel="stylesheet" href="css/navbar.css">
  <style>
    .admin-notif-wrap {
      width: 100%;
      max-width: 1200px;
      margin: 14px auto 24px;
      font-family: 'Prompt', sans-serif;
    }
    .admin-notif-head {
      background: #fff;
      border-radius: 16px;
      padding: 18px 20px;
      box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
      margin-bottom: 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .admin-notif-title {
      font-size: 1.45rem;
      font-weight: 700;
      color: #1f2937;
      margin: 0;
    }
    .admin-notif-title i {
      color: #4e3b84;
      margin-right: 6px;
      font-size: 1.2rem;
    }
    .admin-notif-total {
      background: #4e3b84;
      color: #fff;
      border-radius: 999px;
      padding: 8px 14px;
      font-size: .95rem;
      font-weight: 700;
    }
    .admin-notif-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 14px;
    }
    .admin-notif-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
      overflow: hidden;
      border: 1px solid #ece8ff;
    }
    .admin-notif-card-head {
      padding: 12px 14px;
      background: linear-gradient(90deg, rgba(78,59,132,0.1), rgba(78,59,132,0.03));
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }
    .admin-notif-card-title {
      margin: 0;
      font-size: 1rem;
      font-weight: 700;
      color: #31245b;
    }
    .admin-notif-card-title i {
      margin-right: 6px;
      color: #4e3b84;
      font-size: .98rem;
    }
    .admin-notif-count {
      min-width: 28px;
      height: 28px;
      padding: 0 8px;
      border-radius: 999px;
      background: #ff8468;
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: .86rem;
      font-weight: 700;
    }
    .admin-notif-list {
      list-style: none;
      margin: 0;
      padding: 10px;
      display: grid;
      gap: 8px;
      max-height: 420px;
      overflow: auto;
    }
    .admin-notif-item {
      border: 1px solid #ede9ff;
      border-radius: 12px;
      padding: 10px;
      background: #fbfaff;
    }
    .admin-notif-item strong {
      color: #1f2937;
      font-size: .96rem;
      display: block;
      margin-bottom: 4px;
    }
    .admin-notif-meta {
      color: #6b7280;
      font-size: .84rem;
      margin-bottom: 7px;
      line-height: 1.4;
    }
    .admin-notif-link {
      display: inline-block;
      text-decoration: none;
      color: #fff;
      background: #4e3b84;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: .84rem;
      font-weight: 600;
    }
    .admin-notif-item-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      margin-top: 6px;
    }
    .admin-notif-empty {
      color: #6b7280;
      font-size: .93rem;
      padding: 16px 12px;
    }
    .admin-notif-card[id] {
      scroll-margin-top: 88px;
    }
    .admin-notif-done-banner {
      max-width: 1200px;
      margin: 0 auto 14px;
      padding: 12px 16px;
      border-radius: 12px;
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
      font-size: .95rem;
      font-weight: 600;
    }
    .admin-notif-done-banner--err {
      background: #fef2f2;
      color: #b91c1c;
      border-color: #fecaca;
    }
    .admin-notif-bulk-bar {
      background: #fff;
      border-radius: 14px;
      padding: 12px 14px;
      margin-bottom: 14px;
      box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
      border: 1px solid #e8e4ff;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 14px;
    }
    .admin-notif-bulk-hint {
      color: #6b7280;
      font-size: .88rem;
      flex: 1 1 200px;
    }
    .admin-notif-bulk-btn {
      border: none;
      border-radius: 999px;
      padding: 8px 16px;
      font-size: .88rem;
      font-weight: 700;
      cursor: pointer;
      font-family: inherit;
    }
    .admin-notif-bulk-btn--primary {
      background: #22a06b;
      color: #fff;
    }
    .admin-notif-bulk-btn--primary:disabled {
      background: #b8c9c0;
      cursor: not-allowed;
    }
    .admin-notif-bulk-btn--ghost {
      background: #f3f0ff;
      color: #4e3b84;
    }
    .admin-notif-section-tools {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      padding: 8px 10px 0;
      border-bottom: 1px solid #f0ecff;
    }
    .admin-notif-select-all {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: .84rem;
      color: #4b5563;
      cursor: pointer;
      user-select: none;
    }
    .admin-notif-select-all input {
      width: 16px;
      height: 16px;
      accent-color: #4e3b84;
    }
    .admin-notif-item-row {
      display: flex;
      gap: 10px;
      align-items: flex-start;
    }
    .admin-notif-item-check {
      margin-top: 3px;
      width: 16px;
      height: 16px;
      flex-shrink: 0;
      accent-color: #4e3b84;
    }
    .admin-notif-item-body {
      flex: 1;
      min-width: 0;
    }
    @media (max-width: 991px) {
      .admin-notif-wrap {
        margin: 8px 0 20px;
      }
      .admin-notif-head {
        flex-direction: column;
        align-items: flex-start;
      }
      .admin-notif-title {
        font-size: 1.2rem;
      }
      .admin-notif-grid {
        grid-template-columns: 1fr;
      }
      .admin-notif-card[id] {
        scroll-margin-top: 72px;
      }
      .admin-notif-bulk-bar {
        flex-direction: column;
        align-items: stretch;
      }
      .admin-notif-bulk-btn {
        width: 100%;
        min-height: 44px;
      }
    }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-notif-wrap">
  <div class="admin-notif-head">
    <h1 class="admin-notif-title"><i class="bi bi-bell-fill" aria-hidden="true"></i> คำขอรออนุมัติ</h1>
    <span class="admin-notif-total">ทั้งหมด <?php echo (int)$totalAll; ?> รายการ</span>
  </div>
  <?php if ($doneBanner !== ''): ?>
    <?php
      $bannerIsErr = isset($_GET['err']) || (isset($_GET['bulk']) && (int)($_GET['approved'] ?? 0) === 0);
    ?>
    <div class="admin-notif-done-banner<?= $bannerIsErr ? ' admin-notif-done-banner--err' : '' ?>"><?= htmlspecialchars($doneBanner) ?></div>
  <?php endif; ?>

  <form method="post" action="admin_bulk_approve.php" id="adminBulkForm">
    <?= drawdream_csrf_field() ?>
    <input type="hidden" name="bulk_scope" id="bulkScope" value="all">

    <?php if ($totalAll > 0): ?>
    <div class="admin-notif-bulk-bar">
      <span class="admin-notif-bulk-hint">เลือกหลายรายการแล้วกดอนุมัติพร้อมกันได้ — รายการสิ่งของที่ต้องปรับราคาให้ใช้ «ตรวจสอบ / แก้ราคา» ทีละรายการ</span>
      <button type="button" class="admin-notif-bulk-btn admin-notif-bulk-btn--ghost" id="btnSelectAllGlobal">เลือกทั้งหมด</button>
      <button type="submit" class="admin-notif-bulk-btn admin-notif-bulk-btn--primary" id="btnApproveAllGlobal" data-scope="all" disabled>อนุมัติที่เลือกทั้งหมด</button>
    </div>
    <?php endif; ?>

  <div class="admin-notif-grid">
    <section class="admin-notif-card" id="admin-pending-foundations">
      <div class="admin-notif-card-head">
        <h2 class="admin-notif-card-title"><i class="bi bi-building" aria-hidden="true"></i> มูลนิธิ / มูลนิธิโปรไฟล์</h2>
        <span class="admin-notif-count"><?php echo count($foundationPendings); ?></span>
      </div>
      <?php if (!empty($foundationPendings)): ?>
      <div class="admin-notif-section-tools">
        <label class="admin-notif-select-all"><input type="checkbox" class="js-select-section" data-section="foundation"> เลือกทั้งหมด</label>
        <button type="submit" class="admin-notif-bulk-btn admin-notif-bulk-btn--primary js-bulk-approve-section" data-scope="foundation" disabled>อนุมัติที่เลือก</button>
      </div>
      <?php endif; ?>
      <ul class="admin-notif-list">
        <?php if (empty($foundationPendings)): ?>
          <li class="admin-notif-empty">ไม่มีคำขอใหม่</li>
        <?php else: ?>
          <?php foreach ($foundationPendings as $f): ?>
            <li class="admin-notif-item">
              <div class="admin-notif-item-row">
                <input type="checkbox" class="admin-notif-item-check js-bulk-check" data-section="foundation" name="foundation_ids[]" value="<?php echo (int)$f['foundation_id']; ?>">
                <div class="admin-notif-item-body">
              <strong><?php echo htmlspecialchars($f['foundation_name'] ?? 'ไม่ระบุชื่อมูลนิธิ'); ?></strong>
              <div class="admin-notif-meta">สมัครเมื่อ: <?php echo !empty($f['created_at']) ? date('d/m/Y H:i', strtotime($f['created_at'])) : '-'; ?></div>
              <div class="admin-notif-item-actions">
                <a class="admin-notif-link" href="admin_approve_foundation.php?id=<?php echo (int)$f['foundation_id']; ?>">ตรวจสอบ</a>
              </div>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </section>

    <section class="admin-notif-card" id="admin-pending-children">
      <div class="admin-notif-card-head">
        <h2 class="admin-notif-card-title"><i class="bi bi-person-badge" aria-hidden="true"></i> โปรไฟล์เด็ก</h2>
        <span class="admin-notif-count"><?php echo count($childrenPendings); ?></span>
      </div>
      <?php if (!empty($childrenPendings)): ?>
      <div class="admin-notif-section-tools">
        <label class="admin-notif-select-all"><input type="checkbox" class="js-select-section" data-section="child"> เลือกทั้งหมด</label>
        <button type="submit" class="admin-notif-bulk-btn admin-notif-bulk-btn--primary js-bulk-approve-section" data-scope="child" disabled>อนุมัติที่เลือก</button>
      </div>
      <?php endif; ?>
      <ul class="admin-notif-list">
        <?php if (empty($childrenPendings)): ?>
          <li class="admin-notif-empty">ไม่มีโปรไฟล์เด็กรออนุมัติ</li>
        <?php else: ?>
          <?php foreach ($childrenPendings as $c): ?>
            <li class="admin-notif-item">
              <div class="admin-notif-item-row">
                <input type="checkbox" class="admin-notif-item-check js-bulk-check" data-section="child" name="child_ids[]" value="<?php echo (int)$c['child_id']; ?>">
                <div class="admin-notif-item-body">
              <strong><?php echo htmlspecialchars($c['child_name'] ?? 'ไม่ระบุชื่อ'); ?></strong>
              <div class="admin-notif-meta">มูลนิธิ: <?php echo htmlspecialchars($c['foundation_name'] ?? '-'); ?> | สถานะ: <?php echo htmlspecialchars($c['approve_profile'] ?? 'รอดำเนินการ'); ?></div>
              <div class="admin-notif-item-actions">
                <a class="admin-notif-link" href="children_donate.php?id=<?php echo (int)$c['child_id']; ?>">ตรวจสอบ</a>
              </div>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </section>

    <section class="admin-notif-card" id="admin-pending-projects">
      <div class="admin-notif-card-head">
        <h2 class="admin-notif-card-title"><i class="bi bi-kanban" aria-hidden="true"></i> โครงการ</h2>
        <span class="admin-notif-count"><?php echo count($projectPendings); ?></span>
      </div>
      <?php if (!empty($projectPendings)): ?>
      <div class="admin-notif-section-tools">
        <label class="admin-notif-select-all"><input type="checkbox" class="js-select-section" data-section="project"> เลือกทั้งหมด</label>
        <button type="submit" class="admin-notif-bulk-btn admin-notif-bulk-btn--primary js-bulk-approve-section" data-scope="project" disabled>อนุมัติที่เลือก</button>
      </div>
      <?php endif; ?>
      <ul class="admin-notif-list">
        <?php if (empty($projectPendings)): ?>
          <li class="admin-notif-empty">ไม่มีโครงการรออนุมัติ</li>
        <?php else: ?>
          <?php foreach ($projectPendings as $p): ?>
            <li class="admin-notif-item">
              <div class="admin-notif-item-row">
                <input type="checkbox" class="admin-notif-item-check js-bulk-check" data-section="project" name="project_ids[]" value="<?php echo (int)$p['project_id']; ?>">
                <div class="admin-notif-item-body">
              <strong><?php echo htmlspecialchars($p['project_name'] ?? '-'); ?></strong>
              <div class="admin-notif-meta">มูลนิธิ: <?php echo htmlspecialchars($p['foundation_name'] ?? '-'); ?> | ปิดรับ: <?php echo htmlspecialchars($p['end_date'] ?? '-'); ?></div>
              <div class="admin-notif-item-actions">
                <a class="admin-notif-link" href="admin_approve_projects.php?id=<?php echo (int)$p['project_id']; ?>">ตรวจสอบ</a>
              </div>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </section>

    <section class="admin-notif-card" id="admin-pending-needs">
      <div class="admin-notif-card-head">
        <h2 class="admin-notif-card-title"><i class="bi bi-box-seam" aria-hidden="true"></i> มูลนิธิสิ่งของที่ต้องการ</h2>
        <span class="admin-notif-count"><?php echo count($needPendings); ?></span>
      </div>
      <?php if (!empty($needPendings)): ?>
      <div class="admin-notif-section-tools">
        <label class="admin-notif-select-all"><input type="checkbox" class="js-select-section" data-section="need"> เลือกทั้งหมด</label>
        <button type="submit" class="admin-notif-bulk-btn admin-notif-bulk-btn--primary js-bulk-approve-section" data-scope="need" disabled>อนุมัติที่เลือก (ราคาที่เสนอ)</button>
      </div>
      <?php endif; ?>
      <ul class="admin-notif-list">
        <?php if (empty($needPendings)): ?>
          <li class="admin-notif-empty">ไม่มีรายการสิ่งของรออนุมัติ</li>
        <?php else: ?>
          <?php foreach ($needPendings as $n): ?>
            <li class="admin-notif-item">
              <div class="admin-notif-item-row">
                <input type="checkbox" class="admin-notif-item-check js-bulk-check" data-section="need" name="need_ids[]" value="<?php echo (int)$n['item_id']; ?>">
                <div class="admin-notif-item-body">
              <strong><?php echo htmlspecialchars($n['item_name'] ?? '-'); ?></strong>
              <div class="admin-notif-meta">มูลนิธิ: <?php echo htmlspecialchars($n['foundation_name'] ?? '-'); ?><?php echo ((int)($n['urgent'] ?? 0) === 1) ? ' | ด่วน' : ''; ?><?php
                $needCAt = trim((string)($n['created_at'] ?? ''));
                if ($needCAt !== '' && $needCAt !== '0000-00-00 00:00:00') {
                    echo ' | เสนอเมื่อ: ' . htmlspecialchars(date('d/m/Y H:i', strtotime($needCAt)));
                }
              ?></div>
              <div class="admin-notif-item-actions">
                <a class="admin-notif-link" href="admin_approve_needlist.php?item_id=<?php echo (int)$n['item_id']; ?>">ตรวจสอบ / แก้ราคา</a>
              </div>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </section>
  </div>
  </form>
</div>

<script>
(function () {
  var form = document.getElementById('adminBulkForm');
  if (!form) return;

  var scopeInput = document.getElementById('bulkScope');
  var checks = Array.prototype.slice.call(form.querySelectorAll('.js-bulk-check'));
  var sectionSelectAll = Array.prototype.slice.call(form.querySelectorAll('.js-select-section'));
  var sectionBtns = Array.prototype.slice.call(form.querySelectorAll('.js-bulk-approve-section'));
  var globalBtn = document.getElementById('btnApproveAllGlobal');
  var globalSelect = document.getElementById('btnSelectAllGlobal');

  function countSelected(section) {
    return checks.filter(function (cb) {
      return cb.checked && (!section || cb.getAttribute('data-section') === section);
    }).length;
  }

  function syncUi() {
    var total = countSelected('');
    if (globalBtn) {
      globalBtn.disabled = total === 0;
      globalBtn.textContent = total > 0
        ? ('อนุมัติที่เลือกทั้งหมด (' + total + ')')
        : 'อนุมัติที่เลือกทั้งหมด';
    }
    sectionBtns.forEach(function (btn) {
      var section = btn.getAttribute('data-scope') || '';
      var n = countSelected(section);
      btn.disabled = n === 0;
      var base = btn.getAttribute('data-base-label') || btn.textContent.replace(/\s*\(\d+\)\s*$/, '');
      btn.setAttribute('data-base-label', base);
      btn.textContent = n > 0 ? (base + ' (' + n + ')') : base;
    });
    sectionSelectAll.forEach(function (master) {
      var section = master.getAttribute('data-section') || '';
      var inSection = checks.filter(function (cb) { return cb.getAttribute('data-section') === section; });
      if (inSection.length === 0) {
        master.checked = false;
        master.indeterminate = false;
        return;
      }
      var picked = inSection.filter(function (cb) { return cb.checked; }).length;
      master.checked = picked === inSection.length;
      master.indeterminate = picked > 0 && picked < inSection.length;
    });
  }

  checks.forEach(function (cb) {
    cb.addEventListener('change', syncUi);
  });

  sectionSelectAll.forEach(function (master) {
    master.addEventListener('change', function () {
      var section = master.getAttribute('data-section') || '';
      checks.forEach(function (cb) {
        if (cb.getAttribute('data-section') === section) {
          cb.checked = master.checked;
        }
      });
      syncUi();
    });
  });

  if (globalSelect) {
    globalSelect.addEventListener('click', function () {
      var allOn = countSelected('') === checks.length;
      checks.forEach(function (cb) { cb.checked = !allOn; });
      syncUi();
    });
  }

  sectionBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (scopeInput) scopeInput.value = btn.getAttribute('data-scope') || 'all';
    });
  });

  if (globalBtn) {
    globalBtn.addEventListener('click', function () {
      if (scopeInput) scopeInput.value = 'all';
    });
  }

  form.addEventListener('submit', function (e) {
    var n = countSelected(scopeInput && scopeInput.value !== 'all' ? scopeInput.value : '');
    if (scopeInput && scopeInput.value === 'all') {
      n = countSelected('');
    }
    if (n <= 0) {
      e.preventDefault();
      alert('กรุณาเลือกรายการที่ต้องการอนุมัติ');
      return;
    }
    if (!confirm('ยืนยันอนุมัติ ' + n + ' รายการที่เลือก?')) {
      e.preventDefault();
    }
  });

  syncUi();
})();
</script>

</body>
</html>
