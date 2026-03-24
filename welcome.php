<?php
// ไฟล์นี้: welcome.php
// หน้าที่: หน้าต้อนรับสำหรับผู้ใช้ทั้งหมด (Admin, Donor, Foundation)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?page=login&step=choose');
    exit();
}

// เช็คว่าแสดงหน้านี้เฉพาะครั้งแรกหลัง login
if (empty($_SESSION['show_welcome'])) {
    // ถ้า admin ให้ไปแอดมิน dashboard แทน
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: homepage.php');
    }
    exit();
}
unset($_SESSION['show_welcome']);

include 'db.php';

$user_name = '';
$user_role = $_SESSION['role'] ?? '';
$greeting = '';

// ดึงชื่อผู้ใช้ตามแต่ละ role
if ($user_role === 'admin') {
    $user_name = 'Admin';
    $greeting = 'ยินดีต้อนรับกลับเข้าสู่ระบบผู้ดูแล DrawDream';
    
} elseif ($user_role === 'foundation') {
    $stmt = $conn->prepare("SELECT foundation_name FROM foundation_profile WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_name = htmlspecialchars($row['foundation_name']);
    }
    $greeting = 'ขอบคุณที่เชื่อใจและไว้วางใจ DrawDream เพื่อช่วยเหลือเด็กๆ';
    
} elseif ($user_role === 'donor') {
    $stmt = $conn->prepare("SELECT first_name, last_name FROM donor WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_name = trim(htmlspecialchars($row['first_name'] . ' ' . $row['last_name']));
    }
    $greeting = 'ขอบคุณที่เป็นส่วนหนึ่งของการช่วยเหลือเด็กๆ ของเรา';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome | DrawDream</title>
    <link rel="stylesheet" href="css/welcome.css">
</head>
<body>
    <div class="welcome-bg-shape shape-a" aria-hidden="true"></div>
    <div class="welcome-bg-shape shape-b" aria-hidden="true"></div>

    <main class="welcome-wrap">
        <section class="welcome-card" role="status" aria-live="polite">
            <div class="welcome-logo">
                <img src="img/logodrawdream.png" alt="DrawDream" class="logo-img">
            </div>
            
            <h2 class="welcome-text">Welcome</h2>
            <h1 class="welcome-name"><?php echo $user_name; ?></h1>
            <p class="welcome-message"><?php echo $greeting; ?></p>
            
            <a href="<?php 
                if ($user_role === 'admin') {
                    echo 'admin_dashboard.php';
                } else {
                    echo 'homepage.php';
                }
            ?>" class="welcome-btn">เข้าสู่ระบบ</a>
            
            <small class="welcome-auto-redirect">ระบบจะเปลี่ยนหน้าอัตโนมัติใน <span id="countdown">3</span> วินาที</small>
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
        // นับถอยหลังและพาเข้าหน้าหลักอัตโนมัติ
        (function () {
            var seconds = 3;
            var countdownEl = document.getElementById('countdown');
            var timer = setInterval(function () {
                seconds -= 1;
                if (countdownEl) {
                    countdownEl.textContent = String(seconds);
                }
                if (seconds <= 0) {
                    clearInterval(timer);
                    var role = '<?php echo $user_role; ?>';
                    if (role === 'admin') {
                        window.location.href = 'admin_dashboard.php';
                    } else {
                        window.location.href = 'homepage.php';
                    }
                }
            }, 1000);
        })();
    </script>
</body>
</html>
