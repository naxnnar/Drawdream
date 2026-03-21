<?php
// ไฟล์นี้: about.php
// หน้าที่: หน้าเกี่ยวกับเราและคำถามที่พบบ่อย
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>เกี่ยวกับเรา | DrawDream </title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/navbar.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/about.css">
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

    <div class="hero-copy">
      <div class="hero-brand">
        <img src="img/logobanner.png" alt="Draw Dream Logo" class="hero-logo">
        <span style="font-size:1.45rem; font-weight:600; color:#333;">เริ่มต้นจากความตั้งใจ</span>
      </div>
      <p class="hero-tagline">อยากเห็นเด็กในสถานสงเคราะห์มีโอกาสได้เลือก&amp;ทำตามความฝัน</p>
      <p class="hero-body">
        วาดฝันเป็นสื่อกลางส่งต่อความช่วยเหลือจากผู้อุปการะไปยังเด็กแบบเฉพาะบุคคลซึ่งสะท้อนความสนใจ
        และความฝันของตัวเด็ก เราเป็นองค์กรไม่แสวงหาผลกำไร ภายใต้แนวคิดของคนรุ่นใหม่
        และให้สมาชิกมีส่วนร่วมติดตามผลที่ตนบริจาคได้
      </p>
    </div>
  </div>
</section>

<!-- ===== งานของเรา ===== -->
<section class="py-5 page-section">
  <div class="container">
    <h2 class="text-center fw-bold mb-5">งานของเรา</h2>
    <div class="row g-4 justify-content-center">
      <div class="col-md-4">
        <div class="about-work-card text-center h-100" style="background-color:#f5c518;">
          <i class="bi-person-hearts mb-3" style="font-size:2rem;"></i>
          <h5 class="fw-bold">สิทธิเด็ก</h5>
          <p>เราสนับสนุนสิทธิเด็ก และส่งเสริมให้เด็กมีส่วนร่วมในการแสดงความคิดเห็นที่สะท้อนความสนใจและความฝันของเด็ก</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="about-work-card text-center h-100" style="background-color:#c0392b; color:#fff;">
          <i class="bi-heart-fill mb-3" style="font-size:2rem;"></i>
          <h5 class="fw-bold">คุณค่าที่สัมผัสได้</h5>
          <p>เราต้องการให้เด็กได้รับความรัก และให้เขารู้ว่าเขาเป็นคนที่มีคุณค่า ผ่านการบริจาคแบบรายบุคคลแบบ1ต่อ1</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="about-work-card text-center h-100" style="background-color:#7a9e7e; color:#fff;">
          <i class="bi-book-fill mb-3" style="font-size:2rem;"></i>
          <h5 class="fw-bold">การศึกษา</h5>
          <p>เราเพิ่มโอกาสในการเข้าถึงการศึกษาที่มีคุณภาพและเครื่องมือที่จำเป็น ทำให้พวกเขาได้รับการศึกษาที่มีคุณภาพ</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== ทุกความฝัน ===== -->
<section class="py-5 page-section section-green" style="background-color:#5a7a4a;">
  <div class="container py-4">
    <div class="row align-items-center g-4">
      <div class="col-md-6">
        <img src="img/children-field.png" alt="เด็กในทุ่งนา"
             class="img-fluid rounded-3 d-block mx-auto"
             style="max-height:380px; width:100%; object-fit:cover;">
      </div>
      <div class="col-md-6 text-center">
        <h2 class="fw-bold mb-4" style="font-size:2rem; line-height:1.5; color:#fff;">
          ทุกความฝันมีความหมายร่วมเติมฝัน<br>ของน้องๆให้เป็นจริง
        </h2>
        <p style="font-size:0.95rem; line-height:2; color:rgba(255,255,255,0.9);">
          เราต้องการให้ทุกการบริจาคไปสู่โครงการที่มุ่งมั่นและตั้งใจสร้างการเปลี่ยนแปลงทางสังคมอย่างแท้จริง
          ผ่านการสนับสนุนที่ทุกคนได้ร่วมเป็นส่วนหนึ่ง ของการสร้างการเปลี่ยนแปลงเพื่อสังคม
          และร่วมผลักดันให้การบริจาคมีความน่าเชื่อถือ โปร่งใส สามารถตรวจสอบได้
        </p>
      </div>
    </div>
  </div>
</section>

<!-- ===== FAQ ===== -->
<section class="py-5 page-section">
  <div class="container" style="max-width:800px;">
    <h2 class="text-center fw-bold mb-5">คำถามที่พบบ่อย</h2>
    <div class="accordion d-flex flex-column gap-3" id="faqAccordion">

      <div class="accordion-item border-0 rounded-4 overflow-hidden" style="background-color:#f5c518;">
        <h2 class="accordion-header">
          <button class="accordion-button fw-bold fs-5 rounded-4" type="button"
            data-bs-toggle="collapse" data-bs-target="#faq1"
            style="background-color:#f5c518; color:#222; box-shadow:none;">
            1.การลดหย่อนภาษี?
          </button>
        </h2>
        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
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
          <div class="accordion-body" style="color:#333;">เนื้อหาคำตอบข้อ 2</div>
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
          <div class="accordion-body" style="color:#333;">เนื้อหาคำตอบข้อ 3</div>
        </div>
      </div>

      <div class="accordion-item border-0 rounded-4 overflow-hidden" style="background-color:#f5c518;">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed fw-bold rounded-4" type="button"
            data-bs-toggle="collapse" data-bs-target="#faq4"
            style="background-color:#f5c518; color:#222; box-shadow:none;">
            4. ค่าธรรมเนียม 10% เป็นค่าอะไรบ้าง
          </button>
        </h2>
        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
          <div class="accordion-body" style="color:#333;">เนื้อหาคำตอบข้อ 4</div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ===== FOOTER (เหมือน homepage) ===== -->
<div class="footer-wrap page-section" style="background-color:#3f4f9a;">
  <footer style="background-color:#3f4f9a;">
    <div class="container py-4" style="background-color:#3f4f9a;">
      <div class="row text-light">
        <div class="col-md-6 mb-4">
          <img src="img/logobanner.png" alt="DrawDream logo" class="mb-3 footer-logo">
          <p class="text-light">
            ร่วมบริจาคเพื่อช่วยเหลือเด็กได้ที่<br>
            ธนาคารไทยพาณิชย์<br>
            เลขที่บัญชี <span style="color:#f4c948; font-weight:bold;">011-1-11111-1</span>
          </p>
        </div>
        <div class="col-md-6 mb-4">
          <h5 class="text-center mb-3 text-light">ติดต่อเรา</h5>
          <p class="text-light footer-address">
            <i class="bi bi-geo-alt-fill me-2"></i>
            ชั้น 3 อาคาร Drawdream ถนนพหลโยธิน แขวงพญาไท เขตพญาไท กรุงเทพมหานคร 10400
          </p>
          <div class="d-flex justify-content-center gap-4 mb-3">
            <span class="text-light"><i class="bi bi-telephone-fill me-1"></i> 0949278518</span>
            <span class="text-light"><i class="bi bi-printer-fill me-1"></i> 0123456789</span>
          </div>
          <div class="social-links">
            <a href="#" class="social-link" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
            <a href="#" class="social-link" aria-label="TikTok"><i class="bi bi-tiktok"></i></a>
            <a href="#" class="social-link" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
            <a href="#" class="social-link" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
          </div>
        </div>
      </div>
      <hr style="border-color:rgba(255,255,255,0.25);">
      <p class="text-center text-light mb-0 small" style="opacity:0.7;">&copy; All right reserved 2026</p>
    </div>
  </footer>
</div>

</body>
</html>