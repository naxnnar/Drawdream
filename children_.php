<?php
// ไฟล์นี้: children_.php
// หน้าที่: หน้ารวมโปรไฟล์เด็กสำหรับผู้บริจาคและมูลนิธิ
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
  <link rel="stylesheet" href="css/children.css?v=2">

</head>
 
<body class="donation-page donation-role-<?php echo htmlspecialchars($role); ?>">

<?php include 'navbar.php'; ?>

<!-- Main content area -->
<div class="donation-shell">

<?php if ($role === 'foundation'): ?>
<div class="page-header">
  <h1>บริจาคให้เด็กรายบุคคล</h1>
  <p>ร่วมสนับสนุนเด็กที่ต้องการความช่วยเหลือ เลือกบริจาคโดยตรงให้กับเด็กแต่ละคนได้ที่นี่</p>
</div>
<?php endif; ?>

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

<?php if ($role !== 'foundation'): ?>
<div class="section-title danger donation-band">บริจาคให้เด็กรายบุคคล</div>
<?php endif; ?>

<div class="container py-4">
  <div class="row donation-grid row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-6 g-4">
    <?php foreach ($unadopted as $child): ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <?php
        // --- กำหนดสถานะโปรไฟล์เด็ก ---
        $rawStatus = $child['approve_profile'] ?? 'รอดำเนินการ';
        if ($rawStatus === 'กำลังดำเนินการ') $rawStatus = 'รอดำเนินการ';
        $statusClass = 'status-pending';
        $statusText = $rawStatus;
        if ($rawStatus === 'อนุมัติ') {
          $statusClass = 'status-approved';
          $statusText = 'อนุมัติแล้ว';
        } elseif ($rawStatus === 'ไม่อนุมัติ') {
          $statusClass = 'status-rejected';
          $statusText = 'ไม่อนุมัติ';
        }
      ?>
      <div class="child-card-wrap">
        <div class="child-card"
             data-view-url="children_donate.php?id=<?php echo (int)$child['child_id']; ?>"
             data-edit-url="foundation_edit_child.php?id=<?php echo (int)$child['child_id']; ?>">
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
              <?php if ($role === 'foundation'): ?>
                <div class="edit-pill-wrap">
                  <button type="button" class="btn-edit-pill" onclick="event.stopPropagation(); window.location.href='foundation_edit_child.php?id=<?php echo (int)$child['child_id']; ?>'">
                    แก้ไขโปรไฟล์
                  </button>
                </div>
              <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    
  </div>
</div>

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
    const isFoundationManageContext = !!(toggleDeleteBtn && toggleEditBtn && bulkForm);

    // ผูกการ์ดให้คลิกได้ทุก role (donor/admin/foundation)
    document.querySelectorAll('.child-card').forEach(card => {
      card.addEventListener('click', function(e) {
        const viewUrl = this.dataset.viewUrl;
        const editUrl = this.dataset.editUrl;

        if (document.body.classList.contains('mode-delete')) {
          e.preventDefault();
          return;
        }

        if (document.body.classList.contains('mode-edit')) {
          e.preventDefault();
          if (editUrl) window.location.href = editUrl;
          return;
        }

        if (viewUrl) {
          window.location.href = viewUrl;
        }
      });
    });

    // ถ้าไม่ใช่บริบทจัดการของมูลนิธิ ให้หยุดแค่ส่วนจัดการ แต่ยังคลิกการ์ดได้ตามปกติ
    if (!isFoundationManageContext) return;

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

    refreshDeleteState();
  })();
</script>
</body>
</html>
