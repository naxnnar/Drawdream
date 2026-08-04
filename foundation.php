<?php
// foundation.php — หน้ามูลนิธิ + รายการสิ่งของ (สาธารณะ/จัดการ)

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน foundation

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/needlist_donate_window.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/includes/utf8_helpers.php';
require_once __DIR__ . '/includes/foundation_account_verified.php';
require_once __DIR__ . '/includes/foundation_public_page_cache.php';

/**
 * หา path รูปในโฟลเดอร์ img/ เมื่อชื่อไฟล์ไม่มีนามสกุล (ลอง .jpg .jpeg .png .webp)
 */
function drawdream_community_img(string $baseName): string {
    static $cache = [];
    if (isset($cache[$baseName])) {
        return $cache[$baseName];
    }
    $imgDir = __DIR__ . '/img';
    foreach (['.jpg', '.jpeg', '.png', '.webp'] as $ext) {
        if (is_file($imgDir . '/' . $baseName . $ext)) {
            return $cache[$baseName] = 'img/' . $baseName . $ext;
        }
    }
    return $cache[$baseName] = 'img/' . $baseName . '.jpg';
}

$is_verified = drawdream_foundation_account_is_verified($conn);
$foundationPageMsg = trim((string)($_GET['msg'] ?? ''));

$roleViewer = $_SESSION['role'] ?? '';
$isFoundationManageView = ($roleViewer === 'foundation');
$userId = (int)($_SESSION['user_id'] ?? 0);

$foundationRows = [];
$donationTotals = [];
$goalTotals = [];
$donationTotalsSlideTrack = [];
$goalTotalsSlideTrack = [];
$needOutcomeFoundations = [];
$needDoneFoundations = [];
$needPurchasingFoundations = [];
$needlistByFoundation = [];
$foundationPublicFromCache = false;

if (!$isFoundationManageView) {
    $cachedFoundationPage = drawdream_foundation_public_page_cache_get();
    if ($cachedFoundationPage !== null) {
        $foundationRows = $cachedFoundationPage['foundationRows'];
        $donationTotals = $cachedFoundationPage['donationTotals'];
        $goalTotals = $cachedFoundationPage['goalTotals'];
        $donationTotalsSlideTrack = $cachedFoundationPage['donationTotalsSlideTrack'];
        $goalTotalsSlideTrack = $cachedFoundationPage['goalTotalsSlideTrack'];
        $needOutcomeFoundations = $cachedFoundationPage['needOutcomeFoundations'];
        $needDoneFoundations = $cachedFoundationPage['needDoneFoundations'];
        $needPurchasingFoundations = $cachedFoundationPage['needPurchasingFoundations'];
        $needlistByFoundation = $cachedFoundationPage['needlistByFoundation'];
        $foundationPublicFromCache = true;
    }
}

if (!$foundationPublicFromCache) {
// ถ้าเป็นมูลนิธิ: ดูของตัวเองได้แม้ยังไม่อนุมัติ
// ถ้าเป็นผู้ใช้ทั่วไป/ผู้บริจาค: แสดงเฉพาะมูลนิธิที่อนุมัติแล้วเท่านั้น
if ($isFoundationManageView) {
    $stOwn = $conn->prepare('SELECT * FROM foundation_profile WHERE user_id = ? ORDER BY foundation_id DESC');
    if ($stOwn) {
        $stOwn->bind_param('i', $userId);
        $stOwn->execute();
        $foundations = $stOwn->get_result();
    } else {
        $foundations = false;
    }
} else {
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile WHERE account_verified = 1 ORDER BY foundation_id DESC");
}
if (!$foundations) {
    error_log('foundation.php foundations query: ' . mysqli_error($conn));
    $foundationRows = [];
} else {
    while ($row = $foundations->fetch_assoc()) {
        $foundationRows[] = $row;
    }
}

$needOpenPub = drawdream_needlist_sql_open_for_donation();
$donationTotals = [];
$goalTotals = [];
$donationTotalsSlideTrack = [];
$goalTotalsSlideTrack = [];
$needOutcomeFoundations = [];
$needDoneFoundations = [];
$needPurchasingFoundations = [];

if (!$isFoundationManageView) {
    $q = mysqli_query($conn, "
        SELECT foundation_id, COALESCE(SUM(current_donate), 0) AS total
        FROM foundation_needlist
        WHERE $needOpenPub
        GROUP BY foundation_id
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $donationTotals[(int)$r['foundation_id']] = (float)$r['total'];

    $q2 = mysqli_query($conn, "
        SELECT
            foundation_id,
            COALESCE(SUM(COALESCE(total_price, 0)), 0) AS goal
        FROM foundation_needlist
        WHERE $needOpenPub
        GROUP BY foundation_id
    ");
    if ($q2) while ($r = mysqli_fetch_assoc($q2)) $goalTotals[(int)$r['foundation_id']] = (float)$r['goal'];

    $qTrack = mysqli_query($conn, "
        SELECT foundation_id, COALESCE(SUM(current_donate), 0) AS total
        FROM foundation_needlist
        WHERE approve_item IN ('approved', 'purchasing', 'done')
        GROUP BY foundation_id
    ");
    if ($qTrack) {
        while ($r = mysqli_fetch_assoc($qTrack)) {
            $donationTotalsSlideTrack[(int)$r['foundation_id']] = (float)$r['total'];
        }
    }
    $qTrackG = mysqli_query($conn, "
        SELECT foundation_id, COALESCE(SUM(COALESCE(total_price, 0)), 0) AS goal
        FROM foundation_needlist
        WHERE approve_item IN ('approved', 'purchasing', 'done')
        GROUP BY foundation_id
    ");
    if ($qTrackG) {
        while ($r = mysqli_fetch_assoc($qTrackG)) {
            $goalTotalsSlideTrack[(int)$r['foundation_id']] = (float)$r['goal'];
        }
    }

    $qOutcome = mysqli_query($conn, "
        SELECT DISTINCT foundation_id
        FROM foundation_needlist
        WHERE approve_item = 'done'
          AND (
            COALESCE(TRIM(update_text), '') <> ''
            OR (update_images IS NOT NULL AND TRIM(update_images) <> '' AND TRIM(update_images) <> '[]')
          )
    ");
    if ($qOutcome) {
        while ($r = mysqli_fetch_assoc($qOutcome)) {
            $needOutcomeFoundations[(int)($r['foundation_id'] ?? 0)] = true;
        }
    }
    $qDone = mysqli_query($conn, "
        SELECT DISTINCT foundation_id
        FROM foundation_needlist
        WHERE approve_item = 'done'
    ");
    if ($qDone) {
        while ($r = mysqli_fetch_assoc($qDone)) {
            $needDoneFoundations[(int)($r['foundation_id'] ?? 0)] = true;
        }
    }

    $qPurch = mysqli_query($conn, "
        SELECT DISTINCT foundation_id
        FROM foundation_needlist
        WHERE approve_item = 'purchasing'
    ");
    if ($qPurch) {
        while ($r = mysqli_fetch_assoc($qPurch)) {
            $needPurchasingFoundations[(int)($r['foundation_id'] ?? 0)] = true;
        }
    }
}

/* ดึงรายการอนุมัติเพียงพอสำหรับสไลด์ — มูลนิธิจัดการรายการตัวเองไม่ใช้สไลด์สาธารณะ */
$needlistByFoundation = [];
if (!$isFoundationManageView && $foundationRows !== []) {
    $batchFids = [];
    foreach ($foundationRows as $fRow) {
        $batchFid = (int)($fRow['foundation_id'] ?? 0);
        if ($batchFid > 0) {
            $batchFids[] = $batchFid;
        }
    }
    $batchFids = array_values(array_unique($batchFids));
    if ($batchFids !== []) {
        $ph = implode(',', array_fill(0, count($batchFids), '?'));
        $types = str_repeat('i', count($batchFids));
        $stBatchNeed = $conn->prepare("
            SELECT foundation_id, item_id, item_name, qty_needed, urgent, item_image, item_image_2, item_image_3, need_foundation_image,
                   approve_item, donate_window_end_at, current_donate, total_price
            FROM foundation_needlist
            WHERE foundation_id IN ($ph)
              AND (
                approve_item IN ('approved', 'purchasing')
                OR approve_item = 'done'
              )
            ORDER BY foundation_id ASC, urgent DESC, item_id DESC
        ");
        if ($stBatchNeed) {
            $stBatchNeed->bind_param($types, ...$batchFids);
            $stBatchNeed->execute();
            $batchRes = $stBatchNeed->get_result();
            while ($batchRow = $batchRes->fetch_assoc()) {
                $batchFid = (int)($batchRow['foundation_id'] ?? 0);
                if ($batchFid <= 0) {
                    continue;
                }
                if (!isset($needlistByFoundation[$batchFid])) {
                    $needlistByFoundation[$batchFid] = [];
                }
                if (count($needlistByFoundation[$batchFid]) < 120) {
                    $needlistByFoundation[$batchFid][] = $batchRow;
                }
            }
        }
    }
}

    if (!$isFoundationManageView) {
        drawdream_foundation_public_page_cache_set(
            $foundationRows,
            $donationTotals,
            $goalTotals,
            $donationTotalsSlideTrack,
            $goalTotalsSlideTrack,
            $needOutcomeFoundations,
            $needDoneFoundations,
            $needPurchasingFoundations,
            $needlistByFoundation
        );
    }
}

/** รายการยังเปิดรับบริจาค (ตรวจใน PHP สำหรับแถวจาก query รวม) */
$foundation_needlist_row_open = static function (array $row): bool {
    if (($row['approve_item'] ?? '') !== 'approved') {
        return false;
    }
    $dwe = trim((string)($row['donate_window_end_at'] ?? ''));
    if ($dwe === '' || str_starts_with($dwe, '0000-00-00')) {
        return true;
    }
    $ts = strtotime($dwe);
    return $ts !== false && $ts > time();
};

/** รายการครบยอดแล้ว — ย้ายไปแท็บผลลัพธ์ที่สำเร็จแล้ว */
$foundation_needlist_row_goal_met = static function (array $row): bool {
    return drawdream_needlist_item_goal_met(
        (float)($row['current_donate'] ?? 0),
        (float)($row['total_price'] ?? 0)
    );
};

// ดึงรายการสิ่งของที่เสนอทั้งหมด (สำหรับ foundation role)
$myNeedlist = [];
$myFoundationId = 0;
$myNeedlistGoalMet = false;
$myNeedlistResultReady = false;
$myNeedProposeBlock = ['blocked' => false, 'reason' => '', 'donate_end_at' => null];
if ($isFoundationManageView) {
    $myFoundationId = (int)($foundationRows[0]['foundation_id'] ?? 0);

    if ($myFoundationId > 0) {
        $stOpenDon = $conn->prepare(
            "SELECT COALESCE(SUM(current_donate), 0) AS total
             FROM foundation_needlist
             WHERE foundation_id = ? AND $needOpenPub"
        );
        if ($stOpenDon) {
            $stOpenDon->bind_param('i', $myFoundationId);
            $stOpenDon->execute();
            $mc = (float)(($stOpenDon->get_result()->fetch_assoc()['total'] ?? 0));
            $donationTotals[$myFoundationId] = $mc;
        } else {
            $mc = 0.0;
        }

        $stOpenGoal = $conn->prepare(
            "SELECT COALESCE(SUM(COALESCE(total_price, 0)), 0) AS goal
             FROM foundation_needlist
             WHERE foundation_id = ? AND $needOpenPub"
        );
        if ($stOpenGoal) {
            $stOpenGoal->bind_param('i', $myFoundationId);
            $stOpenGoal->execute();
            $mg = (float)(($stOpenGoal->get_result()->fetch_assoc()['goal'] ?? 0));
            $goalTotals[$myFoundationId] = $mg;
        } else {
            $mg = 0.0;
        }

        $myNeedlistGoalMet = $mg > 0 && $mc >= $mg;

        $myNeedProposeBlock = drawdream_foundation_needlist_propose_blocked($conn, $myFoundationId);

        $readyStmt = $conn->prepare("SELECT 1 FROM foundation_needlist WHERE foundation_id = ? AND approve_item = 'done' LIMIT 1");
        if ($readyStmt) {
            $readyStmt->bind_param("i", $myFoundationId);
            $readyStmt->execute();
            $myNeedlistResultReady = (bool)$readyStmt->get_result()->fetch_row();
        }
    }

    if ($myFoundationId > 0) {
        $stmtMine = $conn->prepare("
            SELECT item_id, item_name, desired_brand, total_price, urgent, item_image, item_image_2, item_image_3, need_foundation_image, approve_item, note, donate_window_end_at, current_donate, service_charge_paid_at
            FROM foundation_needlist
            WHERE foundation_id = ?
            ORDER BY item_id DESC
        ");
        if ($stmtMine) {
            $stmtMine->bind_param("i", $myFoundationId);
            $stmtMine->execute();
            $myNeedlist = $stmtMine->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$myNeedProposeBlockTitle = '';
if (!empty($myNeedProposeBlock['blocked'])) {
    switch ($myNeedProposeBlock['reason'] ?? '') {
        case 'pending':
            $myNeedProposeBlockTitle = 'รอแอดมินตรวจสอบรายการสิ่งของ — จึงจะเสนอรายการเพิ่มได้หลังมีผลการตรวจสอบ';
            break;
        case 'purchasing':
            $myNeedProposeBlockTitle = 'รายการสิ่งของอยู่ในขั้นตอนจัดซื้อ — จึงจะเสนอรายการเพิ่มไม่ได้';
            break;
        default:
            $myNeedProposeBlockTitle = 'รอบปัจจุบันเปิดรับบริจาค 1 เดือน หลังครบกำหนดจะเสนอรอบใหม่ได้';
            break;
    }
}

/**
 * โปรไฟล์มูลนิธิครบสำหรับแสดงส่วน "มูลนิธิที่คุณอาจสนใจ"
 */
function foundation_profile_complete_public(array $f): bool {
    foreach (['foundation_name', 'phone', 'address', 'foundation_desc'] as $k) {
        if (trim((string)($f[$k] ?? '')) === '') {
            return false;
        }
    }
    if (trim((string)($f['foundation_image'] ?? '')) === '') {
        return false;
    }
    return true;
}

$foundationSlidesOpen = [];
$foundationSlidesDone = [];
$interestFoundations = [];
if (!$isFoundationManageView) {
foreach ($foundationRows as $f) {
    $fid = (int)$f['foundation_id'];
    if (trim((string)($f['foundation_name'] ?? '')) !== '') {
        $interestFoundations[] = $f;
    }

    $items = $needlistByFoundation[$fid] ?? [];
    if (count($items) === 0) {
        continue;
    }

    $openItems = [];
    $doneItems = [];
    foreach ($items as $row) {
        $rowGoalMet = $foundation_needlist_row_goal_met($row);
        if ($foundation_needlist_row_open($row) && !$rowGoalMet) {
            $openItems[] = $row;
        }
        $ap = (string)($row['approve_item'] ?? '');
        if (in_array($ap, ['purchasing', 'done'], true)
            || ($rowGoalMet && $ap === 'approved')) {
            $doneItems[] = $row;
        }
    }

    if ($openItems !== []) {
        $currentOpen = 0.0;
        $goalOpen = 0.0;
        foreach ($openItems as $row) {
            $currentOpen += (float)($row['current_donate'] ?? 0);
            $goalOpen += (float)($row['total_price'] ?? 0);
        }
        if ($goalOpen > 0) {
            $foundationSlidesOpen[] = [
                'f' => $f,
                'items' => $openItems,
                'fid' => $fid,
                'current' => $currentOpen,
                'goal' => $goalOpen,
                'percent' => min(100, round(($currentOpen / $goalOpen) * 100, 2)),
                'mode' => 'open',
            ];
        }
    }

    if ($doneItems !== []) {
        $currentTrack = 0.0;
        $goalTrack = 0.0;
        foreach ($doneItems as $row) {
            $currentTrack += (float)($row['current_donate'] ?? 0);
            $goalTrack += (float)($row['total_price'] ?? 0);
        }
        $foundationSlidesDone[] = [
            'f' => $f,
            'items' => $doneItems,
            'fid' => $fid,
            'current' => $currentTrack,
            'goal' => $goalTrack,
            'percent' => ($goalTrack > 0) ? min(100, round(($currentTrack / $goalTrack) * 100, 2)) : 0,
            'mode' => 'done',
        ];
    }
}
}

$needTabPanels = [
    [
        'id' => 'open',
        'label' => 'รายการที่เปิดรับบริจาค',
        'slides' => $foundationSlidesOpen,
        'empty' => 'ยังไม่มีมูลนิธิที่เปิดรับบริจาคสิ่งของ',
        'carousel_label' => 'มูลนิธิที่เปิดรับบริจาคสิ่งของ',
    ],
    [
        'id' => 'done',
        'label' => 'ผลลัพธ์ที่สำเร็จแล้ว',
        'slides' => $foundationSlidesDone,
        'empty' => 'ยังไม่มีผลลัพธ์สิ่งของที่สำเร็จแล้ว',
        'carousel_label' => 'มูลนิธิที่มีผลลัพธ์สิ่งของสำเร็จแล้ว',
    ],
];
$defaultNeedTab = $foundationSlidesOpen !== [] ? 'open' : 'done';
$hasAnySlides = $foundationSlidesOpen !== [] || $foundationSlidesDone !== [];
$justRegistered = isset($_GET['registered']) && (string)$_GET['registered'] === '1';
$showFoundationOnboarding = $isFoundationManageView && !$is_verified;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>มูลนิธิ | DrawDream</title>
  <?php require_once __DIR__ . '/includes/vendor_assets.php'; drawdream_foundation_page_assets_head(); ?>
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/brand_logo.css?v=3">
  <link rel="stylesheet" href="css/site_footer.css?v=3">
  <link rel="stylesheet" href="css/foundation.css?v=58">
  <link rel="stylesheet" href="css/foundation_manage.css?v=2">
</head>
<body class="foundation-page">

  <?php include 'navbar.php'; ?>

  <div class="page-wrap">

    <?php if ($showFoundationOnboarding): ?>
      <div class="foundation-onboarding-banner" role="status">
        <div class="foundation-onboarding-banner__icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></div>
        <div class="foundation-onboarding-banner__body">
          <strong>บัญชีมูลนิธิรอแอดมินตรวจสอบ</strong>
          <p>ตอนนี้ดูหน้ามูลนิธิและแก้โปรไฟล์ได้ — หลังอนุมัติแล้วจึงจะเสนอสิ่งของ โครงการ และใช้แดชบอร์ดได้เต็มรูปแบบ (โดยทั่วไป 1–3 วันทำการ)</p>
          <a href="update_profile.php" class="foundation-onboarding-banner__link">ตรวจสอบ / แก้ไขโปรไฟล์มูลนิธิ</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($foundationPageMsg !== ''): ?>
      <div class="alert alert-warning foundation-page-flash" role="status" style="max-width:960px;margin:0 auto 16px;padding:12px 16px;border-radius:10px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;">
        <?= htmlspecialchars($foundationPageMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if (($_SESSION['role'] ?? '') === 'foundation'): ?>
      <div class="foundation-view-wrap">
        <div class="foundation-view-head">
          <h1>มูลนิธิของเรา</h1>
          <p>จัดการรายการสิ่งของที่ต้องการได้จากหน้านี้</p>
          <div class="foundation-view-toolbar">
            <div class="foundation-view-actions">
              <?php if ($is_verified): ?>
                <?php if (empty($myNeedProposeBlock['blocked'])): ?>
                  <a href="foundation_add_need.php" class="foundation-manage-btn foundation-manage-btn-primary">+ เสนอสิ่งของมูลนิธิ</a>
                <?php else: ?>
                  <span class="foundation-manage-btn foundation-manage-btn-disabled" aria-disabled="true" title="<?= htmlspecialchars($myNeedProposeBlockTitle, ENT_QUOTES, 'UTF-8') ?>">+ เสนอสิ่งของมูลนิธิ</span>
                <?php endif; ?>
                <button type="button" id="toggleEditNeedBtn" class="foundation-manage-btn foundation-manage-btn-edit">แก้ไขรายการสิ่งของ</button>
                <?php if ($myNeedlistResultReady): ?>
                  <a href="foundation_post_needlist_result.php" class="foundation-manage-btn foundation-manage-btn-update">อัปเดตผลลัพธ์สิ่งของ</a>
                <?php else: ?>
                  <span class="foundation-manage-btn foundation-manage-btn-disabled" aria-disabled="true" title="อัปเดตได้เมื่อแอดมินยืนยันจัดส่งสิ่งของแล้ว (สถานะ done)">อัปเดตผลลัพธ์สิ่งของ</span>
                <?php endif; ?>
              <?php else: ?>
                <p class="foundation-pending-inline-msg">บัญชีมูลนิธิยังรอการตรวจสอบจากผู้ดูแลระบบ — หลังอนุมัติแล้วจึงจะเสนอหรือจัดการรายการสิ่งของได้</p>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="my-needlist-section" id="my-needlist-section">
          <h3 class="my-needlist-title" style="font-family:'Prompt',sans-serif;font-size:1.4em;color:#2e3f7f;margin-bottom:18px;">รายการสิ่งของที่เสนอทั้งหมด</h3>
          <p class="needlist-cycle-hint">ระบบปิดรับบริจาคอัตโนมัติเมื่อครบ 1 เดือนนับจากวันที่แอดมินอนุมัติรายการ และจึงจะเสนอรอบใหม่ได้</p>
          <?php
          require_once __DIR__ . '/includes/foundation_need_flash.php';
          echo drawdream_foundation_need_flash_render_html();
          ?>
          <?php if (!empty($_GET['need_created'])): ?>
            <div class="alert alert-success needlist-flash" role="status">เสนอรายการสิ่งของสำเร็จ รอแอดมินอนุมัติ</div>
          <?php endif; ?>
          <?php if (!empty($_GET['need_updated'])): ?>
            <div class="alert alert-success needlist-flash" role="status">อัปเดตรายการสิ่งของแล้ว</div>
          <?php endif; ?>
          <?php if (!empty($_GET['need_resubmitted'])): ?>
            <div class="alert alert-success needlist-flash" role="status">แก้ไขรายการแล้ว ส่งให้แอดมินตรวจอนุมัติใหม่</div>
          <?php endif; ?>
          <?php if (!empty($_GET['need_edit_locked'])): ?>
            <div class="alert alert-warning needlist-flash" role="status">รายการนี้มีผู้บริจาคแล้วหรืออยู่ขั้นตอนถัดไป จึงแก้ไขไม่ได้</div>
          <?php endif; ?>
          <?php if (!empty($_GET['need_round_wait'])): ?>
            <?php
              $nextCloseText = '';
              $nextRaw = trim((string)($_GET['next'] ?? ''));
              if ($nextRaw !== '') {
                $nextTs = strtotime($nextRaw);
                if ($nextTs !== false) {
                  $nextCloseText = date('d/m/Y H:i', $nextTs);
                }
              }
              $waitReason = (string)($_GET['reason'] ?? 'approved_open');
            ?>
            <?php if ($waitReason === 'pending'): ?>
              <div class="alert alert-warning needlist-flash" role="status">ไม่สามารถเสนอรายการสิ่งของเพิ่มได้ในขณะที่รอการตรวจสอบจากแอดมิน</div>
            <?php elseif ($waitReason === 'purchasing'): ?>
              <div class="alert alert-warning needlist-flash" role="status">ไม่สามารถเสนอรายการสิ่งของเพิ่มได้ในขณะที่รายการอยู่ในขั้นตอนจัดซื้อ</div>
            <?php else: ?>
              <div class="alert alert-warning needlist-flash" role="status">เสนอรายการรอบใหม่ได้หลังจากรอบปัจจุบันครบ 1 เดือนแล้ว<?= $nextCloseText !== '' ? ' (รอบนี้ปิดรับ: ' . htmlspecialchars($nextCloseText, ENT_QUOTES, 'UTF-8') . ')' : '' ?></div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (empty($myNeedlist)): ?>
            <div class="foundation-needlist-empty">ยังไม่มีรายการที่เสนอ</div>
          <?php endif; ?>
          <div class="my-needlist-grid">
            <?php foreach ($myNeedlist as $nl): ?>
            <?php
              $status = $nl['approve_item'] ?? 'pending';
              $nlImages = foundation_needlist_item_filenames_from_row($nl);
              $nlFdn = foundation_needlist_normalize_filename((string)($nl['need_foundation_image'] ?? ''));
              $needUploadDirAbs = drawdream_needlist_upload_dir();
              $needCardImages = [];
              foreach ($nlImages as $imgFn) {
                  if ($imgFn !== '' && is_file($needUploadDirAbs . $imgFn)) {
                      $needCardImages[] = $imgFn;
                  }
              }
              $nlImg = '';
              if ($nlFdn !== '' && is_file($needUploadDirAbs . $nlFdn)) {
                  $nlImg = $nlFdn;
              }
              if ($needCardImages === [] && $nlImg !== '') {
                  $needCardImages[] = $nlImg;
              }
              $statusLabel = ['pending' => 'รอการอนุมัติ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'][$status] ?? $status;
              /* คลาสสอดคล้องกับ .foundation-status-pill ในโครงการ (project.css) */
              $statusPillClass = ['pending' => 'st-pending', 'approved' => 'st-approved', 'rejected' => 'st-rejected'][$status] ?? 'st-pending';
              $dweRaw = trim((string)($nl['donate_window_end_at'] ?? ''));
              $donateWindowExpired = ($status === 'approved' && $dweRaw !== '' && !str_starts_with($dweRaw, '0000-00-00') && strtotime($dweRaw) !== false && strtotime($dweRaw) < time());
              $canEditNeed = drawdream_foundation_needlist_may_edit($nl);
            ?>
            <?php
              $cardGoal = (float)($nl['total_price'] ?? 0);
            ?>
            <div class="need-card<?= !$is_verified ? ' need-card--pending-verify' : '' ?>">
              <?php if ($is_verified): ?>
              <a class="need-card-tap-link" href="foundation_need_view.php?id=<?= (int)($nl['item_id'] ?? 0) ?>" aria-label="ดูรายละเอียดรายการสิ่งของ"></a>
              <div class="need-card-img-wrap">
                <div class="need-card-img-primary">
                  <?php if ($needCardImages !== []): ?>
                  <div class="need-img-rotator need-card-img-rotator<?= count($needCardImages) > 1 ? ' need-img-rotator--multi' : '' ?>" data-interval="3000">
                    <div class="need-img-rotator__viewport">
                      <?php foreach ($needCardImages as $ci => $cardImgFn): ?>
                      <div class="need-img-rotator__slide<?= $ci === 0 ? ' is-active' : '' ?>" data-slide="<?= (int)$ci ?>">
                        <img src="uploads/needs/<?= htmlspecialchars($cardImgFn) ?>" alt="" class="need-card-img">
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <?php if (count($needCardImages) > 1): ?>
                    <div class="need-img-rotator__dots need-img-rotator__dots--card" role="tablist" aria-label="เลือกภาพสิ่งของ">
                      <?php foreach ($needCardImages as $ci => $_): ?>
                      <button type="button" class="need-img-rotator__dot<?= $ci === 0 ? ' is-active' : '' ?>" data-go="<?= (int)$ci ?>" role="tab" aria-label="ภาพ <?= (int)$ci + 1 ?>" aria-selected="<?= $ci === 0 ? 'true' : 'false' ?>"></button>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php else: ?>
                    <div class="need-card-noimg">ไม่มีรูป</div>
                  <?php endif; ?>
                  <?php if ((int)$nl['urgent'] === 1): ?>
                    <span class="need-urgent-badge">ต้องการด่วน</span>
                  <?php endif; ?>
                  <div class="need-card-status-overlay">
                    <span class="foundation-status-pill <?= htmlspecialchars($statusPillClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    <?php if ($donateWindowExpired): ?>
                      <span class="foundation-status-pill st-need-closed" title="ครบระยะเวลารับบริจาคแล้ว">ปิดรับบริจาคแล้ว</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="need-card-body">
                <?php if (!$donateWindowExpired && $status === 'approved' && $dweRaw !== '' && !str_starts_with($dweRaw, '0000-00-00') && strtotime($dweRaw) !== false): ?>
                  <span class="need-window-hint" title="วันปิดรับบริจาคอัตโนมัติ 1 เดือน">ปิดรับอัตโนมัติครบ 1 เดือน (<?= htmlspecialchars(date('d/m/Y H:i', strtotime($dweRaw))) ?>)</span>
                <?php endif; ?>
                <div class="need-card-name"><?= htmlspecialchars($nl['item_name']) ?></div>
                <div class="need-card-goal">
                  เป้าหมาย: <?= number_format($cardGoal, 0) ?> บาท
                  <span class="need-period">/ รอบละ 1 เดือน</span>
                </div>
                <?php if (trim((string)($nl['desired_brand'] ?? '')) !== ''): ?>
                  <div class="need-card-desc">แบรนด์ที่ต้องการ: <?= htmlspecialchars((string)$nl['desired_brand']) ?></div>
                <?php endif; ?>
                <div class="need-edit-wrap">
                  <?php if ($canEditNeed): ?>
                    <a class="need-card-edit-link" href="foundation_add_need.php?edit=<?= (int)($nl['item_id'] ?? 0) ?>&amp;return_to=<?= rawurlencode('foundation.php#my-needlist-section') ?>" onclick="event.stopPropagation();"><?= $status === 'approved' ? 'แก้ไขและส่งอนุมัติใหม่' : 'แก้ไขรายการนี้' ?></a>
                  <?php else: ?>
                    <span class="need-card-edit-link need-card-edit-link--disabled" aria-disabled="true" title="แก้ไขได้เฉพาะรายการที่รออนุมัติ/ไม่อนุมัติ หรืออนุมัติแล้วแต่ยังไม่มียอดบริจาค">แก้ไขรายการนี้</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php else: ?>
              <div class="need-card-body need-card-body--compact">
                <div class="need-card-status-row">
                  <span class="foundation-status-pill <?= htmlspecialchars($statusPillClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                </div>
                <div class="need-card-name"><?= htmlspecialchars($nl['item_name']) ?></div>
                <p class="need-card-verify-hint">รอแอดมินอนุมัติบัญชีเพื่อดูรายละเอียดและจัดการรายการ</p>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php else: ?>
      <div class="top-bar">
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          <a href="admin_approve_needlist.php" class="admin-btn">ไปหน้าอนุมัติรายการ</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (($_SESSION['role'] ?? '') !== 'foundation'): ?>

    <?php if (!$hasAnySlides): ?>
      <p class="foundation-empty-msg">ยังไม่มีมูลนิธิที่เปิดรับบริจาครายการสิ่งของในระบบ</p>
    <?php else: ?>
    <section class="fd-need-tabs-section" aria-label="รายการสิ่งของมูลนิธิ">
      <?php
        $defaultNeedTabLabel = $needTabPanels[0]['label'] ?? '';
        foreach ($needTabPanels as $panelLabelRow) {
            if ($panelLabelRow['id'] === $defaultNeedTab) {
                $defaultNeedTabLabel = $panelLabelRow['label'];
                break;
            }
        }
      ?>
      <div class="fd-need-view-switch">
        <div class="fd-need-dropdown" data-need-dropdown>
          <button type="button"
            class="fd-need-dropdown__trigger"
            id="fd-need-tab-trigger"
            aria-haspopup="listbox"
            aria-expanded="false"
            aria-controls="fd-need-dropdown-menu"
            aria-label="เลือกมุมมองรายการสิ่งของ">
            <span class="fd-need-dropdown__label"><?= htmlspecialchars($defaultNeedTabLabel) ?></span>
            <i class="bi bi-chevron-down fd-need-dropdown__chevron" aria-hidden="true"></i>
          </button>
          <ul class="fd-need-dropdown__menu" id="fd-need-dropdown-menu" role="listbox" aria-labelledby="fd-need-tab-trigger" hidden>
            <?php foreach ($needTabPanels as $panel): ?>
            <li role="presentation">
              <button type="button"
                class="fd-need-dropdown__option<?= $panel['id'] === $defaultNeedTab ? ' is-active' : '' ?>"
                role="option"
                data-need-tab="<?= htmlspecialchars($panel['id'], ENT_QUOTES, 'UTF-8') ?>"
                aria-selected="<?= $panel['id'] === $defaultNeedTab ? 'true' : 'false' ?>">
                <?= htmlspecialchars($panel['label']) ?>
              </button>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

      <?php foreach ($needTabPanels as $panel):
        $panelSlides = $panel['slides'];
        $panelMode = $panel['id'];
        $panelActive = $panel['id'] === $defaultNeedTab;
      ?>
      <div class="fd-need-tab-panel<?= $panelActive ? ' is-active' : '' ?>"
        id="fd-need-panel-<?= htmlspecialchars($panel['id'], ENT_QUOTES, 'UTF-8') ?>"
        role="tabpanel"
        data-need-panel="<?= htmlspecialchars($panel['id'], ENT_QUOTES, 'UTF-8') ?>"
        aria-labelledby="fd-need-tab-trigger"
        <?= $panelActive ? '' : 'hidden' ?>>
        <?php if ($panelSlides === []): ?>
          <p class="foundation-empty-msg foundation-empty-msg--panel"><?= htmlspecialchars($panel['empty']) ?></p>
        <?php else: ?>
        <section class="foundation-hero-carousel" data-interval="10000" data-carousel-mode="<?= htmlspecialchars($panelMode, ENT_QUOTES, 'UTF-8') ?>" aria-roledescription="carousel" aria-label="<?= htmlspecialchars($panel['carousel_label']) ?>">
          <div class="foundation-hero-track">
            <?php foreach ($panelSlides as $idx => $slide):
              $f = $slide['f'];
              $items = $slide['items'];
              $fid = $slide['fid'];
              $current = $slide['current'];
              $goal = $slide['goal'];
              $percent = $slide['percent'];
              $slideMode = $slide['mode'] ?? $panelMode;
              $foundationImage = $f['foundation_image'] ?? '';
              $facebookUrl = $f['facebook_url'] ?? '';
              $heroProposalImage = '';
              $needUploadDirAbs = drawdream_needlist_upload_dir();
              foreach ($items as $itHero) {
                $nfHero = foundation_needlist_normalize_filename((string)($itHero['need_foundation_image'] ?? ''));
                if ($nfHero !== '' && is_file($needUploadDirAbs . $nfHero)) {
                  $heroProposalImage = $nfHero;
                  break;
                }
              }
              $itemShowcaseEntries = [];
              foreach ($items as $itRow) {
                $isUrgent = (int)($itRow['urgent'] ?? 0) === 1
                  || $itRow['urgent'] === true
                  || $itRow['urgent'] === '1'
                  || strtolower((string)($itRow['urgent'] ?? '')) === 'true';
                foreach (foundation_needlist_item_filenames_from_row($itRow) as $bn) {
                  if ($bn === '' || $bn === '.' || $bn === '..') {
                    continue;
                  }
                  $itemShowcaseEntries[] = ['file' => $bn, 'urgent' => $isUrgent];
                  if (count($itemShowcaseEntries) >= 3) {
                    break 2;
                  }
                }
              }
            ?>
            <article class="foundation-card foundation-slide<?= $idx === 0 ? ' is-active' : '' ?>" id="f<?= $fid ?>-<?= htmlspecialchars($slideMode, ENT_QUOTES, 'UTF-8') ?>" data-slide-index="<?= (int)$idx ?>" data-foundation-id="<?= $fid ?>" aria-hidden="<?= $idx === 0 ? 'false' : 'true' ?>">
              <div class="fc-left">
                <h2 class="fc-title"><?= htmlspecialchars($f['foundation_name'] ?? 'มูลนิธิ') ?></h2>
                <p class="fc-desc"><?= htmlspecialchars(trim((string)($f['foundation_desc'] ?? '')) !== '' ? $f['foundation_desc'] : 'มูลนิธินี้ยังไม่ได้เพิ่มคำอธิบายเพิ่มเติม') ?></p>
                <div class="fc-progress-block">
                  <div class="bar bar-donate" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($percent) ?>">
                    <div class="bar-fill" style="width:<?= htmlspecialchars((string)$percent) ?>%"></div>
                  </div>
                  <div class="fc-amount-row">
                    <span class="fc-amount-label">ยอดปัจจุบัน</span>
                    <span class="fc-prog-current"><?= number_format($current, 0) ?></span>
                    <span class="fc-prog-slash">/</span>
                    <span class="fc-prog-goal"><?= number_format($goal, 0) ?> บาท</span>
                  </div>
                </div>

                <div class="fc-urgent-zone">
                  <?php if (count($itemShowcaseEntries) > 0): ?>
                  <div class="fc-needlist-showcase-carousel need-img-rotator<?= count($itemShowcaseEntries) > 1 ? ' need-img-rotator--multi' : '' ?>" data-interval="3000" aria-label="ภาพรายการสิ่งของ">
                    <div class="need-img-rotator__viewport items urgent-items fc-urgent-items-grid">
                      <?php foreach ($itemShowcaseEntries as $si => $showEnt):
                        $oneImg = (string)($showEnt['file'] ?? '');
                        $showUrgent = !empty($showEnt['urgent']);
                      ?>
                      <div class="item urgent-item-card need-img-rotator__slide<?= $si === 0 ? ' is-active' : '' ?>" data-slide="<?= (int)$si ?>">
                        <?php if ($showUrgent && $slideMode === 'open'): ?>
                          <span class="urgent-tag urgent-tag-abs">ต้องการด่วน</span>
                        <?php endif; ?>
                        <?php if ($oneImg !== ''): ?>
                          <img class="item-img urgent-img-big" src="uploads/needs/<?= htmlspecialchars($oneImg) ?>" alt="">
                        <?php else: ?>
                          <div class="noimg urgent-img-big">ไม่มีรูปภาพ</div>
                        <?php endif; ?>
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <?php if (count($itemShowcaseEntries) > 1): ?>
                    <div class="need-img-rotator__dots" role="tablist" aria-label="เลือกภาพสิ่งของ">
                      <?php foreach ($itemShowcaseEntries as $si => $_): ?>
                      <button type="button" class="need-img-rotator__dot<?= $si === 0 ? ' is-active' : '' ?>" data-go="<?= (int)$si ?>" role="tab" aria-label="ภาพ <?= (int)$si + 1 ?>" aria-selected="<?= $si === 0 ? 'true' : 'false' ?>"></button>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>
                <?php if ($slideMode === 'open'): ?>
                  <a class="btn-donate btn-donate--primary-cta" href="payment/foundation_donate.php?fid=<?= $fid ?>">บริจาค</a>
                <?php else: ?>
                  <a class="btn-donate btn-donate--need-outcome" href="needlist_result.php?fid=<?= $fid ?>">ผลลัพธ์ทุนสิ่งของ</a>
                <?php endif; ?>
              </div>
              <div class="fc-right">
                <div class="fc-right-media">
                  <?php if ($heroProposalImage !== ''): ?>
                    <img class="cover foundation-cover-large" src="uploads/needs/<?= htmlspecialchars($heroProposalImage) ?>" alt="ภาพประกอบรายการสิ่งของ">
                  <?php elseif (!empty($foundationImage)): ?>
                    <img class="cover foundation-cover-large" src="uploads/profiles/<?= htmlspecialchars($foundationImage) ?>" alt="รูปมูลนิธิ">
                  <?php else: ?>
                    <div class="cover-empty foundation-cover-placeholder">ยังไม่มีข้อมูลรูปให้</div>
                  <?php endif; ?>
                </div>
                <div class="fc-right-meta">
                  <?php if (!empty($facebookUrl)):
                    $fbHref = trim((string)$facebookUrl);
                    if ($fbHref !== '' && !preg_match('#^https?://#i', $fbHref)) {
                        $fbHref = 'https://' . ltrim($fbHref, '/');
                    }
                  ?>
                    <a class="fc-fb-link" href="<?= htmlspecialchars($fbHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                      <i class="bi bi-facebook" aria-hidden="true"></i> Facebook
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php if (count($panelSlides) > 1): ?>
          <button type="button" class="foundation-hero-nav foundation-hero-nav--prev" data-hero-prev aria-label="มูลนิธิก่อนหน้า">‹</button>
          <button type="button" class="foundation-hero-nav foundation-hero-nav--next" data-hero-next aria-label="มูลนิธิถัดไป">›</button>
          <div class="foundation-hero-dots" role="tablist" aria-label="เลือกมูลนิธิ">
            <?php foreach ($panelSlides as $idx => $_): ?>
            <button type="button" class="foundation-hero-dot<?= $idx === 0 ? ' is-active' : '' ?>" data-go="<?= (int)$idx ?>" role="tab" aria-selected="<?= $idx === 0 ? 'true' : 'false' ?>" aria-label="สไลด์ <?= (int)$idx + 1 ?>"></button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if (!empty($interestFoundations)): ?>
    <section class="fd-interest-section" aria-labelledby="fd-interest-heading">
      <h2 id="fd-interest-heading" class="fd-interest-title">มูลนิธิที่คุณอาจสนใจ</h2>
      <div class="fd-interest-carousel<?= count($interestFoundations) > 3 ? ' fd-interest-carousel--scroll' : '' ?>">
        <?php if (count($interestFoundations) > 3): ?>
          <button type="button" class="fd-interest-nav fd-interest-nav--prev" data-interest-prev aria-label="เลื่อนไปมูลนิธิก่อนหน้า">
            <i class="bi bi-chevron-left"></i>
          </button>
        <?php endif; ?>
        <div class="fd-interest-viewport" data-interest-viewport>
          <div class="fd-interest-grid">
        <?php foreach ($interestFoundations as $inf):
          $ifid = (int)$inf['foundation_id'];
          $icurrent = $donationTotals[$ifid] ?? 0;
          $igoal = $goalTotals[$ifid] ?? 0;
          $iImg = trim((string)($inf['foundation_image'] ?? ''));
          $iDesc = (string)($inf['foundation_desc'] ?? '');
          $shortDesc = drawdream_utf8_substr($iDesc, 0, 200);
          if (drawdream_utf8_strlen($iDesc) > 200) {
              $shortDesc .= '…';
          }
        ?>
        <article class="fd-interest-card">
          <a class="fd-interest-card-hit" href="foundation_donate_info.php?fid=<?= $ifid ?>" aria-label="ดูมูลนิธิ <?= htmlspecialchars((string)($inf['foundation_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></a>
          <div class="fd-interest-top">
            <?php if ($iImg !== ''): ?>
              <img class="fd-interest-cover" src="uploads/profiles/<?= htmlspecialchars($iImg) ?>" alt="<?= htmlspecialchars($inf['foundation_name'] ?? '') ?>">
            <?php else: ?>
              <div class="fd-interest-cover fd-interest-cover--empty">ไม่มีรูป</div>
            <?php endif; ?>
            <a class="fd-interest-pill-btn" href="foundation_donate_info.php?fid=<?= $ifid ?>">ร่วมบริจาค</a>
          </div>
          <div class="fd-interest-body">
            <h3 class="fd-interest-name"><?= htmlspecialchars($inf['foundation_name'] ?? 'มูลนิธิ') ?></h3>
            <p class="fd-interest-desc"><?= nl2br(htmlspecialchars($shortDesc)) ?></p>
          </div>
        </article>
        <?php endforeach; ?>
          </div>
        </div>
        <?php if (count($interestFoundations) > 3): ?>
          <button type="button" class="fd-interest-nav fd-interest-nav--next" data-interest-next aria-label="เลื่อนไปมูลนิธิถัดไป">
            <i class="bi bi-chevron-right"></i>
          </button>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="fd-community-section" aria-label="เครือข่ายเพื่อสังคม">
      <div class="fd-community-hero">
        <div class="fd-community-hero-image">
          <img src="<?= htmlspecialchars(drawdream_community_img('project_run')) ?>" alt="" class="fd-community-hero-img" width="1200" height="800" decoding="async">
        </div>
        <div class="fd-community-hero-text">
          ด้วยน้ำใจจากคุณ<br>
          เราสามารถสร้างผลกระทบกับชีวิตเด็กกว่า 1.7 ล้านคน ด้วยการ<br>
          ดำเนินงานพัฒนาเพื่อแก้ไขปัญหาอันเป็นรากของความยากจน<br>
          ผ่านการดำเนินงาน<br>
          พัฒนาชุมชนและงานรณรงค์เพื่อความยุติธรรมในสังคม
        </div>
      </div>

      <div class="fd-community-band">
        <h3>กลุ่มองค์กรเพื่อสังคม<br>ที่ร่วมงานกับเรา</h3>
        <p>
          องค์กรที่ได้รับการสนับสนุนงบประมาณเพื่อชุมชน มีความมุ่งมั่นในการเปลี่ยนแปลงทางสังคม<br>
          ผ่านการสื่อสารและกิจกรรมการศึกษา โดยเน้นให้เกิดการพัฒนาสังคมรุ่นใหม่
        </p>
        <div class="fd-community-org-grid">
          <div class="fd-community-org-card">
            <img src="<?= htmlspecialchars(drawdream_community_img('partner1')) ?>" alt="พันธมิตรเครือข่าย 1" class="fd-community-org-card__img" width="640" height="380" decoding="async" loading="lazy">
          </div>
          <div class="fd-community-org-card">
            <img src="<?= htmlspecialchars(drawdream_community_img('partner2')) ?>" alt="พันธมิตรเครือข่าย 2" class="fd-community-org-card__img" width="640" height="380" decoding="async" loading="lazy">
          </div>
          <div class="fd-community-org-card">
            <img src="<?= htmlspecialchars(drawdream_community_img('partner3')) ?>" alt="พันธมิตรเครือข่าย 3" class="fd-community-org-card__img" width="640" height="380" decoding="async" loading="lazy">
          </div>
        </div>
      </div>

      <div class="fd-community-logos">
        <h3>ช่วยเหลือมูลนิธิเด็กเพื่อสังคม</h3>
        <div class="fd-community-logo-grid">
          <div class="fd-community-logo-card">
            <img src="img/logo%20เด็กสายรุ้ง.png" alt="โลโก้เด็กสายรุ้ง" class="fd-community-logo-card__img" width="400" height="200" decoding="async" loading="lazy">
          </div>
          <div class="fd-community-logo-card">
            <img src="img/logo%20ทานตะวัน.png" alt="โลโก้ทานตะวัน" class="fd-community-logo-card__img" width="400" height="200" decoding="async" loading="lazy">
          </div>
          <div class="fd-community-logo-card">
            <img src="img/logo%20พร.png" alt="โลโก้พร" class="fd-community-logo-card__img" width="400" height="200" decoding="async" loading="lazy">
          </div>
        </div>
      </div>
    </section>

    <?php endif; ?>
  </div>

  <?php if (($_SESSION['role'] ?? '') !== 'foundation'): ?>
  <div class="footer-wrap page-section" style="background-color:#3f4f9a;">
  <?php include __DIR__ . '/includes/site_footer.php'; ?>
  </div>
  <?php endif; ?>

  <script>
  window.FOUNDATION_PAGE = {
    interestCarousel: <?= (($_SESSION['role'] ?? '') !== 'foundation' && count($interestFoundations) > 3) ? 'true' : 'false' ?>,
    needSlides: <?= (($_SESSION['role'] ?? '') !== 'foundation' && $hasAnySlides) ? 'true' : 'false' ?>,
    foundationManage: <?= (($_SESSION['role'] ?? '') === 'foundation') ? 'true' : 'false' ?>
  };
  </script>
  <script src="js/foundation_page.js?v=1" defer></script>
  <?php if ($justRegistered || ($foundationPageMsg !== '' && $showFoundationOnboarding)): ?>
  <?php
  require_once __DIR__ . '/includes/vendor_assets.php';
  echo drawdream_sweetalert2_js_tag('', false) . "\n";
  ?>
  <script src="js/drawdream-swal.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    <?php if ($justRegistered): ?>
    drawdreamAlert(<?= json_encode($foundationPageMsg !== '' ? $foundationPageMsg : 'สมัครสมาชิกสำเร็จ — บัญชีมูลนิธิรอแอดมินตรวจสอบก่อนใช้งานเต็มรูปแบบ', JSON_UNESCAPED_UNICODE) ?>, 'success');
    <?php elseif ($foundationPageMsg !== ''): ?>
    drawdreamAlert(<?= json_encode($foundationPageMsg, JSON_UNESCAPED_UNICODE) ?>, 'info');
    <?php endif; ?>
  });
  </script>
  <?php endif; ?>

</body>
</html>