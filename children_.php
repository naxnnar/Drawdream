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
require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_outcome_ensure_columns($conn);
drawdream_child_omise_subscription_ensure_schema($conn);

// ------------------------------
// Current user context
// ------------------------------
$role = $_SESSION['role'] ?? 'donor';
$foundationAccountVerified = drawdream_foundation_account_is_verified($conn);
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

if ($role === 'foundation' && isset($_POST['bulk_action'])) {
  if (!$foundationAccountVerified) {
    header('Location: children_.php?msg_icon=warning&msg=' . rawurlencode('บัญชีมูลนิธิยังรอการตรวจสอบจากผู้ดูแลระบบ จึงยังใช้งานฟีเจอร์นี้ไม่ได้'));
    exit();
  }
  $selectedIds = $_POST['child_ids'] ?? [];
  $childIds = [];
  foreach ($selectedIds as $id) {
    $n = (int)$id;
    if ($n > 0) $childIds[] = $n;
  }

  if (!empty($childIds) && $foundationId !== null) {
      $deleted = 0;
      $blocked = 0;
      $failedDelete = 0;
      foreach ($childIds as $cid) {
        $st = $conn->prepare("SELECT * FROM foundation_children WHERE foundation_id = ? AND child_id = ? AND deleted_at IS NULL LIMIT 1");
        $st->bind_param("ii", $foundationId, $cid);
        $st->execute();
        $crow = $st->get_result()->fetch_assoc();
        if (!$crow) {
          continue;
        }
        $totalDon = drawdream_child_total_donations($conn, $cid);
        $cycleSponsored = drawdream_child_is_cycle_sponsored($conn, $cid, $crow);
        $hasActiveSubscription = drawdream_child_has_any_active_subscription($conn, $cid);
        // ลบได้เฉพาะ "ยังไม่มีผู้อุปการะ" และ "ไม่เคยได้รับเงินบริจาคเลย (ยอดสะสม = 0)"
        $maySoftDelete = ($totalDon <= 0) && !$cycleSponsored && !$hasActiveSubscription;
        if ($maySoftDelete) {
          $upd = $conn->prepare('UPDATE foundation_children SET deleted_at = NOW(), delete_reason = NULL WHERE foundation_id = ? AND child_id = ? AND deleted_at IS NULL');
          $upd->bind_param('ii', $foundationId, $cid);
          if ($upd->execute() && $upd->affected_rows >= 1) {
            $deleted++;
          } else {
            $failedDelete++;
          }
        } else {
          $blocked++;
        }
      }
      $finalMsg = 'ดำเนินการแล้ว';
      if ($deleted > 0) {
        $finalMsg = 'ลบโปรไฟล์เด็กเรียบร้อยแล้ว';
      } elseif ($blocked > 0 || $failedDelete > 0) {
        $finalMsg = 'ไม่สามารถลบโปรไฟล์ที่เลือกได้ — ลบได้เฉพาะเด็กที่ยังไม่มีผู้อุปการะ และยอดสะสมเท่ากับ 0 บาท';
      }
      header('Location: children_.php?msg=' . urlencode($finalMsg));
    exit();
  }

  header('Location: children_.php?msg=' . urlencode('กรุณาเลือกโปรไฟล์ที่ต้องการดำเนินการ'));
  exit();
}

/** ป้ายสถานะโปรไฟล์เด็ก (การ์ดมูลนิธิ/ผู้บริจาค + ตารางแอดมิน) — admin_pill ใช้กับตารางแอดมิน (css/admin_directory.css) */
function children_row_profile_status_meta(array $child): array
{
    $rawAp = $child['approve_profile'] ?? 'รอดำเนินการ';
    if (!empty($child['pending_edit_json']) && $rawAp === 'กำลังดำเนินการ') {
        return [
            'text' => 'รอตรวจสอบการแก้ไข',
            'class' => 'status-pending',
            'raw' => 'รอดำเนินการ',
            'admin_pill' => 'admin-pill admin-pill--warning',
        ];
    }
    $rawStatus = $rawAp;
    if ($rawStatus === 'กำลังดำเนินการ') {
        $rawStatus = 'รอดำเนินการ';
    }
    $statusClass = 'status-pending';
    $statusText = $rawStatus;
    $adminPill = 'admin-pill admin-pill--warning';
    if ($rawStatus === 'อนุมัติ') {
        $statusClass = 'status-approved';
        $statusText = 'อนุมัติแล้ว';
        $adminPill = 'admin-pill admin-pill--success';
    } elseif ($rawStatus === 'ไม่อนุมัติ') {
        $statusClass = 'status-rejected';
        $statusText = 'ไม่อนุมัติ';
        $adminPill = 'admin-pill admin-pill--danger';
    }
    return ['text' => $statusText, 'class' => $statusClass, 'raw' => $rawStatus, 'admin_pill' => $adminPill];
}

// ------------------------------
// Build listing query by role
// ------------------------------
if ($role === 'donor') {
  $sql = "SELECT * FROM foundation_children WHERE approve_profile IN ('อนุมัติ', 'กำลังดำเนินการ') AND deleted_at IS NULL ORDER BY child_id DESC";
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

// แยกกลุ่ม: รออุปการะ vs มีผู้อุปการะ (เฉพาะ Omise subscription active — รายเดือน / 6 เดือน / รายปี)
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

// แจ้งเตือนฝั่งมูลนิธิ: เด็กมีผู้อุปการะแล้ว แต่ยังไม่อัปเดตผลลัพธ์ให้ผู้บริจาคเห็น
$foundationOutcomePending = [];
if ($role === 'foundation') {
  foreach ($sponsored_children as $srow) {
    $outcomeText = trim((string)($srow['update_text'] ?? ''));
    $imgsRaw = (string)($srow['update_images'] ?? '');
    $hasImage = false;
    if ($imgsRaw !== '') {
      $arr = json_decode($imgsRaw, true);
      if (is_array($arr)) {
        foreach ($arr as $imgName) {
          if (trim((string)$imgName) !== '') {
            $hasImage = true;
            break;
          }
        }
      }
    }
    if ($outcomeText === '' && !$hasImage) {
      $foundationOutcomePending[] = $srow;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <title>บริจาคให้เด็กรายบุคคล | DrawDream</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/navbar.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <!-- link css -->
  <link rel="stylesheet" href="css/children.css?v=34">
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
    <?php if ($foundationAccountVerified): ?>
    <div class="foundation-action-buttons">
      <a href="foundation_add_children.php" class="btn-create-profile">+ สร้างโปรไฟล์เด็ก</a>
      <button type="button" id="toggleEditModeBtn" class="btn-toggle-edit">แก้ไขโปรไฟล์</button>
      <button type="button" id="toggleDeleteModeBtn" class="btn-delete-selected">ลบโปรไฟล์</button>
      <?php if ($sponsored_children !== []): ?>
        <button type="button" id="toggleOutcomeModeBtn" class="btn-update-outcome">อัปเดตผลลัพธ์</button>
      <?php else: ?>
        <span class="btn-update-outcome btn-update-outcome--disabled" aria-disabled="true">อัปเดตผลลัพธ์</span>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <p class="foundation-pending-inline-msg text-muted mb-0">บัญชีมูลนิธิยังรอการตรวจสอบจากผู้ดูแลระบบ — หลังอนุมัติแล้วจึงจะสร้างหรือจัดการโปรไฟล์เด็กได้</p>
    <?php endif; ?>
  <?php else: ?>
    <div></div>
  <?php endif; ?>
  <div>
    <?php if (!empty($_GET['msg'] ?? '')): ?>
        <?php if (!empty($_GET['msg'])): ?>
          <?php $swalIcon = (isset($_GET['msg_icon']) && $_GET['msg_icon'] === 'warning') ? 'warning' : 'success'; ?>
          <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
          <script>
          Swal.fire({
            icon: <?php echo json_encode($swalIcon); ?>,
            title: <?php echo json_encode($_GET['msg']); ?>,
            showConfirmButton: false,
            timer: 1600
          });
          </script>
        <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($role === 'foundation' && $foundationAccountVerified): ?>
<form method="POST" id="bulkDeleteForm">
  <input type="hidden" name="bulk_action" id="bulkActionInput" value="delete">
</form>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">เด็กทั้งหมด</h1>
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
                    $imgSrc = $photo !== '' ? 'uploads/childern/' . rawurlencode($photo) : '';
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
                        <td>
                            <span class="<?php echo htmlspecialchars($profMeta['admin_pill'] ?? 'admin-pill admin-pill--neutral'); ?>">
                                <?php echo htmlspecialchars($profMeta['text']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="admin-pill <?php echo $sponsoredRow ? 'admin-pill--success' : 'admin-pill--danger'; ?>">
                                <?php echo htmlspecialchars($sponsorLabel); ?>
                            </span>
                        </td>
                        <td>
                            <div class="admin-dir-actions">
                                <a class="admin-dir-btn admin-dir-btn--primary"
                                   href="admin_view_child.php?id=<?php echo $cid; ?>">โปรไฟล์เด็ก</a>
                                <a class="admin-dir-btn admin-dir-btn--ghost"
                                   href="admin_child_donations.php?child_id=<?php echo $cid; ?>">ยอดบริจาค</a>
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
  <div class="child-section-wrap"<?php echo !empty($gridSection['sponsored']) ? ' id="sponsored-section"' : ''; ?>>
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
        $outcomeTextCard = trim((string)($child['update_text'] ?? ''));
        $outcomeImgsRawCard = (string)($child['update_images'] ?? '');
        $outcomeHasImageCard = false;
        if ($outcomeImgsRawCard !== '') {
          $outcomeImgsArrCard = json_decode($outcomeImgsRawCard, true);
          if (is_array($outcomeImgsArrCard)) {
            foreach ($outcomeImgsArrCard as $imgCardName) {
              if (trim((string)$imgCardName) !== '') {
                $outcomeHasImageCard = true;
                break;
              }
            }
          }
        }
        $outcomeDoneCard = ($outcomeTextCard !== '' || $outcomeHasImageCard);
        $hasActiveSubscriptionCard = !empty($planSponsoredMap[$cidCard]);
        $maySoftDeleteCard = ($totalDonCard <= 0) && !$hasActiveSubscriptionCard;
        $blockBulkCheckbox = ($role === 'foundation')
          && (!$foundationAccountVerified || !$maySoftDeleteCard);
      ?>
      <div class="child-card-wrap<?php echo $blockBulkCheckbox ? ' child-card-wrap--bulk-protected' : ''; ?><?php echo $sponsoredLocked ? ' child-card-wrap--sponsored-lock' : ''; ?>">
        <div class="child-card<?php echo !empty($gridSection['sponsored']) ? ' child-card--sponsored' : ''; ?>"
             data-view-url="admin_view_child.php?id=<?php echo (int)$child['child_id']; ?>"
             data-edit-url="<?php echo $foundationAccountVerified ? 'foundation_edit_child.php?id=' . (int)$child['child_id'] : ''; ?>"
             data-sponsored-locked="<?php echo $sponsoredLocked ? '1' : '0'; ?>"
             data-cycle-total="<?php echo htmlspecialchars((string)(float)($cycleTotals[(int)$child['child_id']] ?? 0)); ?>">
          <?php if ($role === 'foundation' && $foundationAccountVerified): ?>
          <label class="delete-corner">
            <input type="checkbox" class="delete-check" name="child_ids[]" value="<?php echo (int)$child['child_id']; ?>" form="bulkDeleteForm" aria-label="เลือกโปรไฟล์เพื่อลบ"<?php
              if ($blockBulkCheckbox) {
                echo ' disabled title="ลบได้เฉพาะเด็กที่ยังไม่มีผู้อุปการะ และยอดสะสมเท่ากับ 0 บาท"';
              }
            ?>>
          </label>
          <?php endif; ?>
          <div class="card-img danger-bg">
            <img src="uploads/childern/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="รูปเด็ก">
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
                <?php if ($role === 'foundation' && $foundationAccountVerified && !$sponsoredLocked): ?>
                  <div class="inline-delete-actions">
                    <button type="button" class="confirm-inline">ยืนยันลบ</button>
                    <button type="button" class="cancel-inline">ยกเลิก</button>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($role === 'foundation' && $rawStatus === 'ไม่อนุมัติ' && !empty($child['reject_reason'] ?? '')): ?>
                <p class="reject-reason">เหตุผลไม่อนุมัติ: <?php echo htmlspecialchars($child['reject_reason']); ?></p>
              <?php endif; ?>
              <?php if ($role === 'foundation' && $foundationAccountVerified): ?>
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
                </div>
                <?php if ($sponsoredLocked): ?>
                <div class="child-card-outcome-actions">
                  <a href="foundation_child_outcome.php?id=<?php echo (int)$child['child_id']; ?>" class="btn-card-outcome<?php echo $outcomeDoneCard ? ' is-done' : ''; ?>" onclick="event.stopPropagation();">
                    <?php echo $outcomeDoneCard ? 'อัปเดตแล้ว' : 'อัปเดตผลลัพธ์'; ?>
                  </a>
                </div>
                <?php endif; ?>
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
    const toggleOutcomeBtn = document.getElementById('toggleOutcomeModeBtn');
    const confirmInlineBtns = document.querySelectorAll('.confirm-inline');
    const cancelInlineBtns  = document.querySelectorAll('.cancel-inline');
    const bulkForm        = document.getElementById('bulkDeleteForm');
    const bulkActionInput   = document.getElementById('bulkActionInput');
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

    function submitBulkDelete() {
      const selectedCount = Array.from(checks).filter(c => c.checked && !c.disabled).length;
      if (!selectedCount) {
        alert('กรุณาเลือกโปรไฟล์ที่ต้องการดำเนินการก่อน');
        return;
      }
      if (!confirm('ยืนยันลบโปรไฟล์ที่เลือก ' + selectedCount + ' รายการ?')) {
        return;
      }
      if (bulkActionInput) bulkActionInput.value = 'delete';
      bulkForm.submit();
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
        document.body.classList.remove('mode-outcome');
        toggleDeleteBtn.textContent = 'ลบโปรไฟล์';
        clearChecks();
      }
    }

    function toggleOutcomeMode() {
      const isOutcome = document.body.classList.toggle('mode-outcome');
      if (isOutcome) {
        document.body.classList.remove('mode-delete');
        document.body.classList.remove('mode-edit');
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
        submitBulkDelete();
      }
    });
    toggleEditBtn.addEventListener('click', toggleEditMode);
    if (toggleOutcomeBtn) {
      toggleOutcomeBtn.addEventListener('click', toggleOutcomeMode);
    }
    cancelInlineBtns.forEach(btn => btn.addEventListener('click', exitDeleteMode));
    confirmInlineBtns.forEach(btn => btn.addEventListener('click', submitBulkDelete));

    checks.forEach(c => {
      c.addEventListener('click', function(e) { e.stopPropagation(); });
      c.addEventListener('change', refreshDeleteState);
    });

    refreshDeleteState();
  })();
</script>
</body>
</html>
