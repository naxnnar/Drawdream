<?php
// ไฟล์นี้: admin_dashboard.php
// หน้าที่: แดชบอร์ดภาพรวมสำหรับผู้ดูแลระบบ
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
$total_children    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_children WHERE deleted_at IS NULL"))['cnt'];
$pending_foundations = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_profile WHERE account_verified=0"))['cnt'];
$pendingProjExprDash = drawdream_sql_project_is_pending('project_status');
$pending_projects  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_project WHERE {$pendingProjExprDash} AND deleted_at IS NULL"))['cnt'];
$pending_needs     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_needlist WHERE approve_item='pending'"))['cnt'];
$escrow_total      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM escrow_funds WHERE status='holding'"))['total'];

$active_projects = mysqli_query($conn, "
    SELECT * FROM foundation_project
    WHERE project_status IN ('approved','completed') AND deleted_at IS NULL
    ORDER BY project_status DESC, project_id DESC
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
    <link rel="stylesheet" href="css/admin_dashboard.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body class="admin-dashboard-page">

<?php include 'navbar.php'; ?>

<div class="dashboard">
    <div class="dash-title">Dashboard ผู้ดูแลระบบ</div>

    <!-- Cards ภาพรวม: แยก 2 แถวเพื่อเว้นระยะแนวตั้งระหว่างแถวบน–ล่าง -->
    <div class="admin-metric-stats">
        <div class="admin-metric-stats__row admin-metric-stats__row--4">
            <a href="admin_donors.php" class="admin-stat-card-wrap" title="ดูรายชื่อผู้บริจาค">
                <div class="card stat-card donors">
                    <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                    <div class="stat-divider" aria-hidden="true"></div>
                    <div class="stat-content">
                        <div class="card-label">ผู้บริจาคทั้งหมด</div>
                        <div class="card-value"><?= number_format($total_donors, 0) ?><span class="card-value-suffix">คน</span></div>
                    </div>
                </div>
            </a>

            <a href="admin_escrow.php" class="admin-stat-card-wrap" title="จัดการ Escrow">
                <div class="card stat-card escrow">
                    <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
                    <div class="stat-divider" aria-hidden="true"></div>
                    <div class="stat-content">
                        <div class="card-label">เงินใน Escrow</div>
                        <div class="card-value"><?= number_format($escrow_total, 0) ?><span class="card-value-suffix">บาท</span></div>
                    </div>
                </div>
            </a>

            <a href="admin_foundations_overview.php" class="admin-stat-card-wrap" title="ดูรายชื่อมูลนิธิ">
                <div class="card stat-card foundations">
                    <div class="stat-icon"><i class="bi bi-bank2"></i></div>
                    <div class="stat-divider" aria-hidden="true"></div>
                    <div class="stat-content">
                        <div class="card-label">มูลนิธิทั้งหมด</div>
                        <div class="card-value"><?= number_format($total_foundations, 0) ?><span class="card-value-suffix">แห่ง</span></div>
                    </div>
                </div>
            </a>

            <a href="admin_children_overview.php" class="admin-stat-card-wrap" title="ดูรายชื่อเด็ก">
                <div class="card stat-card children">
                    <div class="stat-icon"><i class="bi bi-person-hearts"></i></div>
                    <div class="stat-divider" aria-hidden="true"></div>
                    <div class="stat-content">
                        <div class="card-label">เด็กทั้งหมด</div>
                        <div class="card-value"><?= number_format($total_children, 0) ?><span class="card-value-suffix">คน</span></div>
                    </div>
                </div>
            </a>
        </div>

        <div class="admin-metric-stats__row admin-metric-stats__row--2">
            <div class="card stat-card donation-total">
                <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="stat-divider" aria-hidden="true"></div>
                <div class="stat-content">
                    <div class="card-label">ยอดบริจาคทั้งหมด</div>
                    <div class="card-value"><?= number_format($total_donation, 0) ?><span class="card-value-suffix">บาท</span></div>
                </div>
            </div>

            <div class="card stat-card donation-today">
                <div class="stat-icon"><i class="bi bi-calendar2-check-fill"></i></div>
                <div class="stat-divider" aria-hidden="true"></div>
                <div class="stat-content">
                    <div class="card-label">ยอดบริจาควันนี้</div>
                    <div class="card-value"><?= number_format($today_donation, 0) ?><span class="card-value-suffix">บาท</span></div>
                </div>
            </div>
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
                <p class="empty-text">ยังไม่มีโครงการ</p>
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
                        <div class="don-right">
                            <div class="don-amount"><?= number_format((float)$don['amount'], 0) ?> บาท</div>
                            <div class="don-date"><?= date('d/m/Y H:i', strtotime($don['transfer_datetime'])) ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p class="empty-text">ยังไม่มีการบริจาค</p>
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