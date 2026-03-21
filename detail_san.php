<?php
// ไฟล์นี้: detail_san.php
// หน้าที่: หน้ารายละเอียดเรื่องราวน้องแซน
// เตรียมข้อมูลเรื่องราวของน้องแซน
$story = [
  'name' => 'น้องแซน',
  'image' => 'img/san.png',
  'images' => ['img/san1.png', 'img/san2.png', 'img/san3.png'],
  'description' => 'น้องแซนมักจะใช้ชอบระบายสีเสมอเมื่อมีกิจกรรมเล่นเวลาว่าง เขาจะใช้ดินสอแท่งเดิมจนเหลือแท่งสั้นๆ วาดรูปบนกระดาษ',
  'story' => 'รูปที่เขาชอบวาดที่สุดคือ "บ้านที่มีความสุข" น้องได้รับรางวัลชนะเลิศการประกวดวาดภาพระดับท้องถิ่น อุปกรณ์ศิลปะเหล่านั้นไม่ได้แค่ใช้ระบายสีลงบนกระดาษ แต่กำลังช่วยระบายความหวังและอนาคตที่สวยงาม'
];
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
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="row align-items-center py-5">
  <div class="row">
    <div class="col-md-1"></div>
    <div class="col-md-5 position-relative">
      <img src="<?php echo $story['image']; ?>" alt="<?php echo $story['name']; ?>" class="img-fluid" style="margin-left: 20%; width: 80%;">
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
    <?php foreach ($story['images'] as $img): ?>
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

</body>
</html>