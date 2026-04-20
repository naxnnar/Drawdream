<?php
declare(strict_types=1);
// สรุปสั้น: ฟอร์มบริจาคผ่านระบบแบบทั่วไป แล้วส่งต่อไปหน้า QR ตามจำนวนเงินที่กรอก

$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 20;
$amount = max(0, $amount);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($amount < 20) {
        $error = 'จำนวนเงินขั้นต่ำ 20 บาท';
    } else {
        header('Location: donate_qr.php?amount=' . urlencode((string)$amount));
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>บริจาคค่าบริหารระบบ | DrawDream</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../css/system_donate.css?v=1">
</head>
<body class="system-donate-page">
  <a href="../homepage.php#support" class="donate-back-btn" aria-label="ย้อนกลับ"><i class="bi bi-arrow-left"></i></a>

  <main class="donate-stage">
    <section class="donate-card">
      <header class="donate-card-head">
        <h1>เลือกจำนวนเงินบริจาค</h1>
        <p>ร่วมสนับสนุนค่าบริหารจัดการระบบ DrawDream</p>
      </header>

      <form method="post" class="donate-form" novalidate>
        <div class="preset-list" id="presetList">
          <button type="button" class="preset-item" data-amount="20">฿ 20</button>
          <button type="button" class="preset-item" data-amount="50">฿ 50</button>
          <button type="button" class="preset-item" data-amount="100">฿ 100</button>
        </div>

        <label for="amountInput" class="amount-label">หรือระบุจำนวนเงินตามศรัทธา</label>
        <input
          type="number"
          min="20"
          step="1"
          inputmode="numeric"
          id="amountInput"
          name="amount"
          value="<?= htmlspecialchars((string)(int)$amount, ENT_QUOTES, 'UTF-8') ?>"
          placeholder="ขั้นต่ำ 20 บาท"
          required
        >

        <?php if (!empty($error)): ?>
          <p class="error-text"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <button type="submit" class="submit-btn">ถัดไป</button>
      </form>
    </section>
  </main>

  <script>
    (function () {
      const input = document.getElementById('amountInput');
      const presetList = document.getElementById('presetList');
      if (!input || !presetList) return;

      const updateActive = function () {
        const current = parseInt(input.value || '0', 10);
        presetList.querySelectorAll('.preset-item').forEach(function (btn) {
          const amount = parseInt(btn.getAttribute('data-amount') || '0', 10);
          btn.classList.toggle('is-active', amount === current);
        });
      };

      presetList.querySelectorAll('.preset-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
          input.value = btn.getAttribute('data-amount') || '';
          updateActive();
        });
      });

      input.addEventListener('input', updateActive);
      updateActive();
    })();
  </script>
</body>
</html>
