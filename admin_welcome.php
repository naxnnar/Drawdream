<?php
// ไฟล์นี้: admin_welcome.php
// หน้าที่: หน้าต้อนรับแอดมินหลังเข้าสู่ระบบ
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php?page=login&step=choose');
    exit();
}

// ให้หน้านี้แสดงเฉพาะรอบแรกหลัง login เท่านั้น
if (empty($_SESSION['show_admin_welcome'])) {
    header('Location: admin_dashboard.php');
    exit();
}
unset($_SESSION['show_admin_welcome']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome Admin | DrawDream</title>
    <link rel="stylesheet" href="css/admin_welcome.css">
</head>
<body>
    <div class="welcome-bg-shape shape-a" aria-hidden="true"></div>
    <div class="welcome-bg-shape shape-b" aria-hidden="true"></div>

    <main class="welcome-wrap">
        <section class="welcome-card" role="status" aria-live="polite">
            <div class="emoji-bounce" aria-hidden="true">🎉</div>
            <h1>Welcome Admin</h1>
            <p>ยินดีต้อนรับกลับเข้าสู่ระบบผู้ดูแล DrawDream</p>
            <a href="admin_dashboard.php" class="welcome-btn">เข้าสู่ Admin Dashboard</a>
            <small>ระบบจะพาไปหน้า Dashboard อัตโนมัติใน <span id="countdown">5</span> วินาที</small>
        </section>

        <div class="confetti" aria-hidden="true">
            <span style="--x:5%;--delay:0s;--dur:4.2s;--rot:120deg;--clr:#ff7f2a"></span>
            <span style="--x:12%;--delay:0.6s;--dur:4.8s;--rot:250deg;--clr:#34c759"></span>
            <span style="--x:20%;--delay:1.1s;--dur:4.1s;--rot:330deg;--clr:#00b8ff"></span>
            <span style="--x:28%;--delay:0.2s;--dur:5.1s;--rot:200deg;--clr:#ff3b6b"></span>
            <span style="--x:36%;--delay:1.5s;--dur:4.5s;--rot:180deg;--clr:#ffc107"></span>
            <span style="--x:44%;--delay:0.9s;--dur:4.3s;--rot:300deg;--clr:#7b61ff"></span>
            <span style="--x:52%;--delay:0.3s;--dur:4.9s;--rot:160deg;--clr:#00a884"></span>
            <span style="--x:60%;--delay:1.3s;--dur:4.2s;--rot:280deg;--clr:#ff8a00"></span>
            <span style="--x:68%;--delay:0.5s;--dur:5.2s;--rot:210deg;--clr:#2f80ed"></span>
            <span style="--x:76%;--delay:1.0s;--dur:4.6s;--rot:340deg;--clr:#ff4da6"></span>
            <span style="--x:84%;--delay:0.1s;--dur:4.0s;--rot:110deg;--clr:#13c2c2"></span>
            <span style="--x:92%;--delay:1.7s;--dur:4.7s;--rot:260deg;--clr:#f7b500"></span>
        </div>
    </main>

    <script>
        // นับถอยหลังและพาแอดมินเข้าหน้า Dashboard อัตโนมัติ
        (function () {
            var seconds = 5;
            var countdownEl = document.getElementById('countdown');
            var timer = setInterval(function () {
                seconds -= 1;
                if (countdownEl) {
                    countdownEl.textContent = String(seconds);
                }
                if (seconds <= 0) {
                    clearInterval(timer);
                    window.location.href = 'admin_dashboard.php';
                }
            }, 1000);
        })();
    </script>
</body>
</html>
