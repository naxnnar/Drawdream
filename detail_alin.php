<?php
// detail_alin.php — เรื่องราวน้องอลิน (สตอรี่ตัวอย่าง)
// สรุปสั้น: ไฟล์นี้แสดงรายละเอียดหน้า detail alin
$stories = [
  'alin' => [
    'name' => 'น้องอลิน',
    'image' => 'img/alin.png',
    'images' => ['img/alin1.png', 'img/alin2.png', 'img/alin3.png'],
    'description' => 'น้องอลินเป็นเด็กที่รักการอ่าน แต่หนังสือในห้องสมุดที่มีมักเป็นเล่มเก่าและไม่เพียงพอต่อใจรักการอ่านของเธอ',
    'story' => 'ผ่านโครงการ "บริจาคแบบรวมของทุนการศึกษา" ผู้บริจาคได้ร่วมกันมอบทุนการศึกษาและจัดซื้อชุดหนังสือวรรณกรรมเยาวชนและหนังสือนิทานใหม่ วันนี้น้องอลินไม่ได้มีเพียงหนังสืออ่านนอกจากการอ่านแล้วน้องอลินมักชอบเล่าเรื่องที่เธออ่านให้เพื่อนๆฟัง เธอกลายเป็นตัวแทนโรงเรียนไปแข่งขันทักษะทางภาษาไทยจนได้รับรางวัล'
  ],
  'pin' => [
    'name' => 'น้องพิณเพลง',
    'image' => 'img/pin.png',
    'images' => ['img/pin1.png', 'img/pin2.png', 'img/pin3.png'],
    'description' => 'น้องพิณเพลงเป็นเด็กขี้อายที่มีพรสวรรค์ด้านเสียงร้อง แต่เธอไม่เคยมีโอกาสได้เรียนรู้ทักษะดนตรีอย่างจริงจัง',
    'story' => 'ผู้บริจาครายหนึ่งได้เห็นโปรไฟล์ของน้องและตัดสินใจสนับสนุนผ่านฟีเจอร์ "บริจาครายบุคคล" ปัจจุบันเสียงเพลงของน้องพิณเพลงไม่ได้ก้องกังวานแค่ในมูลนิธิอีกต่อไป เธอได้รับโอกาสขึ้นแสดงในงานโรงเรียน'
  ],
  'san' => [
    'name' => 'น้องแซน',
    'image' => 'img/san.png',
    'images' => ['img/san1.png', 'img/san2.png', 'img/san3.png'],
    'description' => 'น้องแซนมักจะใช้ชอบระบายสีเสมอเมื่อมีกิจกรรมเล่นเวลาว่าง เขาจะใช้ดินสอแท่งเดิมจนเหลือแท่งสั้นๆ วาดรูปบนกระดาษ',
    'story' => 'รูปที่เขาชอบวาดที่สุดคือ "บ้านที่มีความสุข" น้องได้รับรางวัลชนะเลิศการประกวดวาดภาพระดับท้องถิ่น อุปกรณ์ศิลปะเหล่านั้นไม่ได้แค่ใช้ระบายสีลงบนกระดาษ แต่กำลังช่วยระบายความหวังและอนาคตที่สวยงาม'
  ]
];

// ตรวจสอบว่ามีค่า id หรือไม่
$id = isset($_GET['id']) ? $_GET['id'] : 'alin';
$story = isset($stories[$id]) ? $stories[$id] : $stories['alin'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
  <title><?php echo $story['name']; ?> - DrawDream</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/child_story_detail.css?v=3">
</head>
<body class="child-story-page">

<?php include 'navbar.php'; ?>
<div class="container-fluid child-story-shell">
  <div class="child-story-card">
    <a href="homepage.php#stories" onclick="if (window.history.length > 1) { history.back(); return false; }" class="child-story-close-btn" aria-label="ปิดและกลับ">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </a>
    <div class="row child-story-top align-items-start g-4 py-3 py-lg-5">
      <div class="col-12 col-lg-5 child-story-hero-col">
        <img src="<?php echo htmlspecialchars($story['image'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($story['name'], ENT_QUOTES, 'UTF-8'); ?>" class="img-fluid child-story-hero-photo" width="640" height="640" decoding="async">
      </div>
      <div class="col-12 col-lg-7 child-story-intro">
        <h3 class="child-story-name"><?php echo htmlspecialchars($story['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
        <p class="child-story-lead"><?php echo htmlspecialchars($story['description'], ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
    </div>

    <div class="row child-story-gallery g-3 justify-content-center mt-2 mt-lg-4">
      <?php foreach ($story['images'] as $img): ?>
      <div class="col-12 col-md-6 col-lg-4">
        <figure class="child-story-figure">
          <img src="<?php echo htmlspecialchars($img, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="img-fluid child-story-gallery-img" width="800" height="600" loading="lazy" decoding="async">
        </figure>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="row child-story-body mt-4 mb-3 mb-lg-4">
      <div class="col-12 col-lg-10 col-xl-8 mx-auto">
        <p class="child-story-text text-center mb-0"><?php echo htmlspecialchars($story['story'], ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
    </div>
  </div>
</div>


<!-- Footer -->
<div class="row" style="background-color: #3f4f9a;"> 
  <footer class="mt-auto text-light" >
    <div class="container" >
      <div class="row mt-3">
        <div class="col-md-6 mb-3">
          <img src="img/logobanner.png" alt="DrawDream logo">
          <p>ร่วมบริจาคเพื่อช่วยเหลือเด็กได้ที่<br>ธนาคารไทยพาณิชย์ <br>เลขที่บัญชี  011-1-11111-1</p>
        </div>
        
        <div class="col-md-6 mb-3">
          <center><h5>ติดต่อเรา</h5></center>
          <p>
            <i class="bi bi-geo-alt-fill"></i> ชั้น 3 อาคาร Drawdrem ถนนพหลโยธิน แขวงพญาไท เขตพญาไท กรุงเทพมหานคร 10400 
          </p>

          <div class="row text-center">
            <div class="col-md-3"></div>
            <div class="col-md-3">
              <i class="bi bi-telephone-fill"></i> 0949278518
            </div>
            <div class="col-md-3">
              <i class="bi bi-printer-fill"></i> 0123456789
            </div>
          </div>
          <div class="row text-center mt-2">
            <div class="col-md-4"></div>
            <div class="col-md-1">
              <button class="btn btn-light"><i class="bi bi-facebook"></i></button>
            </div>
            <div class="col-md-1">
              <button class="btn btn-light"><i class="bi bi-tiktok"></i></button>
            </div>
            <div class="col-md-1">
              <button class="btn btn-light"><i class="bi bi-instagram"></i></button>
            </div>
            <div class="col-md-1">
              <button class="btn btn-light"><i class="bi bi-youtube"></i></button>
            </div>
          </div>

        </div>
      </div>
      <hr class="mb-4">
      <div class="row">
        <div class="col-md-12 text-center">
          <p>&copy; © All right reserved 2025 WVFT</p>
        </div>
      </div>
    </div>
  </footer>
</div>

</body>
</html>
