<?php
// includes/site_footer.php — HTML footer เว็บไซต์
require_once __DIR__ . '/brand_logo.php';
$footerBase = $footer_base_path ?? '';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($footerBase . 'css/site_footer.css?v=3', ENT_QUOTES, 'UTF-8') ?>">
<div class="site-footer-outer">
<footer class="site-footer" style="background-color: #3f4f9a;">
  <div class="container" style="background-color: #3f4f9a;">
    <div class="site-footer__grid text-light">

      <div class="site-footer__brand-stack">
        <div class="site-footer__logo-cell">
          <img src="<?= htmlspecialchars(drawdream_brand_logo_src($footerBase), ENT_QUOTES, 'UTF-8') ?>" alt="DrawDream logo" class="footer-logo">
        </div>
        <div class="site-footer__donate-cell">
          <p class="text-light site-footer__donate-text mb-0">
            ร่วมบริจาคเพื่อช่วยเหลือเด็กได้ที่<br>
            ธนาคารไทยพาณิชย์<br>
            เลขที่บัญชี <span style="color:#f4c948; font-weight:bold;">011-1-11111-1</span>
          </p>
        </div>
      </div>

      <div class="site-footer__title-cell footer-contact-col">
        <h5 class="text-light footer-contact-title mb-0">ติดต่อเรา</h5>
      </div>

      <div class="site-footer__contact-body footer-contact-col">
        <p class="text-light footer-address site-footer__address mb-3">
          <i class="bi bi-geo-alt-fill me-2"></i>ชั้น 3 อาคาร Drawdream ถนนพหลโยธิน แขวงพญาไท เขตพญาไท กรุงเทพมหานคร&nbsp;10400
        </p>
        <div class="site-footer__contact-phones text-light mb-3">
          <span><i class="bi bi-telephone-fill me-1"></i> 0949278518</span>
          <a href="mailto:contact@drawdream.org" class="site-footer__email text-light text-decoration-none"><i class="bi bi-envelope-fill me-1"></i> contact@drawdream.org</a>
        </div>
        <div class="social-links site-footer__social">
          <a href="https://www.facebook.com/profile.php?id=61591559604565" class="social-link" aria-label="Facebook" target="_blank" rel="noopener noreferrer"><i class="bi bi-facebook"></i></a>
          <a href="https://www.tiktok.com/@edelweissinmydream?_r=1&amp;_t=ZS-97dAY9MOKW4" class="social-link" aria-label="TikTok" target="_blank" rel="noopener noreferrer"><i class="bi bi-tiktok"></i></a>
          <a href="#" class="social-link" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
          <a href="https://www.youtube.com/@prim8938" class="social-link" aria-label="YouTube" target="_blank" rel="noopener noreferrer"><i class="bi bi-youtube"></i></a>
        </div>
      </div>

    </div>

    <p class="site-footer__copy text-light">© All right reserved 2026</p>
  </div>
</footer>
</div>
