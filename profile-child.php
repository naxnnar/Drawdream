<?php
session_start();
include 'db.php';

$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$sql = "SELECT * FROM Children WHERE child_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $child_id);
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();

if (!$child) {
    echo "<script>alert('ไม่พบข้อมูลเด็กที่ระบุ'); window.location='donation.php';</script>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <title>โปรไฟล์ - <?php echo htmlspecialchars($child['child_name']); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background-color: #eeeeee;
        }

        /* ===== การ์ดหลักสีเหลือง ===== */
        .custom-profile-card {
            background-color: #F8CE32;
            border-radius: 40px;
            padding: 50px;
            position: relative;
            max-width: 1000px;
            margin: auto;
        }

        /* ป้ายด้านบน */
        .profile-label {
            position: absolute;
            top: -44px;
            left: 50px;
            background-color: #F8CE32;
            padding: 10px 28px;
            border-radius: 18px 18px 0 0;
            font-weight: 700;
            font-size: 1rem;
            color: #333;
        }

        /* ===== Layout: Flexbox 2 คอลัมน์แนวนอน ===== */
        .profile-inner {
            display: flex;
            flex-direction: row;
            gap: 40px;
            align-items: flex-start;
        }

        /* ===== คอลัมน์ซ้าย ===== */
        .col-left {
            flex: 0 0 300px;
            min-width: 260px;
        }

        /* กรอบรูปภาพ */
        .child-img-container {
            background-color: #E56B51;
            border-radius: 24px;
            width: 100%;
            height: 310px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .child-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top center;
        }

        /* ข้อมูลเด็กใต้รูป */
        .child-details {
            margin-top: 18px;
            color: #333;
            font-size: 0.95rem;
            line-height: 2;
        }

        .child-details p {
            margin: 0;
        }

        /* ===== คอลัมน์ขวา ===== */
        .col-right {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .brand-header {
            font-size: 3.5rem;
            font-weight: 900;
            color: #222;
            margin-bottom: 10px;
            letter-spacing: -1px;
            line-height: 1.1;
        }

        .donate-text {
            font-size: 1rem;
            color: #333;
            margin-bottom: 28px;
            line-height: 1.7;
        }

        /* ===== ปุ่มเลือกเงิน ===== */
        .money-row {
            display: flex;
            gap: 14px;
            width: 100%;
            margin-bottom: 14px;
        }

        .btn-money-choice {
            background-color: #fff;
            border: none;
            border-radius: 18px;
            padding: 18px 10px;
            font-size: 1.6rem;
            font-weight: 800;
            color: #222;
            flex: 1;
            cursor: pointer;
            transition: background-color 0.2s, color 0.2s;
        }

        .btn-money-choice:hover {
            background-color: #e0e0e0;
        }

        .btn-money-choice.active {
            background-color: #3f4f9a;
            color: #fff;
        }

        /* ===== กล่องจำนวนเงิน ===== */
        .amount-box {
            background-color: #fff;
            border-radius: 18px;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            min-height: 75px;
        }

        .amount-box input {
            border: none;
            background: transparent;
            font-size: 2rem;
            font-weight: 800;
            color: #222;
            width: 80%;
            outline: none;
            pointer-events: none;
        }

        .currency-label {
            font-size: 1.2rem;
            font-weight: 600;
            color: #aaa;
        }

        /* ===== ปุ่มบริจาค ===== */
        .btn-submit-donation {
            background-color: #4A5CB5;
            color: #fff;
            border: none;
            border-radius: 20px;
            padding: 20px;
            font-size: 1.8rem;
            font-weight: 800;
            width: 100%;
            margin-top: 18px;
            box-shadow: 0 7px 0 #2d3a8c;
            transition: transform 0.1s, box-shadow 0.1s;
            cursor: pointer;
            letter-spacing: 2px;
        }

        .btn-submit-donation:hover {
            background-color: #3d4fa8;
        }

        .btn-submit-donation:active {
            transform: translateY(5px);
            box-shadow: 0 2px 0 #2d3a8c;
        }

        /* ===== Responsive ===== */
        @media (max-width: 768px) {
            .profile-inner {
                flex-direction: column;
            }
            .col-left {
                flex: unset;
                width: 100%;
            }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<main class="container my-5">
    <div class="custom-profile-card">
        <div class="profile-label">เด็กในอุปการะ</div>

        <div class="profile-inner">

            <!-- ===== คอลัมน์ซ้าย: รูป + ข้อมูล ===== -->
            <div class="col-left">
                <div class="child-img-container">
                    <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile">
                </div>
                <div class="child-details">
                    <p><strong>ชื่อ:</strong> <?php echo htmlspecialchars($child['child_name']); ?></p>
                    <p><strong>ชั้น:</strong> <?php echo htmlspecialchars($child['education']); ?></p>
                    <p><strong>อายุ:</strong> <?php echo $child['age']; ?> ปี</p>
                    <p><strong>สิ่งที่ชอบ:</strong> หมากับแมว</p>
                    <p><strong>อาชีพในฝัน:</strong> <?php echo htmlspecialchars($child['dream']); ?></p>
                    <p><strong>พรที่ขอ:</strong> <?php echo htmlspecialchars($child['wish']); ?></p>
                </div>
            </div>

            <!-- ===== คอลัมน์ขวา: บริจาค ===== -->
            <div class="col-right">
                <h1 class="brand-header">Drawdream</h1>
                <p class="donate-text">
                    โครงการนี้เป็นการบริจาคเงิน 700 บาทต่อการอุปการะเด็ก 1 คน<br>
                    ในรูปแบบต่อเนื่องทุกๆ เดือน
                </p>

                <div class="money-row">
                    <button class="btn-money-choice" onclick="selectAmount(200, this)">200</button>
                    <button class="btn-money-choice" onclick="selectAmount(500, this)">500</button>
                    <button class="btn-money-choice" onclick="selectAmount(1000, this)">1000</button>
                </div>

                <div class="amount-box">
                    <input type="text" id="display-amount" readonly>
                    <span class="currency-label">บาท</span>
                </div>

                <button class="btn-submit-donation" onclick="processDonation(<?php echo $child['child_id']; ?>)">
                    บริจาค
                </button>
            </div>

        </div>
    </div>
</main>

<script>
    function selectAmount(amount, btn) {
        document.getElementById('display-amount').value = amount;
        document.querySelectorAll('.btn-money-choice').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
    }

    function processDonation(id) {
        const amount = document.getElementById('display-amount').value;
        if (!amount) {
            alert("กรุณาเลือกจำนวนเงินก่อนบริจาค");
            return;
        }
        window.location.href = `payment.php?amount=${amount}&child_id=${id}`;
    }
</script>

</body>
</html>