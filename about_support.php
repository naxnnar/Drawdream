<?php
declare(strict_types=1);
// about_support.php — สนับสนุนเรา (บริจาค + ค่าบริหารระบบ)

require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
require_once __DIR__ . '/includes/session_init.php';
drawdream_session_start();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <title>สนับสนุนเรา | DrawDream</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link rel="stylesheet" href="css/navbar.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/about.css">
  <link rel="stylesheet" href="css/homepage.css">
</head>
<body class="about-page about-support-page">
<?php include 'navbar.php'; ?>

<!-- ===== แบนเนอร์ขอบคุณผู้บริจาค (ภาพปก / ปกโครงการ) — เต็มความกว้าง ===== -->
<section class="home-banner-carousel home-banner-carousel--fullbleed" aria-roledescription="carousel" aria-label="ขอบคุณผู้สนับสนุน">
  <div class="home-banner-carousel__viewport">
    <div class="home-banner-carousel__track">
      <article class="home-banner-carousel__slide is-active" data-slide="0">
        <img src="img/ภาพปก.png" alt="ขอบคุณผู้บริจาคจากทุกอาชีพ" class="home-banner-carousel__img" width="1920" height="600" loading="eager" decoding="async">
      </article>
      <article class="home-banner-carousel__slide" data-slide="1">
        <img src="img/ปกโครงการ.png" alt="ความสำเร็จของโครงการที่เกิดขึ้นจริง" class="home-banner-carousel__img" width="1920" height="600" loading="lazy" decoding="async">
      </article>
    </div>

    <button type="button" class="home-banner-carousel__nav home-banner-carousel__nav--prev" aria-label="สไลด์ก่อนหน้า">‹</button>
    <button type="button" class="home-banner-carousel__nav home-banner-carousel__nav--next" aria-label="สไลด์ถัดไป">›</button>

    <div class="home-banner-carousel__dots" role="tablist" aria-label="เลือกสไลด์">
      <button type="button" class="home-banner-carousel__dot is-active" role="tab" aria-selected="true" aria-label="สไลด์ที่ 1" data-slide="0"></button>
      <button type="button" class="home-banner-carousel__dot" role="tab" aria-selected="false" aria-label="สไลด์ที่ 2" data-slide="1"></button>
    </div>
  </div>
</section>

<!-- ===== ทุกความฝัน ===== -->
<section class="py-5 page-section about-dream-section">
  <div class="container py-4">
    <div class="row align-items-center g-4">
      <div class="col-md-6">
        <div class="about-dream-photo">
          <span class="about-dream-photo__spark about-dream-photo__spark--1" aria-hidden="true">✦</span>
          <span class="about-dream-photo__spark about-dream-photo__spark--2" aria-hidden="true">★</span>
          <span class="about-dream-photo__spark about-dream-photo__spark--3" aria-hidden="true">✦</span>
          <div class="about-dream-photo__frame">
            <div class="about-dream-slideshow" role="region" aria-roledescription="carousel" aria-label="ภาพกิจกรรมกับเด็ก">
              <img src="img/children-field.png" alt="เด็กในทุ่งนาเรียนรู้ด้วยกัน" class="about-dream-slideshow__slide is-active" loading="eager" tabindex="-1" draggable="false">
              <img src="img/วันเกิด.jpg" alt="เด็กร่วมฉลองวันเกิด" class="about-dream-slideshow__slide" loading="lazy" tabindex="-1" draggable="false">
              <img src="img/จดหมาย.jpg" alt="เด็กถือจดหมายและของขวัญจากผู้อุปการะ" class="about-dream-slideshow__slide" loading="lazy" tabindex="-1" draggable="false">
              <div class="about-dream-slideshow__dots" role="tablist" aria-label="เลือกภาพ">
                <button type="button" class="about-dream-slideshow__dot is-active" role="tab" aria-selected="true" aria-label="ภาพที่ 1" data-slide="0"></button>
                <button type="button" class="about-dream-slideshow__dot" role="tab" aria-selected="false" aria-label="ภาพที่ 2" data-slide="1"></button>
                <button type="button" class="about-dream-slideshow__dot" role="tab" aria-selected="false" aria-label="ภาพที่ 3" data-slide="2"></button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-6 text-center">
        <h1 class="fw-bold mb-4 about-dream-section__title">
          ทุกความฝันมีความหมายร่วมเติมฝัน<br>ของน้องๆให้เป็นจริง
        </h1>
        <p class="about-dream-section__lead">
          เราต้องการให้ทุกการบริจาคไปสู่โครงการที่มุ่งมั่นและตั้งใจสร้างการเปลี่ยนแปลงทางสังคมอย่างแท้จริง
          ผ่านการสนับสนุนที่ทุกคนได้ร่วมเป็นส่วนหนึ่ง ของการสร้างการเปลี่ยนแปลงเพื่อสังคม
          และร่วมผลักดันให้การบริจาคมีความน่าเชื่อถือ โปร่งใส สามารถตรวจสอบได้
        </p>
      </div>
    </div>
  </div>
</section>

<!-- ===== บริจาคให้เด็ก ===== -->
<section class="home-section page-section about-donate-section" style="background-color:#F7ECDE;" aria-labelledby="about-donate-heading">
  <div class="container py-5">
    <div class="row align-items-center">
      <div class="col-md-5 d-flex justify-content-center">
        <div class="donation-image">
          <img src="img/house.png" alt="DrawDream">
        </div>
      </div>
      <div class="col-md-7 text-center px-4 px-md-5">
        <h2 id="about-donate-heading" class="mb-2"><b>บริจาคให้เด็กกับ <span class="highlight">Drawdream</span></b></h2>
        <p class="mb-4">เพื่อช่วยคนเด็กเข้าถึงศิลปะการศึกษา</p>
        <form id="aboutDonateForm" class="mb-3" action="payment/donate_qr.php" method="get">
          <div class="donation-amounts mb-3">
            <button type="button" class="amount-btn about-amount-btn" data-amount="500">฿500</button>
            <button type="button" class="amount-btn about-amount-btn" data-amount="600">฿600</button>
            <button type="button" class="amount-btn about-amount-btn" data-amount="900">฿900</button>
          </div>
          <div class="mb-3 d-flex justify-content-center">
            <div class="about-donate-input-wrap">
              <input type="number" min="20" step="1" id="aboutDonateAmount" name="amount" placeholder="ระบุจำนวนเงิน (ขั้นต่ำ 20)" required class="about-donate-input">
              <span class="about-donate-input__currency" aria-hidden="true">฿</span>
              <button type="button" id="aboutClearBtn" class="about-donate-input__clear" aria-label="ล้างจำนวนเงิน">&times;</button>
            </div>
          </div>
          <div class="d-flex justify-content-center">
            <button type="submit" class="btn-donate mt-2 about-donate-submit">บริจาค</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<!-- ===== สนับสนุนค่าบริหารระบบ ===== -->
<section class="home-section home-contact-section page-section about-support-section" id="support" aria-labelledby="about-support-heading">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-12">
        <div class="rights-box">
          <div class="rights-content">
            <h4 id="about-support-heading">ร่วมสนับสนุนค่าบริหารจัดการระบบ</h4>
            <p>เพื่อขับเคลื่อนแพลตฟอร์มการช่วยเหลือให้มีประสิทธิภาพสูงสุด</p>
            <div class="rights-actions">
              <a href="payment/system_donate.php" class="btn btn-success btn-lg btn-radis">สนับสนุนค่าระบบ</a>
            </div>
          </div>
          <div class="rights-image text-center text-md-end">
            <img src="img/star.png" alt="" class="img-fluid">
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="footer-wrap page-section" style="background-color:#3f4f9a;">
<?php include __DIR__ . '/includes/site_footer.php'; ?>
</div>

<script>
(function () {
  var carousel = document.querySelector('.home-banner-carousel');
  if (!carousel) return;

  var slides = carousel.querySelectorAll('.home-banner-carousel__slide');
  var dots = carousel.querySelectorAll('.home-banner-carousel__dot');
  var prevBtn = carousel.querySelector('.home-banner-carousel__nav--prev');
  var nextBtn = carousel.querySelector('.home-banner-carousel__nav--next');
  var intervalMs = 5000;
  var index = 0;
  var timerId = null;
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function showSlide(n) {
    index = (n + slides.length) % slides.length;
    slides.forEach(function (slide, i) {
      slide.classList.toggle('is-active', i === index);
    });
    dots.forEach(function (dot, i) {
      var on = i === index;
      dot.classList.toggle('is-active', on);
      dot.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function nextSlide() {
    showSlide(index + 1);
  }

  function prevSlide() {
    showSlide(index - 1);
  }

  function startAuto() {
    if (reducedMotion || slides.length < 2) return;
    stopAuto();
    timerId = window.setInterval(nextSlide, intervalMs);
  }

  function stopAuto() {
    if (timerId !== null) {
      window.clearInterval(timerId);
      timerId = null;
    }
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      prevSlide();
      startAuto();
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      nextSlide();
      startAuto();
    });
  }

  dots.forEach(function (dot) {
    dot.addEventListener('click', function () {
      var i = parseInt(dot.getAttribute('data-slide') || '0', 10);
      showSlide(i);
      startAuto();
    });
  });

  carousel.addEventListener('mouseenter', stopAuto);
  carousel.addEventListener('mouseleave', startAuto);

  showSlide(0);
  startAuto();
})();

(function () {
  const slideshow = document.querySelector('.about-dream-slideshow');
  if (slideshow) {
    const slides = slideshow.querySelectorAll('.about-dream-slideshow__slide');
    const dots = slideshow.querySelectorAll('.about-dream-slideshow__dot');
    const intervalMs = 4500;
    let index = 0;
    let timerId = null;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function showSlide(n) {
      index = (n + slides.length) % slides.length;
      slides.forEach(function (slide, i) {
        slide.classList.toggle('is-active', i === index);
      });
      dots.forEach(function (dot, i) {
        var on = i === index;
        dot.classList.toggle('is-active', on);
        dot.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }

    function nextSlide() {
      showSlide(index + 1);
    }

    function startAuto() {
      if (reducedMotion || slides.length < 2) return;
      stopAuto();
      timerId = window.setInterval(nextSlide, intervalMs);
    }

    function stopAuto() {
      if (timerId !== null) {
        window.clearInterval(timerId);
        timerId = null;
      }
    }

    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        var i = parseInt(dot.getAttribute('data-slide') || '0', 10);
        showSlide(i);
        startAuto();
      });
    });

    slideshow.addEventListener('mouseenter', stopAuto);
    slideshow.addEventListener('mouseleave', startAuto);

    showSlide(0);
    startAuto();
  }
})();

(function () {
  const form = document.getElementById('aboutDonateForm');
  const amountInput = document.getElementById('aboutDonateAmount');
  const clearBtn = document.getElementById('aboutClearBtn');
  if (!form || !amountInput) return;

  const amountBtns = form.querySelectorAll('.about-amount-btn');
  amountBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      amountBtns.forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      amountInput.value = btn.dataset.amount;
    });
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      amountInput.value = '';
      amountBtns.forEach(function (b) { b.classList.remove('active'); });
    });
  }

  form.addEventListener('submit', function (e) {
    const val = parseInt(amountInput.value, 10);
    if (isNaN(val) || val < 20) {
      e.preventDefault();
      alert('กรุณากรอกจำนวนเงินขั้นต่ำ 20 บาท');
      amountInput.focus();
    }
  });
})();
</script>
</body>
</html>
