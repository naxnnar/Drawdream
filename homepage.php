<?php
// homepage.php — หน้าแรก
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน homepage
$homeFlashMsg = isset($_GET['msg']) ? trim((string) $_GET['msg']) : '';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/homepage_impact_stats.php';
$homeImpactStats = drawdream_homepage_impact_stats($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <title>หน้าหลัก | DrawDream</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/homepage.css?v=2">
</head>
<body>

<?php include 'navbar.php'; ?>

<?php if ($homeFlashMsg !== ''): ?>
<div class="alert alert-warning text-center mb-0 rounded-0 border-0 py-3" role="alert" style="border-radius:0;"><?= htmlspecialchars($homeFlashMsg, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- ===== SECTION 1: HERO ===== -->
<div class="home-section home-hero">
  <div class="container">
    <div class="row align-items-center g-4 g-xl-5">

      <div class="col-md-5 d-flex hero-image-col">
        <div class="portrait-wrapper">
          <img src="img/childd.png" alt="DrawDream" class="portrait-img">
        </div>
      </div>

      <div class="col-md-7 hero-text-col">
        <div class="center-section home-hero-copy">
          <img src="img/โลโก้.png" alt="DrawDream" class="home-hero__brand-logo">
          <h2 class="home-hero__title">แพลตฟอร์มระดมทุนดิจิทัลเพื่อสังคม</h2>
          <p class="home-hero__intro">
            ที่มุ่งสร้างความโปร่งใสและสร้างความเชื่อมั่นให้แก่ผู้บริจาค เราทำหน้าที่เป็นตัวกลางเชื่อมโยงผู้ให้เข้ากับมูลนิธิเด็กและมูลนิธิเพื่อสังคมโดยตรง
            เพื่อเปลี่ยนความฝันด้านโอกาสและการศึกษาของเด็กๆ ให้กลายเป็นความจริงที่ตรวจสอบได้ในทุกขั้นตอน
          </p>
          <div class="hero-actions mt-4">
            <a href="children_.php" class="btn btn-hero">อุปการะ</a>
            <a href="project.php" class="btn btn-hero">โครงการ</a>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===== 3 ช่องทางการให้ ===== -->
<section class="home-platform" aria-labelledby="home-platform-channels-heading">
  <div class="container home-platform__channels">
    <h3 id="home-platform-channels-heading" class="home-platform__channels-title text-center">ส่งต่อโอกาสอย่างมั่นใจ<br>เลือกรูปแบบการให้ที่ตรงใจคุณ</h3>
    <div class="row g-4">
      <div class="col-lg-4">
        <article class="home-channel home-channel--coral">
          <a class="home-channel-hit" href="children_.php" aria-label="อุปการะเด็ก"></a>
          <div class="home-channel__media home-channel__media--sponsor" style="background-image:url('img/อุปการะเด็ก.png');">
            <div class="home-channel__media-overlay home-channel__media-overlay--sponsor">
              <span class="home-channel__icon" aria-hidden="true"><i class="bi bi-person-hearts"></i></span>
              <h4 class="home-channel__name">อุปการะเด็ก</h4>
              <p class="home-channel__en">Child Sponsorship</p>
            </div>
          </div>
          <div class="home-channel__body">
            <p>ร่วมมอบโอกาสที่ต่อเนื่องเพื่อพลิกอนาคตของเด็กๆ ผ่านระบบสนับสนุนรายเดือน (Subscription) เพื่อเป็นทุนการศึกษาและพัฒนาคุณภาพชีวิตอย่างยั่งยืน โดยระบบจะบริหารจัดการข้อมูลอย่างปลอดภัยตามหลักนโยบายคุ้มครองเด็ก พร้อมส่งรายงานการเติบโตของเด็กให้คุณติดตามได้อย่างต่อเนื่อง</p>
            <a href="children_.php" class="home-channel__btn">อุปการะ</a>
          </div>
        </article>
      </div>
      <div class="col-lg-4">
        <article class="home-channel home-channel--green">
          <a class="home-channel-hit" href="project.php" aria-label="บริจาคเข้าโครงการ"></a>
          <div class="home-channel__media home-channel__media--project" style="background-image:url('img/โครงการเด็ก.png');">
            <div class="home-channel__media-overlay home-channel__media-overlay--project">
              <span class="home-channel__icon" aria-hidden="true"><i class="bi bi-flag-fill"></i></span>
              <h4 class="home-channel__name">บริจาคเข้าโครงการ</h4>
              <p class="home-channel__en">Project Crowdfunding</p>
            </div>
          </div>
          <div class="home-channel__body">
            <p>สนับสนุนโครงการพัฒนาสังคมและสวัสดิการเด็กที่มีเป้าหมายและระยะเวลาชัดเจน คุณสามารถเลือกบริจาคให้แก่โครงการที่ตรงกับความสนใจ โดยระบบจะรวบรวมเงินทุนส่งตรงถึงมูลนิธิเจ้าของโครงการเพื่อนำไปดำเนินงานให้สำเร็จตามเป้าหมายที่วางไว้</p>
            <a href="project.php" class="home-channel__btn">บริจาคโครงการ</a>
          </div>
        </article>
      </div>
      <div class="col-lg-4">
        <article class="home-channel home-channel--yellow">
          <a class="home-channel-hit" href="foundation.php" aria-label="สมทบทุนจัดซื้อสิ่งของ"></a>
          <div class="home-channel__media home-channel__media--need" style="background-image:url('img/สิ่งของเด็ก.png');">
            <div class="home-channel__media-overlay home-channel__media-overlay--need">
              <span class="home-channel__icon" aria-hidden="true"><i class="bi bi-box-seam-fill"></i></span>
              <h4 class="home-channel__name">สมทบทุนจัดซื้อสิ่งของ</h4>
              <p class="home-channel__en">In-kind Funding</p>
            </div>
          </div>
          <div class="home-channel__body">
            <p>เปลี่ยนเงินบริจาคเป็นสิ่งของที่มูลนิธิต้องการอย่างแท้จริง เช่น อุปกรณ์การเรียน นม หรือของใช้จำเป็นสำหรับเด็ก โดยผู้บริจาคจะโอนเงินเข้าสมทบทุนในโครงการ จากนั้นแพลตฟอร์มจะทำหน้าที่เป็นตัวกลางในการจัดซื้อและจัดส่งสิ่งของทั้งหมดให้ถึงมือมูลนิธิ เพื่อให้กระบวนการโปร่งใสและผู้บริจาคยังคงได้รับสิทธิ์ลดหย่อนภาษีจากการบริจาคเงิน</p>
            <a href="foundation.php" class="home-channel__btn">สมทบทุน</a>
          </div>
        </article>
      </div>
    </div>
  </div>
</section>

<!-- ===== SECTION 3: STORIES ===== -->
<div class="home-section stories" id="stories">
  <div class="container">

    <!-- น้องอลิน — แถวบน: รูปซ้าย, text ขวา -->
    <div class="row align-items-center mb-5">
      <div class="col-md-6">
        <img src="img/alin.png" alt="น้องอลิน" class="img-fluid rounded shadow">
      </div>
      <div class="col-md-6 ps-md-5">
        <h3 class="mt-4 mt-md-0">น้องอลิน</h3>
        <p class="story-text">
          น้องอลินเป็นเด็กที่รักการอ่าน แต่หนังสือในห้องสมุดที่มีมักเป็นเล่มเก่าและไม่เพียงพอต่อใจรักการอ่านของเธอ
          ผ่านโครงการ "บริจาคแบบรวมของทุนการศึกษา" ผู้บริจาคได้ร่วมกันมอบทุนการศึกษาและจัดซื้อชุดหนังสือวรรณกรรมเยาวชนและหนังสือนิทานใหม่
          วันนี้น้องอลินไม่ได้มีเพียงหนังสืออ่านนอกจากการอ่านแล้วน้องอลินมักชอบเล่าเรื่องที่เธออ่านให้เพื่อนๆฟัง
          เธอกลายเป็นตัวแทนโรงเรียนไปแข่งขันทักษะทางภาษาไทยจนได้รับรางวัล
        </p>
        <a href="detail_alin.php" class="btn btn-light btn-home">อ่านต่อ</a>
      </div>
    </div>

    <!-- น้องพิณเพลง + น้องแซน — แถวล่าง: 2 คอลัมน์ -->
    <div class="row g-4">
      <div class="col-md-6 d-flex flex-column align-items-center" style="justify-content:flex-start;">
        <img src="img/pin.png" alt="น้องพิณเพลง" class="img-fluid rounded shadow mb-3" style="object-fit:cover; object-position:top; align-self:flex-start;">
        <h3>น้องพิณเพลง</h3>
        <p class="card-text">
          น้องพิณเพลงเป็นเด็กขี้อายที่มีพรสวรรค์ด้านเสียงร้อง แต่เธอไม่เคยมีโอกาสได้เรียนรู้ทักษะดนตรีอย่างจริงจัง
          ผู้บริจาครายหนึ่งได้เห็นโปรไฟล์ของน้องและตัดสินใจสนับสนุนผ่านฟีเจอร์ "บริจาครายบุคคล"
          ปัจจุบันเสียงเพลงของน้องพิณเพลงไม่ได้ก้องกังวานแค่ในมูลนิธิอีกต่อไป เธอได้รับโอกาสขึ้นแสดงในงานโรงเรียน
        </p>
        <a href="detail_pin.php" class="btn btn-light btn-home">อ่านต่อ</a>
      </div>
      <div class="col-md-6 d-flex flex-column align-items-center">
        <img src="img/san.png" alt="น้องแซน" class="img-fluid rounded shadow mb-3" style="object-fit:cover; object-position:top; align-self:flex-start;">
        <h3>น้องแซน</h3>
        <p class="card-text">
          น้องแซนมักจะใช้ชอบระบายสีเสมอเมื่อมีกิจกรรมเล่นเวลาว่าง เขาจะใช้ดินสอแท่งเดิมจนเหลือแท่งสั้นๆ วาดรูปบนกระดาษ
          รูปที่เขาชอบวาดที่สุดคือ "บ้านที่มีความสุข" น้องได้รับรางวัลชนะเลิศการประกวดวาดภาพระดับท้องถิ่น
          อุปกรณ์ศิลปะเหล่านั้นไม่ได้แค่ใช้ระบายสีลงบนกระดาษ แต่กำลังช่วยระบายความหวังและอนาคตที่สวยงาม
        </p>
        <a href="detail_san.php" class="btn btn-light btn-home">อ่านต่อ</a>
      </div>
    </div>

  </div>
</div>

<!-- ===== ผลกระทบจากข้อมูลจริง (ต่อท้ายเรื่องราว) ===== -->
<section class="home-impact home-impact--after-stories page-section" aria-labelledby="home-impact-heading">
  <div class="container">
    <h2 id="home-impact-heading" class="home-impact__title text-center fw-bold">มาสร้างความเปลี่ยนแปลงไปด้วยกัน</h2>
    <p class="home-impact__lead text-center">ตัวเลขจากการให้และการส่งมอบจริงบนแพลตฟอร์ม DrawDream</p>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-4 home-impact__stats justify-content-center">
      <div class="col">
        <div class="home-impact__card text-center">
          <span class="home-impact__card-icon home-impact__card-icon--coral" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
          <p class="home-impact__label mb-2">ยอดบริจาคทั้งหมด</p>
          <p class="home-impact__value mb-0">
            <span class="home-impact__number"><?= htmlspecialchars(drawdream_homepage_impact_stat_display_baht($homeImpactStats['total_donation_baht']), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="home-impact__unit">บาท</span>
          </p>
        </div>
      </div>
      <div class="col">
        <div class="home-impact__card text-center">
          <span class="home-impact__card-icon home-impact__card-icon--green" aria-hidden="true"><i class="bi bi-people-fill"></i></span>
          <p class="home-impact__label mb-2">จำนวนผู้บริจาคทั้งหมด</p>
          <p class="home-impact__value mb-0">
            <span class="home-impact__number"><?= htmlspecialchars(drawdream_homepage_impact_stat_display($homeImpactStats['total_donors']), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="home-impact__unit">คน</span>
          </p>
        </div>
      </div>
      <div class="col">
        <div class="home-impact__card text-center">
          <span class="home-impact__card-icon home-impact__card-icon--coral" aria-hidden="true"><i class="bi bi-person-hearts"></i></span>
          <p class="home-impact__label mb-2">เด็กที่มีผู้อุปการะแล้ว</p>
          <p class="home-impact__value mb-0">
            <span class="home-impact__number"><?= htmlspecialchars(drawdream_homepage_impact_stat_display($homeImpactStats['children_sponsored']), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="home-impact__unit">คน</span>
          </p>
        </div>
      </div>
      <div class="col">
        <div class="home-impact__card text-center">
          <span class="home-impact__card-icon home-impact__card-icon--green" aria-hidden="true"><i class="bi bi-flag-fill"></i></span>
          <p class="home-impact__label mb-2">โครงการที่ระดมทุนสำเร็จ</p>
          <p class="home-impact__value mb-0">
            <span class="home-impact__number"><?= htmlspecialchars(drawdream_homepage_impact_stat_display($homeImpactStats['projects_completed']), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="home-impact__unit">โครงการ</span>
          </p>
        </div>
      </div>
      <div class="col">
        <div class="home-impact__card text-center">
          <span class="home-impact__card-icon home-impact__card-icon--yellow" aria-hidden="true"><i class="bi bi-box-seam-fill"></i></span>
          <p class="home-impact__label mb-2">มูลนิธิที่ได้รับสิ่งของแล้ว</p>
          <p class="home-impact__value mb-0">
            <span class="home-impact__number"><?= htmlspecialchars(drawdream_homepage_impact_stat_display($homeImpactStats['foundations_items_received']), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="home-impact__unit">มูลนิธิ</span>
          </p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== SECTION 5: ABOUT DRAWDREAM ===== -->
<div class="home-section home-about-yellow">
  <div class="container py-5">
    <div class="row align-items-center">

      <div class="col-md-6 pe-md-5">
        <h3><b>DrawDream วาดฝันให้เป็นจริง<br>สร้างสังคมที่ดี</b></h3>
        <p class="mt-3">
          เปลี่ยน ความฝัน ให้เป็น ความจริง ร่วมสร้างสังคมที่เด็กทุกคนเติบโตได้อย่างงดงาม
          เราเชื่อว่าสังคมที่ดีกว่าเริ่มต้นที่ โอกาส ของเด็กๆ DrawDream ขอเชิญชวนมูลนิธิเด็กและนักสร้างการเปลี่ยนแปลงมาร่วมเป็นส่วนหนึ่งกับเรา
          เพื่อขยายพลังแห่งการให้ผ่านแพลตฟอร์มที่เข้าใจคุณ ด้วยฟีเจอร์ที่ช่วยสื่อสาร ความต้องการที่แท้จริง ของมูลนิธิสู่ใจผู้บริจาคโดยตรง
        </p>
      </div>

      <div class="col-md-6 text-center">
        <img src="img/ball.png" alt="DrawDream children" class="img-fluid">
      </div>

    </div>
  </div>
</div>

<!-- ===== FOOTER ===== -->
<div class="home-section" style="background-color: #3f4f9a;">
<?php include __DIR__ . '/includes/site_footer.php'; ?>
</div>

</body>
</html>