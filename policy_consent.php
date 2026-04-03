<?php
// ไฟล์นี้: policy_consent.php
// หน้าที่: หน้านโยบายเต็ม (เปิดจาก URL); เนื้อหาเดียวกับโมดัลในหน้าสร้างโปรไฟล์เด็ก (includes/policy_consent_content.php)
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>นโยบายความยินยอม | DrawDream</title>
<link rel="stylesheet" href="css/navbar.css">
<link rel="stylesheet" href="css/policy_consent.css">
</head>
<body class="policy-consent-page">
<?php include 'navbar.php'; ?>

<div class="policy-consent-page__container">
    <div class="policy-consent-page__paper">
        <div class="policy-consent-prose">
            <?php include __DIR__ . '/includes/policy_consent_content.php'; ?>
        </div>
    </div>
</div>
</body>
</html>
