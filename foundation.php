<?php
// foundation.php — หน้ามูลนิธิ + รายการสิ่งของ (สาธารณะ/จัดการ)

if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
require_once __DIR__ . '/includes/needlist_donate_window.php';

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

$is_verified = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && isset($_SESSION['account_verified']) && $_SESSION['account_verified'] == 1);

// ถ้า foundation role ให้ดึงเฉพาะมูลนิธิของตัวเอง ถ้าไม่ใช่ให้ดึงทั้งหมด
if (($_SESSION['role'] ?? '') === 'foundation') {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile WHERE user_id = $userId ORDER BY foundation_id DESC");
} else {
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile ORDER BY foundation_id DESC");
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
$q2 = mysqli_query($conn, "SELECT foundation_id, COALESCE(SUM(total_price),0) AS goal FROM foundation_needlist WHERE $needOpenPub GROUP BY foundation_id");
if ($q2) while ($r = mysqli_fetch_assoc($q2)) $goalTotals[(int)$r['foundation_id']] = (float)$r['goal'];

/* ดึงรายการอนุมัติเพียงพอสำหรับสไลด์ — LIMIT 3 เดิมทำให้แถวที่มีรูปถูกตัดออก */
$stmtAll = $conn->prepare("SELECT item_id, item_name, qty_needed, price_estimate, urgent, item_image, item_image_2, item_image_3, need_foundation_image FROM foundation_needlist WHERE foundation_id=? AND $needOpenPub ORDER BY urgent DESC, item_id DESC LIMIT 120");
if (!$stmtAll) die("Prepare failed: " . $conn->error);

// ดึงรายการสิ่งของที่เสนอทั้งหมด (สำหรับ foundation role)
$myNeedlist = [];
if (($_SESSION['role'] ?? '') === 'foundation') {
    // ดึง foundation_id จาก foundation_profile ก่อน
    $rowFp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT foundation_id FROM foundation_profile WHERE user_id = $userId LIMIT 1"));
    $myFoundationId = (int)($rowFp['foundation_id'] ?? 0);

    if ($myFoundationId > 0) {
        $stmtMine = $conn->prepare("
            SELECT item_id, item_name, item_desc, brand, price_estimate, total_price, urgent, item_image, item_image_2, item_image_3, need_foundation_image, approve_item, note, donate_window_end_at, reviewed_at
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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>มูลนิธิ | DrawDream</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/foundation.css?v=27">
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
              <?php if ($is_verified): ?>
                <a href="foundation_add_need.php" class="foundation-manage-btn foundation-manage-btn-primary">+ เสนอสิ่งของมูลนิธิ</a>
              <?php else: ?>
                <span class="foundation-warn">รอการอนุมัติก่อนจึงจะเสนอสิ่งของมูลนิธิได้</span>
              <?php endif; ?>
              <button type="button" id="toggleEditNeedBtn" class="foundation-manage-btn foundation-manage-btn-edit">แก้ไขรายการสิ่งของ</button>
            </div>
          </div>
        </div>

        <div class="my-needlist-section" id="my-needlist-section">
          <h3 class="my-needlist-title" style="font-family:'Prompt',sans-serif;font-size:1.4em;color:#2e3f7f;margin-bottom:18px;">รายการสิ่งของที่เสนอทั้งหมด</h3>
          <?php if (!empty($_GET['need_updated'])): ?>
            <div class="alert alert-success needlist-flash" role="status">อัปเดตรายการสิ่งของแล้ว</div>
          <?php endif; ?>
          <?php if (empty($myNeedlist)): ?>
            <p style="color:#888; font-family:'Sarabun',sans-serif;">ยังไม่มีรายการที่เสนอ หรือยังไม่ถูกบันทึก</p>
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
              // ดึงระยะเวลาจาก note
              $nlNote = $nl['note'] ?? '';
              $nlPeriod = '';
              if (preg_match('/^ระยะเวลา:\s*(.+)/u', $nlNote, $pm)) {
                $nlPeriod = trim($pm[1]);
              }
              $statusLabel = ['pending' => 'รอการอนุมัติ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'][$status] ?? $status;
              $statusClass = ['pending' => 'status-pending', 'approved' => 'status-approved', 'rejected' => 'status-rejected'][$status] ?? 'status-pending';
              $dweRaw = trim((string)($nl['donate_window_end_at'] ?? ''));
              $donateWindowExpired = ($status === 'approved' && $dweRaw !== '' && !str_starts_with($dweRaw, '0000-00-00') && strtotime($dweRaw) !== false && strtotime($dweRaw) < time());
            ?>
            <div class="need-card">
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
                </div>
              </div>
              <div class="need-card-body">
                <span class="need-status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                <?php if ($donateWindowExpired): ?>
                  <span class="need-status-badge status-closed-window" title="ครบระยะเวลารับบริจาคแล้ว">ปิดรับบริจาคแล้ว</span>
                <?php elseif ($status === 'approved' && $dweRaw !== '' && !str_starts_with($dweRaw, '0000-00-00') && strtotime($dweRaw) !== false): ?>
                  <span class="need-window-hint" title="วันปิดรับบริจาค">ปิดรับ <?= htmlspecialchars(date('d/m/Y H:i', strtotime($dweRaw))) ?></span>
                <?php endif; ?>
                <div class="need-card-name"><?= htmlspecialchars($nl['item_name']) ?></div>
                <?php if ($nl['brand']): ?>
                  <div class="need-card-cat"><?= htmlspecialchars($nl['brand']) ?></div>
                <?php endif; ?>
                <div class="need-card-goal">
                  เป้าหมาย: <?= number_format((float)($nl['total_price'] ?: $nl['price_estimate']), 0) ?> บาท
                  <?php if ($nlPeriod): ?><span class="need-period">/<?= htmlspecialchars($nlPeriod) ?></span><?php endif; ?>
                </div>
                <?php if ($nl['item_desc']): ?>
                  <div class="need-card-desc"><?= htmlspecialchars($nl['item_desc']) ?></div>
                <?php endif; ?>
                <div class="need-edit-wrap">
                  <a class="need-card-edit-link" href="foundation_add_need.php?edit=<?= (int)($nl['item_id'] ?? 0) ?>">แก้ไขรายการนี้</a>
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
            <a class="btn-donate" href="payment/foundation_donate.php?fid=<?= $fid ?>">บริจาค</a>
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
      <p class="fd-interest-sub">ข้อมูลจากมูลนิธิที่อยู่ในระบบ</p>
      <div class="fd-interest-grid">
        <?php foreach (array_slice($interestFoundations, 0, 3) as $inf):
          $ifid = (int)$inf['foundation_id'];
          $iImg = trim((string)($inf['foundation_image'] ?? ''));
          $iDesc = (string)($inf['foundation_desc'] ?? '');
          $shortDesc = function_exists('mb_substr')
            ? mb_substr($iDesc, 0, 200)
            : substr($iDesc, 0, 200);
          if (strlen($iDesc) > 200) {
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
            <a class="fd-interest-pill-btn" href="payment/foundation_donate.php?fid=<?= $ifid ?>">ร่วมบริจาค</a>
          </div>
          <div class="fd-interest-body">
            <h3 class="fd-interest-name"><?= htmlspecialchars($inf['foundation_name'] ?? 'มูลนิธิ') ?></h3>
            <p class="fd-interest-desc"><?= nl2br(htmlspecialchars($shortDesc)) ?></p>
          </div>
        </article>
        <?php endforeach; ?>
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