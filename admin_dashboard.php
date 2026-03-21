<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// ======== ดึงข้อมูลทั้งหมด ========
$total_donation    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM donation WHERE payment_status='completed'"))['total'];
$today_donation    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM donation WHERE payment_status='completed' AND DATE(transfer_datetime) = CURDATE()"))['total'];
$total_donors      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM donor"))['cnt'];
$total_foundations = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_profile"))['cnt'];
$pending_foundations = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_profile WHERE account_verified=0"))['cnt'];
$pending_projects  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM project WHERE project_status='pending'"))['cnt'];
$pending_needs     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_needlist WHERE approve_item='pending'"))['cnt'];
$escrow_total      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM escrow_funds WHERE status='holding'"))['total'];

$active_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name 
    FROM project p
    LEFT JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status IN ('approved','completed')
    ORDER BY p.project_status DESC, p.project_id DESC
    LIMIT 10
");

$recent_donations = mysqli_query($conn, "
    SELECT d.*, dc.project_donate, dc.needitem_donate, pt.omise_charge_id
    FROM donation d
    JOIN donate_category dc ON d.category_id = dc.category_id
    LEFT JOIN payment_transaction pt ON pt.donate_id = d.donate_id
    WHERE d.payment_status = 'completed'
    ORDER BY d.transfer_datetime DESC
    LIMIT 15
");

// ======== ดึงข้อมูลกราฟ 30 วันล่าสุด ========
$chart_data = mysqli_query($conn, "
    SELECT 
        DATE(transfer_datetime) AS donate_date,
        COALESCE(SUM(amount), 0) AS total
    FROM donation
    WHERE payment_status = 'completed'
      AND transfer_datetime >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(transfer_datetime)
    ORDER BY donate_date ASC
");

// สร้าง array 30 วัน (ถ้าวันไหนไม่มีข้อมูลให้เป็น 0)
$chart_labels = [];
$chart_values = [];
$chart_map = [];
while ($row = mysqli_fetch_assoc($chart_data)) {
    $chart_map[$row['donate_date']] = (float)$row['total'];
}
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('d/m', strtotime($date));
    $chart_values[] = $chart_map[$date] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
    body { background:#f4f6f9; font-family:'Prompt','Sarabun',sans-serif; margin:0; }
    .dashboard { max-width:1400px; margin:30px auto; padding:0 20px; }
    .dash-title { font-size:26px; font-weight:700; color:#333; margin-bottom:25px; }

    .cards { display:grid; grid-template-columns:repeat(4,1fr); gap:20px; margin-bottom:30px; }
    .card { background:white; border-radius:15px; padding:25px; box-shadow:0 2px 10px rgba(0,0,0,0.07); border-left:5px solid #ddd; }
    .card.blue   { border-left-color:#4A5BA8; }
    .card.green  { border-left-color:#4CAF50; }
    .card.orange { border-left-color:#FF9800; }
    .card.purple { border-left-color:#9C27B0; }
    .card.teal   { border-left-color:#009688; }
    .card-label  { font-size:13px; color:#999; margin-bottom:8px; }
    .card-value  { font-size:28px; font-weight:700; color:#333; }
    .card-sub    { font-size:12px; color:#bbb; margin-top:5px; }

    .pending-row { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-bottom:30px; }
    .pending-card { background:white; border-radius:15px; padding:20px 25px; box-shadow:0 2px 10px rgba(0,0,0,0.07); display:flex; justify-content:space-between; align-items:center; text-decoration:none; color:#333; transition:all 0.3s; }
    .pending-card:hover { transform:translateY(-3px); box-shadow:0 6px 20px rgba(0,0,0,0.12); }
    .pending-label { font-size:15px; font-weight:600; }
    .pending-sub   { font-size:12px; color:#999; margin-top:3px; }
    .pending-count { font-size:36px; font-weight:700; color:#E74C3C; }
    .pending-count.zero { color:#4CAF50; }

    /* กราฟ */
    .chart-box { background:white; border-radius:15px; padding:25px; box-shadow:0 2px 10px rgba(0,0,0,0.07); margin-bottom:30px; }
    .chart-title { font-size:16px; font-weight:700; color:#333; margin-bottom:20px; padding-bottom:12px; border-bottom:2px solid #f0f0f0; }
    .chart-wrap { position:relative; height:260px; }

    .sections { display:grid; grid-template-columns:1.2fr 1fr; gap:20px; }
    .section-box { background:white; border-radius:15px; padding:25px; box-shadow:0 2px 10px rgba(0,0,0,0.07); }
    .section-title { font-size:16px; font-weight:700; color:#333; margin-bottom:20px; padding-bottom:12px; border-bottom:2px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; }
    .section-link { font-size:12px; color:#4A5BA8; text-decoration:none; font-weight:500; }

    .proj-item { padding:12px 0; border-bottom:1px solid #f5f5f5; }
    .proj-item:last-child { border-bottom:none; }
    .proj-name { font-size:14px; font-weight:600; color:#333; margin-bottom:5px; display:flex; justify-content:space-between; }
    .proj-foundation { font-size:12px; color:#999; margin-bottom:6px; }
    .proj-bar-bg { background:#f0f0f0; border-radius:6px; height:8px; overflow:hidden; }
    .proj-bar-fill { height:100%; border-radius:6px; background:linear-gradient(90deg,#4A5BA8,#667eea); }
    .proj-amount { font-size:11px; color:#aaa; margin-top:4px; display:flex; justify-content:space-between; }
    .status-badge { font-size:11px; padding:2px 8px; border-radius:8px; font-weight:600; }
    .status-approved  { background:#e8f5e9; color:#4CAF50; }
    .status-completed { background:#e8eaf6; color:#4A5BA8; }

    .don-item { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #f5f5f5; font-size:13px; }
    .don-item:last-child { border-bottom:none; }
    .don-type   { font-weight:600; color:#333; margin-bottom:2px; }
    .don-ref    { font-size:11px; color:#bbb; }
    .don-amount { font-weight:700; color:#E74C3C; white-space:nowrap; }
    .don-date   { font-size:11px; color:#bbb; text-align:right; margin-top:2px; }

    @media (max-width:1024px) {
        .cards       { grid-template-columns:repeat(2,1fr); }
        .pending-row { grid-template-columns:1fr; }
        .sections    { grid-template-columns:1fr; }
    }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="dashboard">
    <div class="dash-title">Dashboard ผู้ดูแลระบบ</div>

    <!-- Cards ภาพรวม -->
    <div class="cards">
        <div class="card blue">
            <div class="card-label">ยอดบริจาคทั้งหมด</div>
            <div class="card-value"><?= number_format($total_donation, 0) ?></div>
            <div class="card-sub">บาท</div>
        </div>
        <div class="card green">
            <div class="card-label">ยอดบริจาควันนี้</div>
            <div class="card-value"><?= number_format($today_donation, 0) ?></div>
            <div class="card-sub">บาท</div>
        </div>
        <div class="card orange">
            <div class="card-label">เงินใน Escrow</div>
            <div class="card-value"><?= number_format($escrow_total, 0) ?></div>
            <div class="card-sub">บาท (รอจัดซื้อ)</div>
        </div>
        <div class="card purple">
            <div class="card-label">ผู้บริจาคทั้งหมด</div>
            <div class="card-value"><?= number_format($total_donors, 0) ?></div>
            <div class="card-sub">คน</div>
        </div>
        <div class="card teal">
            <div class="card-label">มูลนิธิในระบบ</div>
            <div class="card-value"><?= number_format($total_foundations, 0) ?></div>
            <div class="card-sub">มูลนิธิ</div>
        </div>
    </div>

    <!-- รายการรออนุมัติ -->
    <div class="pending-row">
        <a href="admin_approve_foundation.php" class="pending-card">
            <div>
                <div class="pending-label">มูลนิธิรออนุมัติ</div>
                <div class="pending-sub">คลิกเพื่อตรวจสอบ</div>
            </div>
            <div class="pending-count <?= $pending_foundations == 0 ? 'zero' : '' ?>"><?= $pending_foundations ?></div>
        </a>
        <a href="admin_approve_projects.php" class="pending-card">
            <div>
                <div class="pending-label">โครงการรออนุมัติ</div>
                <div class="pending-sub">คลิกเพื่อตรวจสอบ</div>
            </div>
            <div class="pending-count <?= $pending_projects == 0 ? 'zero' : '' ?>"><?= $pending_projects ?></div>
        </a>
        <a href="admin_approve_needlist.php" class="pending-card">
            <div>
                <div class="pending-label">สิ่งของรออนุมัติ</div>
                <div class="pending-sub">คลิกเพื่อตรวจสอบ</div>
            </div>
            <div class="pending-count <?= $pending_needs == 0 ? 'zero' : '' ?>"><?= $pending_needs ?></div>
        </a>
    </div>

    <!-- กราฟยอดบริจาค 30 วันล่าสุด -->
    <div class="chart-box">
        <div class="chart-title">📈 ยอดบริจาครายวัน — 30 วันล่าสุด</div>
        <div class="chart-wrap">
            <canvas id="donationChart"></canvas>
        </div>
    </div>

    <!-- โครงการ + ประวัติบริจาค -->
    <div class="sections">
        <div class="section-box">
            <div class="section-title">
                โครงการที่ดำเนินอยู่
                <a href="project.php" class="section-link">ดูทั้งหมด</a>
            </div>
            <?php if ($active_projects && mysqli_num_rows($active_projects) > 0): ?>
                <?php while ($proj = mysqli_fetch_assoc($active_projects)): ?>
                    <?php
                        $goal    = (float)($proj['goal_amount'] ?? 0);
                        $current = (float)($proj['current_donate'] ?? 0);
                        $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
                        $st      = $proj['project_status'];
                    ?>
                    <div class="proj-item">
                        <div class="proj-name">
                            <?= htmlspecialchars($proj['project_name']) ?>
                            <span class="status-badge status-<?= $st ?>">
                                <?= $st === 'approved' ? 'กำลังระดม' : 'สำเร็จแล้ว' ?>
                            </span>
                        </div>
                        <div class="proj-foundation"><?= htmlspecialchars($proj['foundation_name'] ?? '-') ?></div>
                        <div class="proj-bar-bg">
                            <div class="proj-bar-fill" style="width:<?= (int)$percent ?>%"></div>
                        </div>
                        <div class="proj-amount">
                            <span><?= number_format($current, 0) ?> บาท</span>
                            <span>เป้า <?= number_format($goal, 0) ?> บาท (<?= round($percent) ?>%)</span>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color:#999;text-align:center;padding:20px;">ยังไม่มีโครงการ</p>
            <?php endif; ?>
        </div>

        <div class="section-box">
            <div class="section-title">
                การบริจาคล่าสุด
                <a href="admin_escrow.php" class="section-link">ดู Escrow</a>
            </div>
            <?php if ($recent_donations && mysqli_num_rows($recent_donations) > 0): ?>
                <?php while ($don = mysqli_fetch_assoc($recent_donations)): ?>
                    <div class="don-item">
                        <div>
                            <div class="don-type">
                                <?php if (!empty($don['project_donate'])): ?>บริจาคโครงการ
                                <?php elseif (!empty($don['needitem_donate'])): ?>บริจาคสิ่งของ
                                <?php else: ?>บริจาค<?php endif; ?>
                            </div>
                            <div class="don-ref"><?= htmlspecialchars($don['omise_charge_id'] ?? '-') ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div class="don-amount"><?= number_format((float)$don['amount'], 0) ?> บาท</div>
                            <div class="don-date"><?= date('d/m/Y H:i', strtotime($don['transfer_datetime'])) ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color:#999;text-align:center;padding:20px;">ยังไม่มีการบริจาค</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const labels = <?= json_encode($chart_labels) ?>;
const values = <?= json_encode($chart_values) ?>;

const ctx = document.getElementById('donationChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: 'ยอดบริจาค (บาท)',
            data: values,
            backgroundColor: 'rgba(74, 91, 168, 0.15)',
            borderColor: '#4A5BA8',
            borderWidth: 2,
            borderRadius: 6,
            pointBackgroundColor: '#4A5BA8',
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => '฿' + ctx.parsed.y.toLocaleString()
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { font: { size: 11 }, maxRotation: 45 }
            },
            y: {
                beginAtZero: true,
                grid: { color: '#f0f0f0' },
                ticks: {
                    font: { size: 11 },
                    callback: val => '฿' + val.toLocaleString()
                }
            }
        }
    }
});
</script>

</body>
</html>