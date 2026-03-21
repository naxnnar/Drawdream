<?php
// ไฟล์นี้: detail_alin.php
// หน้าที่: หน้ารายละเอียดเรื่องราวน้องอลิน
// เตรียมข้อมูลเรื่องราว
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
  <title><?php echo $story['name']; ?> - DrawDream</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <!-- link css -->
</head>
<body>

<?php include 'navbar.php'; ?>
  
<div class="row align-items-center py-5">
  <div class="row">
    <div class="col-md-1"></div>
    <div class="col-md-5 position-relative">
      <img src="<?php echo $story['image']; ?>" alt="<?php echo $story['name']; ?>" class="img-fluid" style="margin-left: 20%; width: 80%;">
      
      <!-- ปุ่มปิด: วางตรงมุมบนขวาของรูป -->
      <a href="homepage.php" class="btn btn-lg position-absolute" style="margin-left: 85%; margin-top: -4%;">
        <i class="bi bi-x-square-fill fs-2"></i>
      </a>
    </div>
    <div class="col-md-5">
      <h3 class="mt-5"><?php echo $story['name']; ?></h3>
      <p class="mt-3"><?php echo $story['description']; ?></p>
    </div>
  </div>
  
  <div class="row mt-5">
    <div class="col-md-2"></div>
    <?php foreach($story['images'] as $img): ?>
    <div class="col-md-3">
      <img src="<?php echo $img; ?>" alt="Story detail" class="img-fluid">
    </div>
    <?php endforeach; ?>
  </div>
  
  <div class="row mt-3 mb-5">
    <div class="col-md-2"></div>
    <div class="col-md-8">
      <p class="text-center"><?php echo $story['story']; ?></p>
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
