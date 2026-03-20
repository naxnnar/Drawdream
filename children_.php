<?php
// ------------------------------
// Session and database bootstrap
// ------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php'; // เชื่อมต่อฐานข้อมูล

// ------------------------------
// Current user context
// ------------------------------
$role = $_SESSION['role'] ?? 'donor';
$foundationId = null;

// ดึง foundation_id เฉพาะตอนผู้ใช้เป็นมูลนิธิ เพื่อลด query ที่ไม่จำเป็น
if ($role === 'foundation') {
  $currentUserId = (int)($_SESSION['user_id'] ?? 0);
  if ($currentUserId > 0) {
    $sqls = "SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1";
    $stmtFP = $conn->prepare($sqls);
    $stmtFP->bind_param("i", $currentUserId);
    $stmtFP->execute();
    $resultFP = $stmtFP->get_result();
    $FPArr = $resultFP->fetch_assoc();
    if (!empty($FPArr['foundation_id'])) {
      $foundationId = (int)$FPArr['foundation_id'];
    }
  }
}

// Auto-migrate is_hidden column (run every request to ensure column exists before SELECT)
$checkIsHidden = $conn->query("SHOW COLUMNS FROM Children LIKE 'is_hidden'");
if ($checkIsHidden->num_rows === 0) {
  $conn->query("ALTER TABLE Children ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
}

if ($role === 'foundation' && isset($_POST['bulk_action'])) {
  $bulkAction = $_POST['bulk_action'] ?? 'delete';
  $selectedIds = $_POST['child_ids'] ?? [];
  $deleteReason = trim($_POST['delete_reason'] ?? '');
  $childIds = [];
  foreach ($selectedIds as $id) {
    $n = (int)$id;
    if ($n > 0) $childIds[] = $n;
  }

  if ($deleteReason === '') {
    header('Location: children_.php?msg=' . urlencode('กรุณาเลือกเหตุผลก่อนยืนยัน'));
    exit();
  }

  if (!empty($childIds) && $foundationId !== null) {
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $types = 'i' . str_repeat('i', count($childIds));
    $params = array_merge([$foundationId], $childIds);

    if ($bulkAction === 'hide') {
      $sqlAct = "UPDATE Children SET is_hidden=1 WHERE foundation_id=? AND child_id IN ($placeholders)";
      $stmtAct = $conn->prepare($sqlAct);
      $stmtAct->bind_param($types, ...$params);
      $stmtAct->execute();
      $affectedCount = $stmtAct->affected_rows;
      header('Location: children_.php?msg=' . urlencode("ซ่อนโปรไฟล์สำเร็จ {$affectedCount} รายการ"));
    } else {
      $sqlAct = "DELETE FROM Children WHERE foundation_id=? AND child_id IN ($placeholders)";
      $stmtAct = $conn->prepare($sqlAct);
      $stmtAct->bind_param($types, ...$params);
      $stmtAct->execute();
      $affectedCount = $stmtAct->affected_rows;
      header('Location: children_.php?msg=' . urlencode("ลบโปรไฟล์สำเร็จ {$affectedCount} รายการ"));
    }
    exit();
  }

  header('Location: children_.php?msg=' . urlencode('กรุณาเลือกโปรไฟล์ที่ต้องการดำเนินการ'));
  exit();
}

// ------------------------------
// Build listing query by role
// ------------------------------
if ($role === 'donor') {
  $sql = "SELECT * FROM Children WHERE approve_profile = 'อนุมัติ' AND is_hidden = 0 ORDER BY child_id DESC";
  $result = $conn->query($sql);
} elseif ($role === 'foundation') {
  if ($foundationId !== null) {
    $stmt = $conn->prepare("SELECT * FROM Children WHERE foundation_id = ? ORDER BY child_id DESC");
    $stmt->bind_param("i", $foundationId);
    $stmt->execute();
    $result = $stmt->get_result();
  } else {
    $result = false;
  }
} else {
  $sql = "SELECT * FROM Children ORDER BY child_id DESC";
  $result = $conn->query($sql);
}

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
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }

    .foundation-action-buttons {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      width: 100%;
      justify-content: flex-start;
    }

    .btn-create-profile {
      background: #3C5099;
      color: #fff;
      border: 0;
      border-radius: 10px;
      font-weight: 800;
      padding: 9px 16px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .btn-delete-selected {
      background: #CE573F;
      color: #fff;
      border: 0;
      border-radius: 10px;
      font-weight: 800;
      padding: 9px 16px;
      margin-left: auto;
    }

    .btn-toggle-edit {
      background: #F1CF54;
      color: #1f2937;
      border: 0;
      border-radius: 10px;
      font-weight: 800;
      padding: 9px 16px;
    }

    .btn-delete-selected:disabled {
      opacity: 0.55;
      cursor: not-allowed;
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

    .child-card-wrap {
      width: 100%;
      display: flex;
      flex-direction: column;
      position: relative;
    }

    .child-card-wrap .child-card {
      flex: 1;
    }

    .card-actions {
      display: none;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      margin-top: 8px;
      padding: 4px 2px;
    }

    body.mode-edit .card-actions.edit-mode {
      display: flex;
    }

    .delete-corner {
      display: none !important;
      position: absolute;
      top: 12px;
      right: 12px;
      z-index: 6;
      padding: 0;
    }

    .delete-corner input[type="checkbox"] {
      width: 22px;
      height: 22px;
      accent-color: #CE573F;
      border-radius: 6px;
      cursor: pointer;
    }

    body.mode-delete .delete-corner {
      display: inline-flex !important;
    }

    body.mode-delete .child-status-pill {
      display: none;
    }

    .inline-delete-actions {
      display: none;
      margin-top: 8px;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    body.mode-delete .inline-delete-actions {
      display: inline-flex;
    }

    .inline-delete-actions .confirm-inline {
      background: #CE573F;
      color: #fff;
      border: 0;
      border-radius: 999px;
      padding: 7px 14px;
      font-size: .88rem;
      font-weight: 800;
    }

    .inline-delete-actions .cancel-inline {
      background: #fff;
      color: #24324a;
      border: 1.5px solid #c7cdd7;
      border-radius: 999px;
      padding: 7px 14px;
      font-size: .88rem;
      font-weight: 800;
    }

    .btn-edit-profile {
      background: #F1CF54;
      color: #1f2937;
      border: 0;
      border-radius: 999px;
      padding: 8px 14px;
      font-size: 0.9rem;
      font-weight: 800;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
    }

    .delete-check-wrap {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.85rem;
      font-weight: 700;
      color: #24324a;
      white-space: nowrap;
    }

    .delete-check-wrap input[type="checkbox"] {
      width: 18px;
      height: 18px;
      accent-color: #CE573F;
    }

    .reject-reason {
      margin-top: 7px;
      font-size: 0.84rem;
      color: #b3422f;
      font-weight: 700;
      line-height: 1.25;
    }

    .donation-grid .card-info {
      padding-top: 6px;
    }

    .donation-grid .card-img {
      border-radius: 16px;
    }

    .donation-grid .card-info h3 {
      font-size: 1.42rem;
      margin-top: 10px;
      margin-bottom: 8px;
      font-weight: 800;
      letter-spacing: 0.2px;
      color: #13213a;
    }

    .donation-grid .card-info p {
      font-size: 0.92rem;
      margin: 3px 0;
    }

    .meta-row {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #24324a;
      line-height: 1.25;
      font-weight: 600;
    }

    .meta-icon {
      width: 24px;
      height: 24px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      flex: 0 0 24px;
      box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12);
    }

    .meta-icon.age {
      background: #ffe5cf;
      color: #d9602f;
    }

    .meta-icon.dream {
      background: #e5f0ff;
      color: #2f59c7;
    }

    .meta-icon.foundation {
      background: #e6f8ed;
      color: #2f8f55;
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
        font-size: 1.18rem;
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

    /* ─── Reason Bottom Sheet ─── */
    .reason-sheet-overlay {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 1055;
      background: rgba(0,0,0,0.45);
      align-items: flex-end;
      justify-content: center;
    }
    .reason-sheet-overlay.open { display: flex; }
    .reason-sheet-panel {
      background: #fff;
      width: 100%;
      max-width: 560px;
      border-radius: 24px 24px 0 0;
      padding: 24px 20px 28px;
      animation: sheetSlideUp 0.28s ease;
      max-height: 90vh;
      overflow-y: auto;
    }
    @keyframes sheetSlideUp {
      from { transform: translateY(100%); }
      to   { transform: translateY(0); }
    }
    .reason-sheet-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 6px;
    }
    .reason-sheet-title { font-size: 1.12rem; font-weight: 800; color: #13213a; }
    .reason-sheet-subtitle { font-size: 0.86rem; color: #6b7280; margin-top: 2px; }
    .reason-sheet-close {
      background: none; border: none;
      font-size: 1.4rem; line-height: 1;
      color: #9ca3af; cursor: pointer; padding: 0 4px;
    }
    .reason-sheet-prompt { font-size: 0.9rem; color: #374151; font-weight: 600; margin-bottom: 14px; }
    .reason-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
    .reason-option {
      background: #f9fafb;
      border: 2px solid #e5e7eb;
      border-radius: 16px;
      padding: 14px 10px;
      display: flex; flex-direction: column; align-items: center; gap: 8px;
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s;
      text-align: center;
    }
    .reason-option:hover, .reason-option.selected { border-color: #CE573F; background: #fff5f3; }
    .reason-option-hide:hover, .reason-option-hide.selected { border-color: #6b7280 !important; background: #f3f4f6 !important; }
    .reason-icon {
      width: 54px; height: 54px;
      border-radius: 14px;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 1.45rem;
    }
    .reason-label { font-size: 0.80rem; font-weight: 700; color: #374151; line-height: 1.3; }
    .reason-sheet-footer { display: flex; gap: 10px; margin-top: 4px; }
    .reason-confirm-btn {
      flex: 1; background: #CE573F; color: #fff; border: 0;
      border-radius: 999px; padding: 12px;
      font-size: 0.96rem; font-weight: 800;
      transition: background 0.15s;
    }
    .reason-confirm-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .reason-cancel-btn {
      flex: 1; background: #fff; color: #374151;
      border: 1.5px solid #d1d5db;
      border-radius: 999px; padding: 12px;
      font-size: 0.96rem; font-weight: 700;
    }

  </style>

</head>
 
<body class="donation-page donation-role-<?php echo htmlspecialchars($role); ?>">

<?php include 'navbar.php'; ?>

<!-- Main content area -->
<div class="donation-shell">

<!-- Top action buttons (role-based) -->
<div class="donation-top-actions">
  <?php if ($role === 'foundation'): ?>
    <div class="foundation-action-buttons">
      <a href="foundation_add_children.php" class="btn-create-profile">+ สร้างโปรไฟล์เด็ก</a>
      <button type="button" id="toggleEditModeBtn" class="btn-toggle-edit">แก้ไขโปรไฟล์</button>
      <button type="button" id="toggleDeleteModeBtn" class="btn-delete-selected">ลบโปรไฟล์</button>
    </div>
  <?php else: ?>
    <div></div>
  <?php endif; ?>
  <div>
    <?php if (!empty($_GET['msg'] ?? '')): ?>
      <span style="font-weight:700;color:#2b4b80;"><?php echo htmlspecialchars($_GET['msg']); ?></span>
    <?php endif; ?>
  </div>
</div>

<?php if ($role === 'foundation'): ?>
<form method="POST" id="bulkDeleteForm">
  <input type="hidden" name="bulk_action" id="bulkActionInput" value="delete">
  <input type="hidden" name="delete_reason" id="deleteReasonInput" value="">
</form>

<!-- Delete/Hide Reason Bottom Sheet -->
<div id="reasonSheetOverlay" class="reason-sheet-overlay">
  <div class="reason-sheet-panel">
    <div class="reason-sheet-header">
      <div>
        <div class="reason-sheet-title">กรุณาเลือกเหตุผล</div>
        <div class="reason-sheet-subtitle" id="reasonSheetCount">เลือกแล้ว 0 โปรไฟล์</div>
      </div>
      <button type="button" id="closeReasonSheet" class="reason-sheet-close" aria-label="ปิด">&#x2715;</button>
    </div>
    <p class="reason-sheet-prompt">เลือกเหตุผลเพื่อดำเนินการต่อ</p>
    <div class="reason-grid">
      <button type="button" class="reason-option" data-reason="เด็กพ้นสภาพการดูแล (Case Closure)" data-action="delete">
        <span class="reason-icon" style="background:#e8f5e9;color:#2e7d32;"><i class="bi bi-person-check-fill"></i></span>
        <span class="reason-label">เด็กพ้นสภาพ<br>การดูแล</span>
      </button>
      <button type="button" class="reason-option" data-reason="บรรลุวัตถุประสงค์การรับบริจาค (Goal Reached)" data-action="delete">
        <span class="reason-icon" style="background:#e3f2fd;color:#1565c0;"><i class="bi bi-award-fill"></i></span>
        <span class="reason-label">บรรลุวัตถุประสงค์<br>การรับบริจาค</span>
      </button>
      <button type="button" class="reason-option" data-reason="ความปลอดภัยและสิทธิเด็ก (Child Protection)" data-action="delete">
        <span class="reason-icon" style="background:#fce4ec;color:#c62828;"><i class="bi bi-shield-fill"></i></span>
        <span class="reason-label">ความปลอดภัย<br>และสิทธิเด็ก</span>
      </button>
      <button type="button" class="reason-option" data-reason="การเปลี่ยนแปลงสถานะทางสุขภาพ (Health Status Change)" data-action="delete">
        <span class="reason-icon" style="background:#fff3e0;color:#e65100;"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="reason-label">การเปลี่ยนแปลง<br>สุขภาพ</span>
      </button>
      <button type="button" class="reason-option" data-reason="ตรวจพบข้อมูลไม่ถูกต้อง (Data Integrity)" data-action="delete">
        <span class="reason-icon" style="background:#f3e5f5;color:#6a1b9a;"><i class="bi bi-exclamation-triangle-fill"></i></span>
        <span class="reason-label">ตรวจพบข้อมูล<br>ไม่ถูกต้อง</span>
      </button>
      <button type="button" class="reason-option reason-option-hide" data-reason="ปิดการมองเห็นชั่วคราว" data-action="hide">
        <span class="reason-icon" style="background:#f3f4f6;color:#6b7280;"><i class="bi bi-eye-slash-fill"></i></span>
        <span class="reason-label">ปิดการมองเห็น<br>(ไม่ลบข้อมูล)</span>
      </button>
    </div>
    <div class="reason-sheet-footer">
      <button type="button" id="confirmReasonBtn" class="reason-confirm-btn" disabled>ยืนยัน</button>
      <button type="button" id="cancelReasonBtn" class="reason-cancel-btn">ยกเลิก</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Section: Children waiting for sponsors -->
<h2 class="section-title danger donation-band">บริจาคให้เด็กรายบุคคล</h2>

<div class="container py-4">
  <div class="row donation-grid row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-6 g-4">
    <?php foreach ($unadopted as $child): ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <?php
        $rawStatus = $child['approve_profile'] ?? 'รอดำเนินการ';
        if ($rawStatus === 'กำลังดำเนินการ') $rawStatus = 'รอดำเนินการ';
        $statusClass = 'status-pending';
        $statusText = $rawStatus;
        if ($rawStatus === 'อนุมัติ') {
          $statusClass = 'status-approved';
          $statusText = 'อนุมัติแล้ว';
        } elseif ($rawStatus === 'ไม่อนุมัติ') {
          $statusClass = 'status-rejected';
        }
        $canEdit = ($role === 'foundation' && $rawStatus === 'อนุมัติ');
      ?>
      <div class="child-card-wrap">
        <a href="children_donate.php?id=<?php echo $child['child_id']; ?>" class="child-card">
          <label class="delete-corner">
            <input type="checkbox" class="delete-check" name="child_ids[]" value="<?php echo (int)$child['child_id']; ?>" form="bulkDeleteForm" aria-label="เลือกลบโปรไฟล์นี้">
          </label>
          <div class="card-img danger-bg">
            <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="รูปเด็ก">
          </div>
          <div class="card-info">
              <h3><?php echo htmlspecialchars($child['child_name']); ?></h3>
              <p class="meta-row"><span class="meta-icon age"><i class="bi bi-cake2-fill"></i></span> <?php echo $child['age']; ?> ปี</p>
              <p class="meta-row"><span class="meta-icon dream"><i class="bi bi-stars"></i></span> <?php echo htmlspecialchars($child['dream']); ?></p>
              <p class="meta-row"><span class="meta-icon foundation"><i class="bi bi-house-heart-fill"></i></span> <?php echo htmlspecialchars($child['foundation_name'] ?? '-'); ?></p>
              <?php if ($role === 'foundation' || $role === 'admin'): ?>
                  <div class="child-status-pill <?php echo $statusClass; ?>">
                    <?php echo $statusText; ?>
                  </div>
                  <?php if ($role === 'foundation'): ?>
                    <div class="inline-delete-actions">
                      <button type="button" class="confirm-inline">ยืนยันลบ</button>
                      <button type="button" class="cancel-inline">ยกเลิก</button>
                    </div>
                  <?php endif; ?>
              <?php endif; ?>
              <?php if ($role === 'foundation' && $rawStatus === 'ไม่อนุมัติ' && !empty($child['reject_reason'] ?? '')): ?>
                  <p class="reject-reason">เหตุผลไม่อนุมัติ: <?php echo htmlspecialchars($child['reject_reason']); ?></p>
              <?php endif; ?>
          </div>
        </a>

        <?php if ($role === 'foundation'): ?>
          <div class="card-actions edit-mode">
            <?php if ($canEdit): ?>
              <a href="foundation_edit_child.php?id=<?php echo (int)$child['child_id']; ?>" class="btn-edit-profile">แก้ไขโปรไฟล์</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    
  </div>
</div>

<!-- Section: Sponsored children -->
<h2 class="section-title success donation-band">เด็กที่มีผู้อุปการะ</h2>

<div class="container py-4">
  <div class="row donation-grid row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-6 g-4">
    <?php foreach ($adopted as $child): ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <?php
        $rawStatus = $child['approve_profile'] ?? 'รอดำเนินการ';
        if ($rawStatus === 'กำลังดำเนินการ') $rawStatus = 'รอดำเนินการ';
        $canEdit = ($role === 'foundation' && $rawStatus === 'อนุมัติ');
      ?>
      <div class="child-card-wrap">
        <a href="children_donate.php?id=<?php echo $child['child_id']; ?>" class="child-card">
          <label class="delete-corner">
            <input type="checkbox" class="delete-check" name="child_ids[]" value="<?php echo (int)$child['child_id']; ?>" form="bulkDeleteForm" aria-label="เลือกลบโปรไฟล์นี้">
          </label>
          <div class="card-img success-bg">
            <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="รูปเด็ก">
          </div>
          <div class="card-info">
              <h3><?php echo htmlspecialchars($child['child_name']); ?></h3>
              <p class="meta-row"><span class="meta-icon age"><i class="bi bi-cake2-fill"></i></span> <?php echo $child['age']; ?> ปี</p>
              <p class="meta-row"><span class="meta-icon dream"><i class="bi bi-stars"></i></span> <?php echo htmlspecialchars($child['dream']); ?></p>
              <?php if ($role === 'foundation' || $role === 'admin'): ?>
                <div class="child-status-pill status-approved">อนุมัติแล้ว</div>
                <?php if ($role === 'foundation'): ?>
                  <div class="inline-delete-actions">
                    <button type="button" class="confirm-inline">ยืนยันลบ</button>
                    <button type="button" class="cancel-inline">ยกเลิก</button>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
          </div>
        </a>

        <?php if ($role === 'foundation'): ?>
          <div class="card-actions edit-mode">
            <?php if ($canEdit): ?>
              <a href="foundation_edit_child.php?id=<?php echo (int)$child['child_id']; ?>" class="btn-edit-profile">แก้ไขโปรไฟล์</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
</div>
</div>

<!-- Footer section -->
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

<script>
  (function initChildManageModes() {
    const checks = document.querySelectorAll('.delete-check');
    const toggleDeleteBtn = document.getElementById('toggleDeleteModeBtn');
    const toggleEditBtn   = document.getElementById('toggleEditModeBtn');
    const confirmInlineBtns = document.querySelectorAll('.confirm-inline');
    const cancelInlineBtns  = document.querySelectorAll('.cancel-inline');
    const bulkForm        = document.getElementById('bulkDeleteForm');
    const deleteReasonInput = document.getElementById('deleteReasonInput');
    const bulkActionInput   = document.getElementById('bulkActionInput');
    const reasonSheetOverlay = document.getElementById('reasonSheetOverlay');

    if (!checks.length || !toggleDeleteBtn || !toggleEditBtn || !bulkForm) return;

    // --- Sheet elements (foundation role only) ---
    const reasonSheetCount = reasonSheetOverlay ? document.getElementById('reasonSheetCount') : null;
    const confirmReasonBtn = reasonSheetOverlay ? document.getElementById('confirmReasonBtn') : null;
    const cancelReasonBtn  = reasonSheetOverlay ? document.getElementById('cancelReasonBtn') : null;
    const closeSheetBtn    = reasonSheetOverlay ? document.getElementById('closeReasonSheet') : null;
    const reasonOptions    = reasonSheetOverlay ? reasonSheetOverlay.querySelectorAll('.reason-option') : [];
    let selectedReasonOption = null;

    function openReasonSheet() {
      const selectedCount = Array.from(checks).filter(c => c.checked).length;
      if (!selectedCount) {
        alert('กรุณาเลือกโปรไฟล์ที่ต้องการดำเนินการก่อน');
        return;
      }
      if (!reasonSheetOverlay) return;
      reasonSheetCount.textContent = `เลือกแล้ว ${selectedCount} โปรไฟล์`;
      reasonOptions.forEach(o => o.classList.remove('selected'));
      selectedReasonOption = null;
      confirmReasonBtn.disabled = true;
      confirmReasonBtn.textContent = 'ยืนยัน';
      confirmReasonBtn.style.background = '#CE573F';
      reasonSheetOverlay.classList.add('open');
    }

    function closeSheet() {
      if (reasonSheetOverlay) reasonSheetOverlay.classList.remove('open');
    }

    function confirmSelectedReason() {
      if (!selectedReasonOption) return;
      deleteReasonInput.value = selectedReasonOption.dataset.reason;
      if (bulkActionInput) bulkActionInput.value = selectedReasonOption.dataset.action;
      closeSheet();
      bulkForm.submit();
    }

    if (reasonSheetOverlay) {
      reasonOptions.forEach(function(opt) {
        opt.addEventListener('click', function() {
          reasonOptions.forEach(o => o.classList.remove('selected'));
          this.classList.add('selected');
          selectedReasonOption = this;
          confirmReasonBtn.disabled = false;
          if (this.dataset.action === 'hide') {
            confirmReasonBtn.textContent = 'ปิดการมองเห็น';
            confirmReasonBtn.style.background = '#6b7280';
          } else {
            confirmReasonBtn.textContent = 'ยืนยันลบ';
            confirmReasonBtn.style.background = '#CE573F';
          }
        });
      });
      confirmReasonBtn.addEventListener('click', confirmSelectedReason);
      cancelReasonBtn.addEventListener('click', closeSheet);
      closeSheetBtn.addEventListener('click', closeSheet);
      reasonSheetOverlay.addEventListener('click', function(e) {
        if (e.target === reasonSheetOverlay) closeSheet();
      });
    }

    function clearChecks() {
      checks.forEach(c => { c.checked = false; });
      refreshDeleteState();
    }

    function enterDeleteMode() {
      document.body.classList.add('mode-delete');
      document.body.classList.remove('mode-edit');
      refreshDeleteState();
    }

    function exitDeleteMode() {
      document.body.classList.remove('mode-delete');
      toggleDeleteBtn.textContent = 'ลบโปรไฟล์';
      clearChecks();
    }

    function toggleEditMode() {
      const isEdit = document.body.classList.toggle('mode-edit');
      if (isEdit) {
        document.body.classList.remove('mode-delete');
        toggleDeleteBtn.textContent = 'ลบโปรไฟล์';
        clearChecks();
      }
    }

    function refreshDeleteState() {
      const count = Array.from(checks).filter(c => c.checked).length;
      if (document.body.classList.contains('mode-delete')) {
        toggleDeleteBtn.textContent = `ลบโปรไฟล์ (${count})`;
      } else {
        toggleDeleteBtn.textContent = 'ลบโปรไฟล์';
      }
    }

    toggleDeleteBtn.addEventListener('click', function() {
      if (!document.body.classList.contains('mode-delete')) {
        enterDeleteMode();
      } else {
        openReasonSheet();
      }
    });
    toggleEditBtn.addEventListener('click', toggleEditMode);
    cancelInlineBtns.forEach(btn => btn.addEventListener('click', exitDeleteMode));
    confirmInlineBtns.forEach(btn => btn.addEventListener('click', openReasonSheet));

    checks.forEach(c => {
      c.addEventListener('click', function(e) { e.stopPropagation(); });
      c.addEventListener('change', refreshDeleteState);
    });

    document.querySelectorAll('.child-card').forEach(card => {
      card.addEventListener('click', function(e) {
        if (document.body.classList.contains('mode-delete')) {
          e.preventDefault();
        }
      });
    });

    refreshDeleteState();
  })();
</script>
</body>
</html>
