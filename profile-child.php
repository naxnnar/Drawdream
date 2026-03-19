<?php
session_start();
include 'db.php';

$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$role = $_SESSION['role'] ?? 'donor';
$isAdmin = ($role === 'admin');

$sql = "
    SELECT c.*, COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) AS display_foundation_name
    FROM Children c
    LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
    WHERE c.child_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $child_id);
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();

if (!$child) {
    echo "<script>alert('ไม่พบข้อมูลเด็กที่ระบุ'); window.location='donation.php';</script>";
    exit();
}

$birthDateText = '-';
if (!empty($child['birth_date'] ?? '')) {
    $birthDateText = date('d/m/Y', strtotime($child['birth_date']));
}

$reviewStatus = $child['approve_profile'] ?? 'รอดำเนินการ';
if ($reviewStatus === 'กำลังดำเนินการ') {
    $reviewStatus = 'รอดำเนินการ';
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
        body { background-color: #f2f4f7; }

        .admin-review-card {
            max-width: 1100px;
            margin: 24px auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .admin-review-header {
            background: #1e2f97;
            color: #fff;
            padding: 18px 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            position: relative;
            text-align: center;
        }

        .admin-review-title {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .admin-review-title h4,
        .admin-review-title div {
            color: #fff;
        }

        .admin-review-body {
            padding: 24px;
        }

        .admin-review-layout {
            max-width: 760px;
            margin: 0 auto;
            justify-content: center;
        }

        .admin-image-col {
            display: flex;
            justify-content: center;
            width: 100%;
            flex: 0 0 100%;
        }

        .admin-details-col {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .admin-child-image {
            width: 100%;
            max-width: 320px;
            max-height: 360px;
            object-fit: cover;
            border-radius: 14px;
            background: #f3f3f3;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
        }

        .data-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            width: 100%;
            max-width: 720px;
            margin: 0 auto;
        }

        .data-item {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .data-item.full {
            grid-column: 1 / -1;
        }

        .label {
            display: block;
            font-size: 0.86rem;
            color: #5b6470;
            margin-bottom: 4px;
        }

        .value {
            font-weight: 600;
            color: #1f2937;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .status-approved { background: #7683be, #3f4f9a }
        .status-pending { background: #f6c744, #efb81f }
        .status-rejected { background: #ef5350, #e53935 }

        .admin-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .admin-actions .btn {
            min-width: 150px;
            min-height: 48px;
            padding: 12px 20px;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 800;
            box-shadow: 0 10px 18px rgba(15, 23, 42, 0.12);
        }

        .admin-actions .btn-primary {
            background: linear-gradient(135deg, #f6c744, #efb81f);
            color: #1f2937;
        }

        .admin-actions .btn-danger {
            background: linear-gradient(135deg, #ef5350, #e53935);
            color: #fff;
        }

        .admin-actions .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 24px rgba(15, 23, 42, 0.14);
        }

        .custom-profile-card {
            background-color: #F8CE32;
            border-radius: 40px;
            padding: 50px;
            position: relative;
            max-width: 1000px;
            margin: auto;
        }

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

        .profile-inner {
            display: flex;
            flex-direction: row;
            gap: 40px;
            align-items: flex-start;
        }

        .col-left { flex: 0 0 300px; min-width: 260px; }

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

        .child-details {
            margin-top: 18px;
            color: #333;
            font-size: 0.95rem;
            line-height: 2;
        }

        .child-details p { margin: 0; }

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

        .btn-money-choice.active {
            background-color: #3f4f9a;
            color: #fff;
        }

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
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .data-grid { grid-template-columns: 1fr; }
            .profile-inner { flex-direction: column; }
            .col-left { width: 100%; }
            .admin-review-header {
                flex-direction: column;
                align-items: center;
                padding-top: 56px;
            }

            .admin-details-col {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<?php if ($isAdmin): ?>
<main class="container-fluid my-4">
    <div class="admin-review-card">
        <div class="admin-review-header">
            <div class="admin-review-title">
                <h4 class="mb-1">ตรวจสอบโปรไฟล์เด็ก</h4>
                <div>มูลนิธิ: <?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></div>
            </div>
            <a href="donation.php" class="btn btn-light btn-sm" style="position:absolute; right:24px; top:50%; transform:translateY(-50%);">กลับหน้ารายการเด็ก</a>
        </div>

        <div class="admin-review-body">
            <div class="row g-4 admin-review-layout">
                <div class="col-lg-4 admin-image-col">
                    <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile" class="admin-child-image">
                </div>

                <div class="col-lg-8 admin-details-col">
                    <div class="data-grid">
                        <div class="data-item">
                            <span class="label">ชื่อเด็ก</span>
                            <span class="value"><?php echo htmlspecialchars($child['child_name']); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">มูลนิธิ</span>
                            <span class="value"><?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">วันเกิด</span>
                            <span class="value"><?php echo htmlspecialchars($birthDateText); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">อายุ</span>
                            <span class="value"><?php echo (int)$child['age']; ?> ปี</span>
                        </div>
                        <div class="data-item">
                            <span class="label">ระดับการศึกษา</span>
                            <span class="value"><?php echo htmlspecialchars($child['education'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ความฝัน</span>
                            <span class="value"><?php echo htmlspecialchars($child['dream'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">สิ่งที่อยากขอ / ความต้องการ</span>
                            <span class="value"><?php echo htmlspecialchars($child['wish'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ธนาคาร</span>
                            <span class="value"><?php echo htmlspecialchars($child['bank_name'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">เลขบัญชี</span>
                            <span class="value"><?php echo htmlspecialchars($child['child_bank'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สถานะการอุปการะ</span>
                            <span class="value"><?php echo htmlspecialchars($child['status'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สถานะการตรวจสอบ</span>
                            <?php
                              $cls = 'status-pending';
                                                            if ($reviewStatus === 'อนุมัติ') $cls = 'status-approved';
                                                            if ($reviewStatus === 'ไม่อนุมัติ') $cls = 'status-rejected';
                            ?>
                                                        <span class="status-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($reviewStatus); ?></span>
                        </div>
                    </div>

                                        <?php if ($reviewStatus !== 'อนุมัติ'): ?>
                    <div class="admin-actions">
                        <a href="approve_process.php?id=<?php echo (int)$child['child_id']; ?>&action=approve" class="btn btn-primary"
                           onclick="return confirm('ยืนยันอนุมัติโปรไฟล์เด็กคนนี้?');">อนุมัติ</a>
                        <a href="approve_process.php?id=<?php echo (int)$child['child_id']; ?>&action=reject" class="btn btn-danger"
                           onclick="return confirm('ยืนยันไม่อนุมัติโปรไฟล์เด็กคนนี้?');">ไม่อนุมัติ</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<?php else: ?>
<main class="container my-5">
    <div class="custom-profile-card">
        <div class="profile-label">เด็กในอุปการะ</div>

        <div class="profile-inner">
            <div class="col-left">
                <div class="child-img-container">
                    <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile">
                </div>
                <div class="child-details">
                    <p><strong>ชื่อ:</strong> <?php echo htmlspecialchars($child['child_name']); ?></p>
                    <p><strong>มูลนิธิ:</strong> <?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></p>
                    <p><strong>วันเกิด:</strong> <?php echo htmlspecialchars($birthDateText); ?></p>
                    <p><strong>ชั้น:</strong> <?php echo htmlspecialchars($child['education']); ?></p>
                    <p><strong>อายุ:</strong> <?php echo (int)$child['age']; ?> ปี</p>
                    <p><strong>อาชีพในฝัน:</strong> <?php echo htmlspecialchars($child['dream']); ?></p>
                    <p><strong>พรที่ขอ:</strong> <?php echo htmlspecialchars($child['wish']); ?></p>
                </div>
            </div>

            <div class="col-right">
                <h1 class="brand-header">Drawdream</h1>
                <p class="donate-text">
                    โครงการนี้เป็นการบริจาคเงิน 700 บาทต่อการอุปการะเด็ก 1 คน<br>
                    ในรูปแบบต่อเนื่องทุกๆ เดือน
                </p>

                <?php if ($role === 'donor'): ?>
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
                <?php else: ?>
                <a href="donation.php" class="btn btn-dark mt-3">กลับหน้ารายการเด็ก</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php endif; ?>

<script>
function selectAmount(amount, btn) {
    const amountInput = document.getElementById('display-amount');
    if (!amountInput) return;
    amountInput.value = amount;
    document.querySelectorAll('.btn-money-choice').forEach(el => el.classList.remove('active'));
    btn.classList.add('active');
}

function processDonation(id) {
    const amountInput = document.getElementById('display-amount');
    if (!amountInput || !amountInput.value) {
        alert("กรุณาเลือกจำนวนเงินก่อนบริจาค");
        return;
    }
    window.location.href = `payment.php?amount=${amountInput.value}&child_id=${id}`;
}
</script>

</body>
</html>
