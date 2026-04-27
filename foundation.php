<?php
// foundation.php — หน้ามูลนิธิ + รายการสิ่งของ (สาธารณะ/จัดการ)

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน foundation

if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
require_once __DIR__ . '/includes/needlist_donate_window.php';
require_once __DIR__ . '/includes/utf8_helpers.php';
require_once __DIR__ . '/includes/foundation_account_verified.php';

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

// ถ้าเป็นมูลนิธิ: ดูของตัวเองได้แม้ยังไม่อนุมัติ
// ถ้าเป็นผู้ใช้ทั่วไป/ผู้บริจาค: แสดงเฉพาะมูลนิธิที่อนุมัติแล้วเท่านั้น
if (($_SESSION['role'] ?? '') === 'foundation') {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile WHERE user_id = $userId ORDER BY foundation_id DESC");
} else {
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile WHERE account_verified = 1 ORDER BY foundation_id DESC");
}
if (!$foundations) die("Query foundations failed: " . mysqli_error($conn));

// ✅ ยอดบริจาค/เป้าหมายเฉพาะรายการที่ยังเปิดรับบริจาคตามระยะเวลา
$needOpenPub = drawdream_needlist_sql_open_for_donation();
$donationTotals = [];
$q = mysqli_query($conn, "
    SELECT foundation_id, COALESCE(SUM(current_donate), 0) AS total
    FROM foundation_needlist
    WHERE $needOpenPub
    GROUP BY foundation_id
");
if ($q) while ($r = mysqli_fetch_assoc($q)) $donationTotals[(int)$r['foundation_id']] = (float)$r['total'];

$goalTotals = [];
$q2 = mysqli_query($conn, "
    SELECT
        foundation_id,
        COALESCE(SUM(COALESCE(total_price, 0)), 0) AS goal
    FROM foundation_needlist
    WHERE $needOpenPub
    GROUP BY foundation_id
");
if ($q2) while ($r = mysqli_fetch_assoc($q2)) $goalTotals[(int)$r['foundation_id']] = (float)$r['goal'];

/* ดึงรายการอนุมัติเพียงพอสำหรับสไลด์ — LIMIT 3 เดิมทำให้แถวที่มีรูปถูกตัดออก */
$stmtAll = $conn->prepare("SELECT item_id, item_name, qty_needed, urgent, item_image, item_image_2, item_image_3, need_foundation_image FROM foundation_needlist WHERE foundation_id=? AND $needOpenPub ORDER BY urgent DESC, item_id DESC LIMIT 120");
if (!$stmtAll) die("Prepare failed: " . $conn->error);

// ดึงรายการสิ่งของที่เสนอทั้งหมด (สำหรับ foundation role)
$myNeedlist = [];
$myFoundationId = 0;
$myNeedlistGoalMet = false;
$myNeedProposeBlock = ['blocked' => false, 'reason' => '', 'donate_end_at' => null];
if (($_SESSION['role'] ?? '') === 'foundation') {
    // ดึง foundation_id จาก foundation_profile ก่อน
    $rowFp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT foundation_id FROM foundation_profile WHERE user_id = $userId LIMIT 1"));
    $myFoundationId = (int)($rowFp['foundation_id'] ?? 0);

    if ($myFoundationId > 0) {
        $mc = $donationTotals[$myFoundationId] ?? 0;
        $mg = $goalTotals[$myFoundationId] ?? 0;
        $myNeedlistGoalMet = $mg > 0 && $mc >= $mg;

        $myNeedProposeBlock = drawdream_foundation_needlist_propose_blocked($conn, $myFoundationId);
    }

    if ($myFoundationId > 0) {
        $stmtMine = $conn->prepare("
            SELECT item_id, item_name, desired_brand, brand, total_price, urgent, item_image, item_image_2, item_image_3, need_foundation_image, approve_item, note, donate_window_end_at, reviewed_at
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

$foundationRows = [];
if ($foundations && mysqli_num_rows($foundations) > 0) {
    while ($row = mysqli_fetch_assoc($foundations)) {
        $foundationRows[] = $row;
    }
}

$foundationSlides = [];
$interestFoundations = [];
foreach ($foundationRows as $f) {
    $fid = (int)$f['foundation_id'];
    if (trim((string)($f['foundation_name'] ?? '')) !== '') {
        $interestFoundations[] = $f;
    }

    $stmtAll->bind_param('i', $fid);
    $stmtAll->execute();
    $res = $stmtAll->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    if (count($items) === 0) {
        continue;
    }

    $current = $donationTotals[$fid] ?? 0;
    $goal = $goalTotals[$fid] ?? 0;
    $percent = ($goal > 0) ? min(100, round(($current / $goal) * 100, 2)) : 0;

    $foundationSlides[] = [
        'f' => $f,
        'items' => $items,
        'fid' => $fid,
        'current' => $current,
        'goal' => $goal,
        'percent' => $percent,
    ];
}
$hasAnySlides = !empty($foundationSlides);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>มูลนิธิ | DrawDream</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/foundation.css?v=36">
</head>
<body class="foundation-page">

  <?php include 'navbar.php'; ?>

  <div class="page-wrap">

    <?php if (($_SESSION['role'] ?? '') === 'foundation'): ?>
      <div class="foundation-view-wrap">
        <div class="foundation-view-head">
          <h1>มูลนิธิของเรา</h1>
          <p>จัดการรายการสิ่งของที่ต้องการได้จากหน้านี้</p>
          <div class="foundation-view-toolbar">
            <div class="foundation-view-actions">
              <?php if ($is_verified && empty($myNeedProposeBlock['blocked'])): ?>
                <a href="foundation_add_need.php" class="foundation-manage-btn foundation-manage-btn-primary">+ เสนอสิ่งของมูลนิธิ</a>
              <?php elseif ($is_verified): ?>
                <span class="foundation-manage-btn foundation-manage-btn-disabled" aria-disabled="true" title="<?= htmlspecialchars($myNeedProposeBlockTitle, ENT_QUOTES, 'UTF-8') ?>">+ เสนอสิ่งของมูลนิธิ</span>
              <?php else: ?>
                <span class="foundation-warn">รอการอนุมัติก่อนจึงจะเสนอสิ่งของมูลนิธิได้</span>
              <?php endif; ?>
              <button type="button" id="toggleEditNeedBtn" class="foundation-manage-btn foundation-manage-btn-edit">แก้ไขรายการสิ่งของ</button>
              <?php if ($is_verified && $myNeedlistGoalMet): ?>
                <a href="foundation_post_needlist_result.php" class="foundation-manage-btn foundation-manage-btn-update">อัปเดตผลลัพธ์สิ่งของ</a>
              <?php elseif ($is_verified): ?>
                <span class="foundation-manage-btn foundation-manage-btn-disabled" aria-disabled="true" title="เมื่อยอดรวมรายการสิ่งของครบเป้าหมายแล้ว จึงจะอัปเดตผลลัพธ์ได้">อัปเดตผลลัพธ์สิ่งของ</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="my-needlist-section" id="my-needlist-section">
          <h3 class="my-needlist-title" style="font-family:'Prompt',sans-serif;font-size:1.4em;color:#2e3f7f;margin-bottom:18px;">รายการสิ่งของที่เสนอทั้งหมด</h3>
          <p class="needlist-cycle-hint">ระบบปิดรับบริจาคอัตโนมัติเมื่อครบ 1 เดือนนับจากวันที่แอดมินอนุมัติรายการ และจึงจะเสนอรอบใหม่ได้</p>
          <?php if (!empty($_GET['need_created'])): ?>
            <div class="alert alert-success needlist-flash" role="status">เสนอรายการสิ่งของสำเร็จ รอแอดมินอนุมัติ</div>
          <?php endif; ?>
          <?php if (!empty($_GET['need_updated'])): ?>
            <div class="alert alert-success needlist-flash" role="status">อัปเดตรายการสิ่งของแล้ว</div>
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
              $nlImgItem = $nlImages[0] ?? '';
              $nlFdn = trim((string)($nl['need_foundation_image'] ?? ''));
              /* หน้ามูลนิธิ: โชว์เฉพาะรูปมูลนิธิถ้ามี ไม่แบ่งคู่กับรูปสิ่งของ */
              $nlImg = $nlFdn !== '' ? $nlFdn : $nlImgItem;
              $statusLabel = ['pending' => 'รอการอนุมัติ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'][$status] ?? $status;
              /* คลาสสอดคล้องกับ .foundation-status-pill ในโครงการ (project.css) */
              $statusPillClass = ['pending' => 'st-pending', 'approved' => 'st-approved', 'rejected' => 'st-rejected'][$status] ?? 'st-pending';
              $dweRaw = trim((string)($nl['donate_window_end_at'] ?? ''));
              $donateWindowExpired = ($status === 'approved' && $dweRaw !== '' && !str_starts_with($dweRaw, '0000-00-00') && strtotime($dweRaw) !== false && strtotime($dweRaw) < time());
            ?>
            <?php
              $cardGoal = (float)($nl['total_price'] ?? 0);
            ?>
            <div class="need-card">
              <a class="need-card-tap-link" href="foundation_need_view.php?id=<?= (int)($nl['item_id'] ?? 0) ?>" aria-label="ดูรายละเอียดรายการสิ่งของ"></a>
              <div class="need-card-img-wrap">
                <div class="need-card-img-primary">
                  <?php if ($nlImg): ?>
                    <img src="uploads/needs/<?= htmlspecialchars($nlImg) ?>" alt="" class="need-card-img">
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
                <?php if ($nl['brand']): ?>
                  <div class="need-card-cat"><?= htmlspecialchars($nl['brand']) ?></div>
                <?php endif; ?>
                <div class="need-card-goal">
                  เป้าหมาย: <?= number_format($cardGoal, 0) ?> บาท
                  <span class="need-period">/ รอบละ 1 เดือน</span>
                </div>
                <?php if (trim((string)($nl['desired_brand'] ?? '')) !== ''): ?>
                  <div class="need-card-desc">แบรนด์ที่ต้องการ: <?= htmlspecialchars((string)$nl['desired_brand']) ?></div>
                <?php endif; ?>
                <div class="need-edit-wrap">
                  <a class="need-card-edit-link" href="foundation_add_need.php?edit=<?= (int)($nl['item_id'] ?? 0) ?>" onclick="event.stopPropagation();">แก้ไขรายการนี้</a>
                </div>
              </div>
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
    <section class="foundation-hero-carousel" data-interval="10000" aria-roledescription="carousel" aria-label="มูลนิธิและความต้องการ">
      <div class="foundation-hero-track">
        <?php foreach ($foundationSlides as $idx => $slide):
          $f = $slide['f'];
          $items = $slide['items'];
          $fid = $slide['fid'];
          $current = $slide['current'];
          $goal = $slide['goal'];
          $percent = $slide['percent'];
          $needGoalMet = $goal > 0 && $current >= $goal;
          $foundationImage = $f['foundation_image'] ?? '';
          $facebookUrl = $f['facebook_url'] ?? '';
          $heroProposalImage = '';
          foreach ($items as $itHero) {
            $nfHero = trim((string)($itHero['need_foundation_image'] ?? ''));
            if ($nfHero !== '') {
              $heroProposalImage = $nfHero;
              break;
            }
          }
          /* ถ้าไม่มีรูปประกอบจากมูลนิธิ ให้ใช้รูปสิ่งของใบแรกที่มี (ไม่บังคับติ๊กด่วน) */
          if ($heroProposalImage === '') {
            foreach ($items as $itHero) {
              $needImgs = foundation_needlist_item_filenames_from_row($itHero);
              foreach ($needImgs as $bn) {
                if ($bn !== '' && $bn !== '.' && $bn !== '..') {
                  $heroProposalImage = $bn;
                  break 2;
                }
              }
            }
          }
          /* แสดงรูปสิ่งของด้านซ้ายสูงสุด 3 รายการ — ทั้งด่วนและไม่ด่วน (เรียงตาม query: urgent ก่อน) */
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
        <article class="foundation-card foundation-slide<?= $idx === 0 ? ' is-active' : '' ?>" id="f<?= $fid ?>" data-slide-index="<?= (int)$idx ?>" aria-hidden="<?= $idx === 0 ? 'false' : 'true' ?>">
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
              <div class="items urgent-items fc-urgent-items-grid fc-needlist-showcase-grid" aria-label="ภาพรายการสิ่งของ">
                <?php foreach ($itemShowcaseEntries as $showEnt):
                  $oneImg = (string)($showEnt['file'] ?? '');
                  $showUrgent = !empty($showEnt['urgent']);
                ?>
                <div class="item urgent-item-card">
                  <?php if ($showUrgent): ?>
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
              <?php endif; ?>
            </div>
            <?php if ($needGoalMet): ?>
              <a class="btn-donate" href="needlist_result.php?fid=<?= $fid ?>">ผลลัพธ์ของมูลนิธิ</a>
            <?php else: ?>
              <a class="btn-donate" href="payment/foundation_donate.php?fid=<?= $fid ?>">บริจาค</a>
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
              <?php if (!empty($facebookUrl)): ?>
                <div class="fb">Facebook: <?= htmlspecialchars($facebookUrl) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php if (count($foundationSlides) > 1): ?>
      <div class="foundation-hero-dots" role="tablist" aria-label="เลือกมูลนิธิ">
        <?php foreach ($foundationSlides as $idx => $_): ?>
        <button type="button" class="foundation-hero-dot<?= $idx === 0 ? ' is-active' : '' ?>" data-go="<?= (int)$idx ?>" role="tab" aria-selected="<?= $idx === 0 ? 'true' : 'false' ?>" aria-label="สไลด์ <?= (int)$idx + 1 ?>"></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
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
            <img src="<?= htmlspecialchars(drawdream_community_img('logo-santisuk')) ?>" alt="มูลนิธิสันติสุข" class="fd-community-logo-card__img" width="280" height="140" decoding="async" loading="lazy">
          </div>
          <div class="fd-community-logo-card">
            <img src="<?= htmlspecialchars(drawdream_community_img('logo-baannok')) ?>" alt="มูลนิธิบ้านนอก" class="fd-community-logo-card__img" width="280" height="140" decoding="async" loading="lazy">
          </div>
          <div class="fd-community-logo-card">
            <img src="<?= htmlspecialchars(drawdream_community_img('logo-holt')) ?>" alt="มูลนิธิฮอลต์สหทัย" class="fd-community-logo-card__img" width="280" height="140" decoding="async" loading="lazy">
          </div>
          <div class="fd-community-logo-card">
            <img src="<?= htmlspecialchars(drawdream_community_img('logo-hope')) ?>" alt="มูลนิธิเฮาส์ออฟเบลสซิง" class="fd-community-logo-card__img" width="280" height="140" decoding="async" loading="lazy">
          </div>
        </div>
      </div>
    </section>

    <?php endif; ?>
  </div>

  <?php if (($_SESSION['role'] ?? '') !== 'foundation'): ?>
  <?php include __DIR__ . '/includes/site_footer.php'; ?>
  <?php endif; ?>

  <?php if (($_SESSION['role'] ?? '') !== 'foundation' && count($interestFoundations) > 3): ?>
  <script>
  (function () {
    var carousel = document.querySelector('.fd-interest-carousel');
    if (!carousel) return;
    var viewport = carousel.querySelector('[data-interest-viewport]');
    var prevBtn = carousel.querySelector('[data-interest-prev]');
    var nextBtn = carousel.querySelector('[data-interest-next]');
    if (!viewport || !prevBtn || !nextBtn) return;

    function getStep() {
      var card = viewport.querySelector('.fd-interest-card');
      if (!card) return 320;
      var styles = window.getComputedStyle(viewport.querySelector('.fd-interest-grid'));
      var gap = parseFloat(styles.columnGap || styles.gap || '24') || 24;
      return card.getBoundingClientRect().width + gap;
    }

    function syncButtons() {
      var maxLeft = viewport.scrollWidth - viewport.clientWidth - 2;
      prevBtn.disabled = viewport.scrollLeft <= 2;
      nextBtn.disabled = viewport.scrollLeft >= maxLeft;
    }

    prevBtn.addEventListener('click', function () {
      viewport.scrollBy({ left: -getStep(), behavior: 'smooth' });
    });
    nextBtn.addEventListener('click', function () {
      viewport.scrollBy({ left: getStep(), behavior: 'smooth' });
    });
    viewport.addEventListener('scroll', syncButtons, { passive: true });
    window.addEventListener('resize', syncButtons);
    syncButtons();
  })();
  </script>
  <?php endif; ?>

  <?php if (($_SESSION['role'] ?? '') !== 'foundation' && $hasAnySlides && count($foundationSlides) > 1): ?>
  <script>
  (function() {
    var root = document.querySelector('.foundation-hero-carousel');
    if (!root) return;
    var slides = [].slice.call(root.querySelectorAll('.foundation-slide'));
    var dots = [].slice.call(root.querySelectorAll('.foundation-hero-dot'));
    var n = slides.length;
    if (n <= 1) return;
    var i = 0;
    var ms = parseInt(root.getAttribute('data-interval') || '10000', 10);
    function go(to) {
      i = ((to % n) + n) % n;
      slides.forEach(function(s, j) {
        var on = j === i;
        s.classList.toggle('is-active', on);
        s.setAttribute('aria-hidden', on ? 'false' : 'true');
      });
      dots.forEach(function(d, j) {
        var on = j === i;
        d.classList.toggle('is-active', on);
        d.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }
    setInterval(function() { go(i + 1); }, ms);
    dots.forEach(function(d) {
      d.addEventListener('click', function() {
        go(parseInt(d.getAttribute('data-go') || '0', 10));
      });
    });

    // ถ้ามี hash เช่น #f12 ให้เปิดสไลด์ของมูลนิธินั้นทันที
    var hash = window.location.hash || '';
    if (hash && hash.indexOf('#f') === 0) {
      var target = document.querySelector(hash);
      if (target && target.classList.contains('foundation-slide')) {
        var idx = parseInt(target.getAttribute('data-slide-index') || '0', 10) || 0;
        go(idx);
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
  })();
  </script>
  <?php endif; ?>

  <?php if (($_SESSION['role'] ?? '') === 'foundation'): ?>
  <script>
  (function() {
    var btn = document.getElementById('toggleEditNeedBtn');
    var section = document.getElementById('my-needlist-section');
    function setNeedEditMode(on) {
      document.body.classList.toggle('mode-edit-need', on);
      if (btn) btn.classList.toggle('btn-mode-active', on);
    }
    if (btn && section) {
      btn.addEventListener('click', function() {
        var turnOn = !document.body.classList.contains('mode-edit-need');
        setNeedEditMode(turnOn);
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }
  })();
  </script>
  <?php endif; ?>

</body>
</html>