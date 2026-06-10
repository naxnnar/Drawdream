<?php
// about.php — เกี่ยวกับเรา / FAQ
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน about
require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
require_once __DIR__ . '/includes/session_init.php';
drawdream_session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <title>เกี่ยวกับเรา | DrawDream </title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link rel="stylesheet" href="css/navbar.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/about.css">
  <link rel="stylesheet" href="css/about_how_it_works.css">
</head>
<body class="about-page">
<?php include 'navbar.php'; ?>

<!-- ===== HERO ===== -->
<section class="hero-section page-section">
  <div class="container text-center">
    <img src="img/about1.png" alt="" class="hero-decor hero-rainbow">
    <img src="img/about2.png" alt="" class="hero-decor hero-rocket">
    <img src="img/about3.png" alt="" class="hero-decor hero-cloud-left">
    <img src="img/about4.png" alt="" class="hero-decor hero-cloud-right">
    <img src="img/about5.png" alt="" class="hero-decor hero-star-red">
    <img src="img/about6.png" alt="" class="hero-decor hero-star-green">
    <img src="img/about7.png" alt="" class="hero-decor hero-star-yellow">
    <img src="img/about8.png" alt="" class="hero-decor hero-star-blue">
    <span class="hero-shooting-star" aria-hidden="true"></span>

    <div class="hero-copy">
      <div class="hero-brand">
        <img src="img/โลโก้.png" alt="Draw Dream Logo" class="hero-logo">
        <span style="font-size:1.45rem; font-weight:600; color:#333;">เริ่มต้นจากความตั้งใจ</span>
      </div>
      <p class="hero-tagline">มุ่งมั่นสร้างโอกาสให้เด็กในมูลนิธิได้ทำตามความฝัน</p>
      <p class="hero-body">
        ผ่านการสนับสนุนแบบเฉพาะบุคคลที่ตอบโจทย์ความสนใจของเด็กแต่ละคนอย่างแท้จริง
        เราเป็นองค์กรไม่แสวงหาผลกำไรที่ขับเคลื่อนด้วยแนวคิดใหม่ เน้นความโปร่งใสเพื่อให้คุณติดตามผลลัพธ์ได้จริง
      </p>
      <p class="hero-body hero-body--secondary">
        เลือกสนับสนุนได้ตามความตั้งใจ — โครงการเฉพาะ สมทบทุนสิ่งของจำเป็น หรือสนับสนุนมูลนิธิ —
        เพื่อให้ทุกการให้ส่งถึงอย่างเป็นรูปธรรม มาร่วมเป็นครอบครัววาดฝัน
        เปลี่ยนทุกโอกาสให้เป็นก้าวสำคัญที่นำเด็กๆ สู่อนาคตที่เขาวาดไว้ได้อย่างแท้จริง
      </p>
    </div>
  </div>
</section>

<!-- ===== งานของเรา ===== -->
<section class="py-5 page-section work-section" id="our-work">
  <div class="container">
    <h2 class="text-center fw-bold mb-5">คุณค่าและความตั้งใจของเรา</h2>
    <div class="row g-4 justify-content-center">
      <div class="col-md-4">
        <div class="about-work-card about-work-card--yellow text-center h-100">
          <span class="about-work-card__icon about-work-card__icon--yellow" aria-hidden="true">
            <i class="bi-person-hearts"></i>
          </span>
          <h5 class="fw-bold">สิทธิเด็ก</h5>
          <p>เรามุ่งมั่นปกป้องสิทธิเด็กและส่งเสริมการมีส่วนร่วมอย่างสร้างสรรค์ พร้อมเปิดพื้นที่ให้เสียงของเขาได้แสดงความคิดเห็นและฉายภาพความฝันของตนเองได้อย่างเต็มภาคภูมิ และสะท้อนตัวตนผ่านสิ่งที่เขารัก เพื่อให้ทุกความเห็นได้รับการรับฟังอย่างมีความหมาย</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="about-work-card about-work-card--red text-center h-100">
          <span class="about-work-card__icon about-work-card__icon--red" aria-hidden="true">
            <i class="bi-heart-fill"></i>
          </span>
          <h5 class="fw-bold">คุณค่าที่สัมผัสได้</h5>
          <p>เราเชื่อว่าเด็กทุกคนควรเติบโตด้วยความรักและความมั่นใจในตัวเอง เราจึงสร้างการแบ่งปันที่เปี่ยมด้วยความหมาย ผ่านโมเดลการสนับสนุนแบบ 1 ต่อ 1 เพื่อโอบกอดเด็กๆ ด้วยความรัก และเสริมสร้างความภาคภูมิใจในคุณค่าของตนเองให้เด็กเติบโตอย่างมั่นคง</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="about-work-card about-work-card--green text-center h-100">
          <span class="about-work-card__icon about-work-card__icon--green" aria-hidden="true">
            <i class="bi-book-fill"></i>
          </span>
          <h5 class="fw-bold">การศึกษา</h5>
          <p>เราเพิ่มโอกาสในการเข้าถึงการศึกษาที่เปี่ยมด้วยคุณภาพ โดยร่วมสนับสนุนอุปกรณ์การเรียนรู้ที่จำเป็นในยุคปัจจุบัน เพื่อเปิดประตูสู่โลกกว้างให้เด็กๆ ได้พัฒนาทักษะ ค้นพบความชอบของตัวเอง และสร้างแรงบันดาลใจในการส่งต่อความฝันของพวกเขาให้กลายเป็นจริงในอนาคต</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== การทำงานของ DrawDream ===== -->
<section class="hiw-stage page-section" id="how-it-works" aria-labelledby="hiw-steps-heading">
  <h2 id="hiw-steps-heading" class="hiw-stage__title">การทำงานของ DrawDream</h2>
  <div class="hiw-grid">
    <article class="hiw-card hiw-card--blue hiw-card--num-top">
      <span class="hiw-card__num" aria-hidden="true">1</span>
      <div class="hiw-card__content">
        <span class="hiw-card__step">ขั้นที่ 1</span>
        <h3 class="hiw-card__heading">ตรวจสอบและยืนยัน</h3>
        <p class="hiw-card__text">
          มูลนิธิ โปรไฟล์เด็ก โครงการ และรายการสิ่งของผ่านการตรวจสอบจากผู้ดูแลและระบบก่อนเปิดรับบริจาค
          เพื่อให้ข้อมูลน่าเชื่อถือและปลอดภัยสำหรับผู้ให้ทุกคน
        </p>
        <span class="hiw-card__icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
      </div>
    </article>

    <article class="hiw-card hiw-card--green hiw-card--num-bottom">
      <span class="hiw-card__num" aria-hidden="true">2</span>
      <div class="hiw-card__content">
        <span class="hiw-card__step">ขั้นที่ 2</span>
        <h3 class="hiw-card__heading">เผยแพร่บนแพลตฟอร์ม</h3>
        <p class="hiw-card__text">
          รายการที่ผ่านการยืนยันจะปรากฏบน DrawDream ให้ผู้บริจาคเลือกอุปการะเด็ก
          บริจาคเข้าโครงการ หรือสมทบทุนจัดซื้อสิ่งของตามความตั้งใจ
        </p>
        <span class="hiw-card__icon" aria-hidden="true"><i class="bi bi-megaphone-fill"></i></span>
      </div>
    </article>

    <article class="hiw-card hiw-card--amber hiw-card--num-top">
      <span class="hiw-card__num" aria-hidden="true">3</span>
      <div class="hiw-card__content">
        <span class="hiw-card__step">ขั้นที่ 3</span>
        <h3 class="hiw-card__heading">รับบริจาคอย่างปลอดภัย</h3>
        <p class="hiw-card__text">
          ผู้บริจาคชำระผ่านระบบที่รองรับ เงินถูกบันทึกและพักใน escrow
          จนกว่าจะดำเนินการตามเงื่อนไขของโครงการหรือรายการสิ่งของ
        </p>
        <span class="hiw-card__icon" aria-hidden="true"><i class="bi bi-credit-card-2-front-fill"></i></span>
      </div>
    </article>

    <article class="hiw-card hiw-card--red hiw-card--num-bottom">
      <span class="hiw-card__num" aria-hidden="true">4</span>
      <div class="hiw-card__content">
        <span class="hiw-card__step">ขั้นที่ 4</span>
        <h3 class="hiw-card__heading">ส่งมอบและอัปเดตผล</h3>
        <p class="hiw-card__text">
          หลังยืนยันการโอนเงินหรือจัดส่งสิ่งของ มูลนิธิโพสต์ความคืบหน้าและผลลัพธ์
          ผู้บริจาคติดตามได้จากแพลตฟอร์มและการแจ้งเตือนในระบบ
        </p>
        <span class="hiw-card__icon" aria-hidden="true"><i class="bi bi-heart-fill"></i></span>
      </div>
    </article>
  </div>
</section>

<!-- ===== FAQ ===== -->
<section class="py-5 page-section about-faq-section">
  <div class="container" style="max-width:800px;">
    <h2 class="text-center fw-bold mb-5">คำถามที่พบบ่อย</h2>
    <div class="accordion d-flex flex-column gap-3" id="faqAccordion">

      <div class="accordion-item border-0 rounded-4 overflow-hidden" style="background-color:#f5c518;">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed fw-bold rounded-4" type="button"
            data-bs-toggle="collapse" data-bs-target="#faq1"
            style="background-color:#f5c518; color:#222; box-shadow:none;">
            1.การลดหย่อนภาษี?
          </button>
        </h2>
        <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
          <div class="accordion-body pt-0" style="color:#333; line-height:2;">
            1 มกราคม 2569 เป็นต้นไป จากมาตรการใหม่ของสรรพากร จะลดหย่อนภาษีได้ต้องบริจาคผ่านการบริจาคทางอิเล็กทรอนิกส์<br>
            บุคคลธรรมดา ไม่เกิน 10% ของจำนวนเงินได้<br>
            นิติบุคคล ไม่เกิน 2% ของกำไรสุทธิ
          </div>
        </div>
      </div>

      <div class="accordion-item border-0 rounded-4 overflow-hidden" style="background-color:#f5c518;">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed fw-bold rounded-4" type="button"
            data-bs-toggle="collapse" data-bs-target="#faq2"
            style="background-color:#f5c518; color:#222; box-shadow:none;">
            2. ฉันจะอุปการะเด็กรายบุคคลได้นานแค่ไหน?
          </button>
        </h2>
        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
          <div class="accordion-body" style="color:#333; line-height:2;">
            สามารถเลือกสนับสนุนเป็นรายเดือนหรือรายปีก็ได้ ถ้าช่วงไหนคุณไม่สะดวกดูแลต่อ กดยกเลิกการอุปการะเด็กล่วงหน้าเพื่อให้มีเวลาหาผู้อุปการะใจดีท่านใหม่มาช่วยดูแลน้องได้อย่างต่อเนื่อง
          </div>
        </div>
      </div>

      <div class="accordion-item border-0 rounded-4 overflow-hidden" style="background-color:#f5c518;">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed fw-bold rounded-4" type="button"
            data-bs-toggle="collapse" data-bs-target="#faq3"
            style="background-color:#f5c518; color:#222; box-shadow:none;">
            3. ฉันสามารถอุปการะเด็กมากกว่าหนึ่งคนได้มั้ย?
          </button>
        </h2>
        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
          <div class="accordion-body" style="color:#333; line-height:2;">
            ได้เลย คุณสามารถเลือกอุปการะน้องกี่คนก็ได้ตามที่ไหวเลย โดยในระบบจะมีหน้าผลลัพธ์
            ไว้ให้คุณเข้ามาดูอัปเดตของน้องๆ ทุกคน
          </div>
        </div>
      </div>

      <div class="accordion-item border-0 rounded-4 overflow-hidden" style="background-color:#f5c518;">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed fw-bold rounded-4" type="button"
            data-bs-toggle="collapse" data-bs-target="#faq4"
            style="background-color:#f5c518; color:#222; box-shadow:none;">
            4. สามารถขอใบเสร็จรับเงินเพื่อลดหย่อนภาษีได้หรือไม่?
          </button>
        </h2>
        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
          <div class="accordion-body" style="color:#333; line-height:2;">
            ได้รับใบเสร็จทันที เมื่อการบริจาคเสร็จสมบูรณ์ ระบบจะออก ใบเสร็จรับเงินบริจาคอิเล็กทรอนิกส์ ให้โดยอัตโนมัติ
            ระบบรองรับการออกใบเสร็จทั้งในนาม บุคคลธรรมดา และ นิติบุคคล (บริษัท) เพื่อให้เหมาะสมกับประเภทการยื่นภาษีของคุณ
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ===== FOOTER (เหมือน homepage) ===== -->
<div class="footer-wrap page-section" style="background-color:#3f4f9a;">
<?php include __DIR__ . '/includes/site_footer.php'; ?>
</div>

</body>
</html>