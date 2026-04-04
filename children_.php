<?php
// children_.php — รายชื่อเด็ก (สาธารณะ / มุมมองมูลนิธิ)

// ------------------------------
// Session and database bootstrap
// ------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php'; // เชื่อมต่อฐานข้อมูล
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_outcome_ensure_columns($conn);
drawdream_child_omise_subscription_ensure_schema($conn);

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
$checkIsHidden = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'is_hidden'");
if ($checkIsHidden->num_rows === 0) {
  $conn->query("ALTER TABLE foundation_children ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
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
    if ($bulkAction === 'hide') {
      $hidden = 0;
      $blocked = 0;
      foreach ($childIds as $cid) {
        $st = $conn->prepare("SELECT child_id, approve_profile, first_approved_at, reviewed_at FROM foundation_children WHERE foundation_id = ? AND child_id = ? AND deleted_at IS NULL LIMIT 1");
        $st->bind_param("ii", $foundationId, $cid);
        $st->execute();
        $rowH = $st->get_result()->fetch_assoc();
        if (!$rowH) {
          continue;
        }
        $ap = (string)($rowH['approve_profile'] ?? '');
        $totalDon = drawdream_child_total_donations($conn, $cid);
        if ($ap === 'อนุมัติ' || $totalDon > 0 || drawdream_child_is_cycle_sponsored($conn, $cid, $rowH)) {
          $blocked++;
          continue;
        }
        $hid = $conn->prepare("UPDATE foundation_children SET is_hidden = 1 WHERE foundation_id = ? AND child_id = ?");
        $hid->bind_param("ii", $foundationId, $cid);
        $hid->execute();
        if ($hid->affected_rows > 0) {
          $hidden++;
        }
      }
      $msgParts = [];
      if ($hidden > 0) {
        $msgParts[] = "ซ่อนโปรไฟล์สำเร็จ {$hidden} รายการ (นำกลับมาแก้แล้วส่งแอดมินใหม่ได้)";
      }
      if ($blocked > 0) {
        $msgParts[] = "ไม่ดำเนินการ {$blocked} รายการ — โปรไฟล์ที่อนุมัติแล้ว มียอดบริจาค หรืออุปการะครบยอดในเดือนนี้ ไม่สามารถลบหรือซ่อนได้";
      }
      header('Location: children_.php?msg=' . urlencode($msgParts !== [] ? implode(' · ', $msgParts) : 'ดำเนินการแล้ว'));
    } else {
      $deleted = 0;
      $blocked = 0;
      $failedDelete = 0;
      foreach ($childIds as $cid) {
        $st = $conn->prepare("SELECT child_id, approve_profile, first_approved_at, reviewed_at, photo_child, qr_account_image FROM foundation_children WHERE foundation_id = ? AND child_id = ? AND deleted_at IS NULL LIMIT 1");
        $st->bind_param("ii", $foundationId, $cid);
        $st->execute();
        $crow = $st->get_result()->fetch_assoc();
        if (!$crow) {
          continue;
        }
        $ap = (string)($crow['approve_profile'] ?? '');
        $totalDon = drawdream_child_total_donations($conn, $cid);
        $cycleSponsored = drawdream_child_is_cycle_sponsored($conn, $cid, $crow);
        // Soft delete — โปรไฟล์ไม่อนุมัติ: อนุญาตลบเมื่อไม่มียอดบริจาค (ไม่ผูกกับ cycle เพราะไม่เปิดรับบริจาค)
        $maySoftDelete = ($ap === 'ไม่อนุมัติ' && $totalDon <= 0)
          || (
            !$cycleSponsored
            && ($totalDon <= 0)
            && ($ap !== 'อนุมัติ')
            && (
              in_array($ap, ['รอดำเนินการ', 'ไม่อนุมัติ'], true)
              || ($ap === 'กำลังดำเนินการ')
            )
          );
        if ($maySoftDelete) {
          $reasonText = $deleteReason === '' ? null : $deleteReason;
          $upd = $conn->prepare('UPDATE foundation_children SET deleted_at = NOW(), profile_delete_reason = ? WHERE foundation_id = ? AND child_id = ? AND deleted_at IS NULL');
          $upd->bind_param('sii', $reasonText, $foundationId, $cid);
          if ($upd->execute() && $upd->affected_rows >= 1) {
            $deleted++;
          } else {
            $failedDelete++;
          }
        } elseif ($ap === 'อนุมัติ' || $totalDon > 0 || $cycleSponsored) {
          $blocked++;
        } else {
          $blocked++;
        }
      }
      $msgParts = [];
      if ($deleted > 0) {
        $msgParts[] = "ลบ {$deleted} รายการ — ข้อมูลยังเก็บในฐานข้อมูล (ทำเครื่องหมายว่าลบแล้ว)";
      }
      if ($blocked > 0) {
        $msgParts[] = "ไม่ดำเนินการ {$blocked} รายการ — อนุมัติแล้ว มียอดบริจาค หรืออุปการะครบยอดในเดือนนี้ (ไม่ให้ลบ)";
      }
      if ($failedDelete > 0) {
        $msgParts[] = "ลบไม่สำเร็จ {$failedDelete} รายการ กรุณาลองใหม่";
      }
      header('Location: children_.php?msg=' . urlencode($msgParts !== [] ? implode(' · ', $msgParts) : 'ดำเนินการแล้ว'));
    }
    exit();
  }

  header('Location: children_.php?msg=' . urlencode('กรุณาเลือกโปรไฟล์ที่ต้องการดำเนินการ'));
  exit();
}

/** ป้ายสถานะโปรไฟล์เด็ก (การ์ดมูลนิธิ/ผู้บริจาค + ตารางแอดมิน) */
function children_row_profile_status_meta(array $child): array
{
    $rawAp = $child['approve_profile'] ?? 'รอดำเนินการ';
    if (!empty($child['pending_edit_json']) && $rawAp === 'กำลังดำเนินการ') {
        return ['text' => 'รอตรวจสอบการแก้ไข', 'class' => 'status-pending', 'raw' => 'รอดำเนินการ'];
    }
    $rawStatus = $rawAp;
    if ($rawStatus === 'กำลังดำเนินการ') {
        $rawStatus = 'รอดำเนินการ';
    }
    $statusClass = 'status-pending';
    $statusText = $rawStatus;
    if ($rawStatus === 'อนุมัติ') {
        $statusClass = 'status-approved';
        $statusText = 'อนุมัติแล้ว';
    } elseif ($rawStatus === 'ไม่อนุมัติ') {
        $statusClass = 'status-rejected';
        $statusText = 'ไม่อนุมัติ';
    }
    return ['text' => $statusText, 'class' => $statusClass, 'raw' => $rawStatus];
}

// ------------------------------
// Build listing query by role
// ------------------------------
if ($role === 'donor') {
  $sql = "SELECT * FROM foundation_children WHERE approve_profile IN ('อนุมัติ', 'กำลังดำเนินการ') AND is_hidden = 0 AND deleted_at IS NULL ORDER BY child_id DESC";
  $result = $conn->query($sql);
} elseif ($role === 'foundation') {
  if ($foundationId !== null) {
    $stmt = $conn->prepare("SELECT * FROM foundation_children WHERE foundation_id = ? AND deleted_at IS NULL ORDER BY child_id DESC");
    $stmt->bind_param("i", $foundationId);
    $stmt->execute();
    $result = $stmt->get_result();
  } else {
    $result = false;
  }
} elseif ($role === 'admin') {
  $sql = "
    SELECT c.*, f.foundation_name AS fp_name
    FROM foundation_children c
    LEFT JOIN foundation_profile f ON c.foundation_id = f.foundation_id
    WHERE c.deleted_at IS NULL
    ORDER BY c.child_id DESC
  ";
  $result = $conn->query($sql);
} else {
  $sql = "SELECT * FROM foundation_children WHERE deleted_at IS NULL ORDER BY child_id DESC";
  $result = $conn->query($sql);
}

// แยกกลุ่ม: รออุปการะ vs มีผู้อุปการะ (ยอดรอบเดือน >= 20,000 หรือมี Omise subscription active)
$waiting_children = [];
$sponsored_children = [];
$all_list_rows = [];
if ($result && $result->num_rows > 0) {
  while ($row = $result->fetch_assoc()) {
    $all_list_rows[] = $row;
  }
}
$cycleTotals = drawdream_child_cycle_totals_batch($conn, $all_list_rows);
$childIdsForTotals = array_map(static fn ($r) => (int)($r['child_id'] ?? 0), $all_list_rows);
$childDonationTotals = ($role === 'foundation' && $childIdsForTotals !== [])
    ? drawdream_child_donation_totals_batch($conn, $childIdsForTotals)
    : [];
$planSponsoredMap = drawdream_child_ids_with_active_plan_sponsorship($conn, $childIdsForTotals);

foreach ($all_list_rows as $row) {
  $cid = (int)($row['child_id'] ?? 0);
  $cycleAmt = (float)($cycleTotals[$cid] ?? 0);
  if (drawdream_child_is_showcase_sponsored($conn, $cid, $row, $cycleAmt, $planSponsoredMap)) {
    $sponsored_children[] = $row;
  } else {
    $waiting_children[] = $row;
  }
}

$child_grid_sections = [
  ['children' => $waiting_children, 'bar' => 'เด็กที่ยังไม่ได้อุปการะ', 'sponsored' => false],
  ['children' => $sponsored_children, 'bar' => 'เด็กที่มีผู้อุปการะ', 'sponsored' => true],
];
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
  <link rel="stylesheet" href="css/children.css?v=19">
  <?php if ($role === 'admin'): ?>
  <link rel="stylesheet" href="css/admin_directory.css">
  <?php endif; ?>

</head>
 
<body class="donation-page donation-role-<?php echo htmlspecialchars($role); ?>">

<?php include 'navbar.php'; ?>

<!-- Main content area -->
<div class="donation-shell">

<?php if ($role === 'foundation'): ?>
<div class="page-header">
  <h1>บริจาครายบุคคล</h1>
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
        <?php if (!empty($_GET['msg'])): ?>
          <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
          <script>
          Swal.fire({
            icon: 'success',
            title: <?php echo json_encode($_GET['msg']); ?>,
            showConfirmButton: false,
            timer: 1600
          });
          </script>
        <?php endif; ?>
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

<?php if ($role === 'admin'): ?>
<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">เด็กทั้งหมด</h1>
        <p class="admin-directory-desc">
            รายชื่อเด็กจากมูลนิธิ: สถานะโปรไฟล์ การอุปการะจริงในระบบ และลิงก์ไปหน้าบริจาคสาธารณะ
            (ยอดรอบเดือนครบเกณฑ์หรือมีอุปการะรายงวดที่ active)
        </p>
    </div>
    <div class="admin-directory-actions-hint">
        <strong>แอดมินทำอะไรได้จากหน้านี้:</strong>
        ตรวจสถานะอนุมัติโปรไฟล์ · เปิดเพจบริจาคเพื่อดูภาพและข้อความที่ donor เห็น ·
        <a href="admin_approve_children.php">อนุมัติโปรไฟล์เด็ก</a> เมื่อมีคิวรอตรวจ
    </div>
    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>รูป</th>
                <th>ชื่อเด็ก</th>
                <th>มูลนิธิ</th>
                <th>สถานะโปรไฟล์</th>
                <th>สถานะอุปการะ</th>
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($all_list_rows === []): ?>
                <tr><td colspan="6" class="b--muted">ยังไม่มีโปรไฟล์เด็ก</td></tr>
            <?php else: ?>
                <?php foreach ($all_list_rows as $r):
                    $cid = (int)($r['child_id'] ?? 0);
                    $photo = trim((string)($r['photo_child'] ?? ''));
                    $fn = trim((string)($r['foundation_name'] ?? ''));
                    if ($fn === '') {
                        $fn = trim((string)($r['fp_name'] ?? ''));
                    }
                    if ($fn === '') {
                        $fn = '—';
                    }
                    $profMeta = children_row_profile_status_meta($r);
                    $cycleAmtRow = (float)($cycleTotals[$cid] ?? 0);
                    $sponsoredRow = drawdream_child_is_showcase_sponsored($conn, $cid, $r, $cycleAmtRow, $planSponsoredMap);
                    $sponsorLabel = $sponsoredRow ? 'มีผู้อุปการะ' : 'รออุปการะ';
                    $imgSrc = $photo !== '' ? 'uploads/Children/' . rawurlencode($photo) : '';
                    ?>
                    <tr>
                        <td>
                            <?php if ($imgSrc !== ''): ?>
                                <img class="admin-dir-thumb" src="<?php echo htmlspecialchars($imgSrc); ?>" alt="">
                            <?php else: ?>
                                <span class="b--muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($r['child_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($fn); ?></td>
                        <td><?php echo htmlspecialchars($profMeta['text']); ?></td>
                        <td><?php echo htmlspecialchars($sponsorLabel); ?></td>
                        <td>
                            <div class="admin-dir-actions">
                                <a class="admin-dir-btn admin-dir-btn--primary"
                                   href="children_donate.php?id=<?php echo $cid; ?>">หน้าบริจาค</a>
                                <a class="admin-dir-btn admin-dir-btn--ghost"
                                   href="admin_approve_children.php">คิวอนุมัติ</a>
                                <a class="admin-dir-btn admin-dir-btn--ghost" href="admin_dashboard.php">Dashboard</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<?php foreach ($child_grid_sections as $gridSection): ?>
  <?php if ($gridSection['children'] === []) { continue; } ?>
  <?php if (!empty($gridSection['bar'])): ?>
  <div class="child-section-wrap">
    <div class="child-section-bar<?php echo !empty($gridSection['sponsored']) ? ' child-section-bar--sponsored' : ' child-section-bar--waiting'; ?>"><?php echo htmlspecialchars($gridSection['bar']); ?></div>
  <?php endif; ?>
<div class="container py-4">
  <div class="row donation-grid row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-6 g-4">
    <?php foreach ($gridSection['children'] as $child): ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <?php
        $profMeta = children_row_profile_status_meta($child);
        $statusClass = $profMeta['class'];
        $statusText = $profMeta['text'];
        $rawStatus = $profMeta['raw'];
        $cidCard = (int)$child['child_id'];
        $totalDonCard = ($role === 'foundation') ? (float)($childDonationTotals[$cidCard] ?? 0) : 0.0;
        $sponsoredLocked = ($role === 'foundation' && !empty($gridSection['sponsored']));
        $blockBulkCheckbox = ($role === 'foundation')
          && (($child['approve_profile'] ?? '') !== 'ไม่อนุมัติ')
          && (
            (($child['approve_profile'] ?? '') === 'อนุมัติ')
            || ($totalDonCard > 0)
            || $sponsoredLocked
          );
      ?>
      <div class="child-card-wrap<?php echo $blockBulkCheckbox ? ' child-card-wrap--bulk-protected' : ''; ?><?php echo $sponsoredLocked ? ' child-card-wrap--sponsored-lock' : ''; ?>">
        <div class="child-card<?php echo !empty($gridSection['sponsored']) ? ' child-card--sponsored' : ''; ?>"
             data-view-url="children_donate.php?id=<?php echo (int)$child['child_id']; ?>"
             data-edit-url="foundation_edit_child.php?id=<?php echo (int)$child['child_id']; ?>"
             data-sponsored-locked="<?php echo $sponsoredLocked ? '1' : '0'; ?>"
             data-cycle-total="<?php echo htmlspecialchars((string)(float)($cycleTotals[(int)$child['child_id']] ?? 0)); ?>">
          <label class="delete-corner">
            <input type="checkbox" class="delete-check" name="child_ids[]" value="<?php echo (int)$child['child_id']; ?>" form="bulkDeleteForm" aria-label="เลือกลบหรือซ่อนโปรไฟล์นี้"<?php
              if ($blockBulkCheckbox) {
                echo ' disabled title="โปรไฟล์ที่อนุมัติแล้ว มียอดบริจาคสะสม หรืออุปการะครบยอดในเดือนนี้ ไม่สามารถลบถาวรหรือซ่อนได้"';
              }
            ?>>
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
                <?php if ($role === 'foundation' && !$sponsoredLocked): ?>
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
                  <?php
                    $apForEdit = $child['approve_profile'] ?? '';
                    $editWarn = ($apForEdit === 'อนุมัติ' || $apForEdit === 'กำลังดำเนินการ');
                    $eid = (int)$child['child_id'];
                  ?>
                  <?php if ($sponsoredLocked): ?>
                  <button type="button" class="btn-edit-pill" disabled title="เด็กที่ได้รับการอุปการะครบยอดในเดือนนี้ ไม่สามารถแก้ไขโปรไฟล์ได้">แก้ไขโปรไฟล์</button>
                  <?php else: ?>
                  <button type="button" class="btn-edit-pill" <?php
                    if ($editWarn) {
                      echo 'onclick="event.stopPropagation(); if(confirm(\'การแก้ไขหลังอนุมัติจะส่งให้แอดมินตรวจสอบอีกครั้งก่อนเผยแพร่ข้อมูลใหม่ ต้องการดำเนินการต่อหรือไม่\')) { window.location.href=\'foundation_edit_child.php?id=' . $eid . '\'; }"';
                    } else {
                      echo 'onclick="event.stopPropagation(); window.location.href=\'foundation_edit_child.php?id=' . $eid . '\';"';
                    }
                  ?>>
                    แก้ไขโปรไฟล์
                  </button>
                  <?php endif; ?>
                  <?php if ($sponsoredLocked): ?>
                  <div class="foundation-outcome-pill-wrap">
                    <a href="foundation_child_outcome.php?id=<?php echo (int)$child['child_id']; ?>" class="btn-outcome-pill" onclick="event.stopPropagation();">อัปเดตผลลัพธ์</a>
                  </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
  <?php if (!empty($gridSection['bar'])): ?>
  </div><!-- .child-section-wrap -->
  <?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

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
          if (this.dataset.sponsoredLocked === '1') {
            return;
          }
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
      const selectedCount = Array.from(checks).filter(c => c.checked && !c.disabled).length;
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
      confirmReasonBtn.style.background = '#CC583F';
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
            confirmReasonBtn.style.background = '#CC583F';
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
      const count = Array.from(checks).filter(c => c.checked && !c.disabled).length;
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
