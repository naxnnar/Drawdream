<?php
// ไฟล์นี้: homepage.php
// หน้าที่: หน้าแรกของเว็บไซต์และเล่าเรื่องโครงการ
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>หน้าหลัก | DrawDream</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/homepage.css">
</head>
<body>

<?php include 'navbar.php'; ?>

<!-- ===== SECTION 1: HERO ===== -->
<div class="home-section home-hero">
  <div class="container">
    <div class="row align-items-center">

      <div class="col-md-5 d-flex hero-image-col">
        <div class="portrait-wrapper">
          <img src="img/childd.png" alt="DrawDream" class="portrait-img">
        </div>
      </div>

      <div class="col-md-7 hero-text-col">
        <div class="center-section">
          <h2>ให้ในแบบที่ใช่ สนับสนุนในสิ่งที่เขาต้องการ...</h2>
          <h3>เพราะความต้องการของเด็กแต่ละคนนั้นแตกต่างกัน</h3>
          <style>
            @media (min-width: 768px) {
              .center-section h3 {
                white-space: nowrap;
              }
            }
          </style>
          <p>DrawDream ช่วยให้คุณ เลือกส่งต่อ ได้ตรงจุดที่สุด</p>
          <p>สามารถเข้าร่วมโครงการอุปการะรายบุคคล เพื่อส่งน้องๆ ให้ถึงฝั่งฝัน</p>
          <div class="hero-actions mt-4">
            <a href="children_.php" class="btn btn-hero">อุปการะ</a>
            <a href="project.php" class="btn btn-hero">โครงการ</a>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===== SECTION 2: DONATION ===== -->
<div class="home-section" style="background-color: #F7ECDE;">
  <div class="container py-5">
    <div class="row align-items-center">

      <div class="col-md-5 d-flex justify-content-center">
        <div class="donation-image">
          <img src="img/house.png" alt="DrawDream">
        </div>
      </div>

      <div class="col-md-7 text-center px-4 px-md-5">
        <h2 class="mb-2"><b>บริจาคให้เด็กกับ <span class="highlight">Drawdream</span></b></h2>
        <p class="mb-4">เพื่อช่วยคนเด็กเข้าถึงศิลปะการศึกษา</p>

        <form id="donateForm" class="mb-3" onsubmit="return false;">
          <div class="donation-amounts mb-3">
            <button type="button" class="amount-btn" data-amount="500">฿500</button>
            <button type="button" class="amount-btn" data-amount="600">฿600</button>
            <button type="button" class="amount-btn" data-amount="900">฿900</button>
          </div>
          <div class="mb-3 d-flex justify-content-center" style="gap:0;">
            <div style="width:515px;max-width:100%;position:relative;">
              <input type="number" min="20" step="1" id="donateAmount" name="donateAmount" placeholder="ระบุจำนวนเงิน (ขั้นต่ำ 20)" required style="width:100%;padding:12px 44px 12px 48px;border-radius:12px;border:1.5px solid #d8cfc7;font-size:1.15rem;">
              <span style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#888;font-size:1.2rem;">฿</span>
              <button type="button" id="clearBtn" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;font-size:1.3rem;color:#888;">&times;</button>
            </div>
          </div>
          <div class="d-flex justify-content-center">
            <button type="submit" class="btn-donate mt-2" style="width:515px;max-width:100%;margin:auto;display:block;">บริจาค</button>
          </div>
        </form>
        <script>
        // ปุ่ม preset
        const amountBtns = document.querySelectorAll('.amount-btn');
        const donateAmount = document.getElementById('donateAmount');
        amountBtns.forEach(btn => {
          btn.addEventListener('click', () => {
            amountBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            donateAmount.value = btn.dataset.amount;
          });
        });
        // ปุ่มล้าง
        document.getElementById('clearBtn').onclick = () => {
          donateAmount.value = '';
          amountBtns.forEach(b => b.classList.remove('active'));
        };
        // ส่งฟอร์ม
        document.getElementById('donateForm').onsubmit = function(e) {
          e.preventDefault();
          const val = parseInt(donateAmount.value, 10);
          if (isNaN(val) || val < 20) {
            alert('กรุณากรอกจำนวนเงินขั้นต่ำ 20 บาท');
            donateAmount.focus();
            return false;
          }
          window.location.assign('payment/donate_qr.php');
        };
        </script>
      </div>

    </div>
  </div>
</div>

<!-- ===== SECTION 3: STORIES ===== -->
<div class="home-section stories">
  <div class="container">

    <!-- น้องอลิน — แถวบน: รูปซ้าย, text ขวา -->
    <div class="row align-items-center mb-5">
      <div class="col-md-6">
        <img src="img/alin.png" alt="น้องอลิน" class="img-fluid rounded shadow">
      </div>
      <div class="col-md-6 ps-md-5">
        <h3 class="text-light mt-4 mt-md-0">น้องอลิน</h3>
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
      <div class="col-md-6 d-flex flex-column align-items-center text-light" style="justify-content:flex-start;">
        <img src="img/pin.png" alt="น้องพิณเพลง" class="img-fluid rounded shadow mb-3" style="object-fit:cover; object-position:top; align-self:flex-start;">
        <h3>น้องพิณเพลง</h3>
        <p class="card-text">
          น้องพิณเพลงเป็นเด็กขี้อายที่มีพรสวรรค์ด้านเสียงร้อง แต่เธอไม่เคยมีโอกาสได้เรียนรู้ทักษะดนตรีอย่างจริงจัง
          ผู้บริจาครายหนึ่งได้เห็นโปรไฟล์ของน้องและตัดสินใจสนับสนุนผ่านฟีเจอร์ "บริจาครายบุคคล"
          ปัจจุบันเสียงเพลงของน้องพิณเพลงไม่ได้ก้องกังวานแค่ในมูลนิธิอีกต่อไป เธอได้รับโอกาสขึ้นแสดงในงานโรงเรียน
        </p>
        <a href="detail_pin.php" class="btn btn-light btn-home">อ่านต่อ</a>
      </div>
      <div class="col-md-6 d-flex flex-column align-items-center text-light">
        <img src="img/san.png" alt="น้องแซน" class="img-fluid rounded shadow mb-3">
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

<!-- ===== SECTION 4: RIGHTS HOLDER ===== -->
<div class="home-section home-contact-section">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-12">
        <div class="rights-box">
          <div class="rights-content">
            <h4>ร่วมสนับสนุนค่าบริหารจัดการระบบ </h4>
            <p>เพื่อเปลี่ยนระบบการช่วยเหลือให้เข้าถึงง่ายสำหรับทุกคน</p>
            <div class="rights-actions">
              <a href="#" class="btn btn-success btn-lg btn-radis">บริจาค</a>
              <!-- <a href="#" class="btn btn-success btn-lg btn-radis">มูลนิธิ</a> -->
            </div>
          </div>
          <div class="rights-image text-center text-md-end">
            <img src="img/star.png" alt="Rights holder" class="img-fluid">
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

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
<footer style="background-color: #3f4f9a;">
  <div class="container py-4" style="background-color: #3f4f9a;">
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
    <hr style="border-color: rgba(255,255,255,0.25);">
    <p class="text-center text-light mb-0 small" style="opacity:0.7;">&copy; All right reserved 2026</p>
  </div>
</footer>
</div>

<script>
  // Donation amount buttons
  const amountBtns = document.querySelectorAll('.amount-btn');
  const display    = document.getElementById('selectedAmountDisplay');
  const clearBtn   = document.getElementById('clearBtn');

  amountBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      amountBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      display.textContent = '฿ ' + btn.dataset.amount;
    });
  });

  clearBtn.addEventListener('click', () => {
    amountBtns.forEach(b => b.classList.remove('active'));
    display.textContent = '฿ 0';
  });
</script>
</body>
</html>