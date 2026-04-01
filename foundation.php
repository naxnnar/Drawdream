<?php
// ไฟล์นี้: foundation.php
// หน้าที่: หน้ารายการมูลนิธิและรายการสิ่งของที่ต้องการ
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

$is_verified = (isset($_SESSION['role']) && $_SESSION['role'] === 'foundation' && isset($_SESSION['account_verified']) && $_SESSION['account_verified'] == 1);

// ถ้า foundation role ให้ดึงเฉพาะมูลนิธิของตัวเอง ถ้าไม่ใช่ให้ดึงทั้งหมด
if (($_SESSION['role'] ?? '') === 'foundation') {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile WHERE user_id = $userId ORDER BY foundation_id DESC");
} else {
    $foundations = mysqli_query($conn, "SELECT * FROM foundation_profile ORDER BY foundation_id DESC");
}
if (!$foundations) die("Query foundations failed: " . mysqli_error($conn));

// ✅ แก้แล้ว — ดึงยอด current_donate จาก foundation_needlist แยกตาม foundation_id
$donationTotals = [];
$q = mysqli_query($conn, "
    SELECT foundation_id, COALESCE(SUM(current_donate), 0) AS total
    FROM foundation_needlist
    WHERE approve_item = 'approved'
    GROUP BY foundation_id
");
if ($q) while ($r = mysqli_fetch_assoc($q)) $donationTotals[(int)$r['foundation_id']] = (float)$r['total'];

$goalTotals = [];
$q2 = mysqli_query($conn, "SELECT foundation_id, COALESCE(SUM(total_price),0) AS goal FROM foundation_needlist WHERE approve_item='approved' GROUP BY foundation_id");
if ($q2) while ($r = mysqli_fetch_assoc($q2)) $goalTotals[(int)$r['foundation_id']] = (float)$r['goal'];

$stmtAll = $conn->prepare("SELECT item_id, item_name, qty_needed, price_estimate, urgent, item_image FROM foundation_needlist WHERE foundation_id=? AND approve_item='approved' ORDER BY urgent DESC, item_id DESC LIMIT 3");
if (!$stmtAll) die("Prepare failed: " . $conn->error);

// ดึงรายการสิ่งของที่เสนอทั้งหมด (สำหรับ foundation role)
$myNeedlist = [];
if (($_SESSION['role'] ?? '') === 'foundation') {
    // ดึง foundation_id จาก foundation_profile ก่อน
    $rowFp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT foundation_id FROM foundation_profile WHERE user_id = $userId LIMIT 1"));
    $myFoundationId = (int)($rowFp['foundation_id'] ?? 0);

    if ($myFoundationId > 0) {
        $stmtMine = $conn->prepare("
            SELECT item_id, item_name, item_desc, brand, price_estimate, total_price, urgent, item_image, approve_item, note
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
    if (foundation_profile_complete_public($f) && (int)($f['account_verified'] ?? 0) === 1) {
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
  <link rel="stylesheet" href="css/foundation.css?v=9">
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
                <a href="foundation_add_need.php" class="foundation-manage-btn foundation-manage-btn-primary">เสนอรายการสิ่งของ</a>
              <?php else: ?>
                <span class="foundation-warn">รอการอนุมัติก่อนจึงจะเสนอรายการสิ่งของได้</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="my-needlist-section">
          <h3 class="my-needlist-title" style="font-family:'Prompt',sans-serif;font-size:1.4em;color:#2e3f7f;margin-bottom:18px;">รายการสิ่งของที่เสนอทั้งหมด</h3>
          <?php if (empty($myNeedlist)): ?>
            <p style="color:#888; font-family:'Sarabun',sans-serif;">ยังไม่มีรายการที่เสนอ หรือยังไม่ถูกบันทึก</p>
          <?php endif; ?>
          <div class="my-needlist-grid">
            <?php foreach ($myNeedlist as $nl): ?>
            <?php
              $status = $nl['approve_item'] ?? 'pending';
              $nlImages = array_values(array_filter(explode('|', (string)($nl['item_image'] ?? ''))));
              $nlImg = $nlImages[0] ?? '';
              // ดึงระยะเวลาจาก note
              $nlNote = $nl['note'] ?? '';
              $nlPeriod = '';
              if (preg_match('/^ระยะเวลา:\s*(.+)/u', $nlNote, $pm)) {
                $nlPeriod = trim($pm[1]);
              }
              $statusLabel = ['pending' => 'รอการอนุมัติ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'][$status] ?? $status;
              $statusClass = ['pending' => 'status-pending', 'approved' => 'status-approved', 'rejected' => 'status-rejected'][$status] ?? 'status-pending';
            ?>
            <div class="need-card">
              <div class="need-card-img-wrap">
                <?php if ($nlImg): ?>
                  <img src="uploads/needs/<?= htmlspecialchars($nlImg) ?>" alt="" class="need-card-img">
                <?php else: ?>
                  <div class="need-card-noimg">ไม่มีรูป</div>
                <?php endif; ?>
                <?php if ((int)$nl['urgent'] === 1): ?>
                  <span class="need-urgent-badge">ต้องการด่วน</span>
                <?php endif; ?>
              </div>
              <div class="need-card-body">
                <span class="need-status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
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
          $urgentItems = array_filter($items, static function ($it) {
              return (int)($it['urgent'] ?? 0) === 1;
          });
        ?>
        <article class="foundation-card foundation-slide<?= $idx === 0 ? ' is-active' : '' ?>" id="f<?= $fid ?>" data-slide-index="<?= (int)$idx ?>" aria-hidden="<?= $idx === 0 ? 'false' : 'true' ?>">
          <div class="fc-left">
            <h2 class="fc-title"><?= htmlspecialchars($f['foundation_name'] ?? 'มูลนิธิ') ?></h2>
            <p class="fc-desc"><?= htmlspecialchars($f['foundation_desc'] ?? '') ?></p>
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
              <?php if (count($urgentItems) > 0): ?>
              <div class="items urgent-items">
                <?php foreach ($urgentItems as $it):
                  $itemImages = array_values(array_filter(explode('|', (string)($it['item_image'] ?? ''))));
                  $mainItemImage = $itemImages[0] ?? '';
                ?>
                <div class="item urgent-item-card">
                  <span class="urgent-tag urgent-tag-abs">ต้องการด่วน</span>
                  <?php if ($mainItemImage !== ''): ?>
                    <img class="item-img urgent-img-big" src="uploads/needs/<?= htmlspecialchars($mainItemImage) ?>" alt="">
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
              <?php if (!empty($foundationImage)): ?>
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
      <p class="fd-interest-sub">มูลนิธิที่กรอกข้อมูลโปรไฟล์ครบและผ่านการยืนยันแล้ว</p>
      <div class="fd-interest-grid">
        <?php foreach ($interestFoundations as $inf):
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

    <?php endif; ?>
  </div>

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
  })();
  </script>
  <?php endif; ?>

</body>
</html>