<?php
// admin_dashboard.php — แดชบอร์ดแอดมิน

if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/escrow_funds_schema.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$broadcast_flash_ok = '';
$broadcast_flash_err = '';
if (isset($_GET['broadcast']) && $_GET['broadcast'] === 'ok') {
    $sentN = (int)($_GET['sent'] ?? 0);
    $broadcast_flash_ok = $sentN > 0
        ? "ส่งแจ้งเตือนแล้ว {$sentN} ฉบับ"
        : 'ดำเนินการแล้ว (ไม่มีผู้รับในกลุ่มที่เลือก)';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_broadcast') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    $expected = (string) ($_SESSION['admin_broadcast_csrf'] ?? '');
    if ($csrf === '' || $expected === '' || !hash_equals($expected, $csrf)) {
        $broadcast_flash_err = 'เซสชันไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $recipient = (string) ($_POST['recipient'] ?? '');
        $message = (string) ($_POST['broadcast_message'] ?? '');
        $res = drawdream_admin_broadcast_notifications($conn, $recipient, $message);
        if ($res['error'] !== '') {
            $broadcast_flash_err = $res['error'];
        } else {
            $_SESSION['admin_broadcast_csrf'] = bin2hex(random_bytes(16));
            header('Location: admin_dashboard.php?broadcast=ok&sent=' . (int) $res['sent']);
            exit();
        }
    }
}

if (empty($_SESSION['admin_broadcast_csrf'])) {
    $_SESSION['admin_broadcast_csrf'] = bin2hex(random_bytes(16));
}
$broadcast_csrf = (string) $_SESSION['admin_broadcast_csrf'];

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
$pending_children_dash = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM foundation_children WHERE COALESCE(approve_profile, 'รอดำเนินการ') IN ('รอดำเนินการ', 'กำลังดำเนินการ') AND deleted_at IS NULL"))['cnt'] ?? 0);
// ยอดเงินพักโครงการ (สอดคล้อง admin_escrow.php — ใช้ escrow_funds.holding เมื่อมีข้อมูลในตาราง)
$escrow_total = drawdream_escrow_project_holding_total_display($conn);

$active_projects = [];
$aprRes = mysqli_query($conn, "
    SELECT * FROM foundation_project
    WHERE project_status IN ('approved','completed') AND deleted_at IS NULL
    ORDER BY project_status DESC, project_id DESC
    LIMIT 100
");
if ($aprRes) {
    while ($row = mysqli_fetch_assoc($aprRes)) {
        $active_projects[] = $row;
    }
}

$recent_donations = [];
$rdrRes = mysqli_query($conn, "
    SELECT d.*, dc.project_donate, dc.needitem_donate, dc.child_donate
    FROM donation d
    JOIN donate_category dc ON d.category_id = dc.category_id
    WHERE d.payment_status = 'completed'
    ORDER BY d.transfer_datetime DESC
    LIMIT 100
");
if ($rdrRes) {
    while ($row = mysqli_fetch_assoc($rdrRes)) {
        $recent_donations[] = $row;
    }
}

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
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
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

    <?php if ($broadcast_flash_ok !== ''): ?>
        <div class="admin-broadcast-flash admin-broadcast-flash--ok" role="status"><?= htmlspecialchars($broadcast_flash_ok) ?></div>
    <?php endif; ?>
    <?php if ($broadcast_flash_err !== ''): ?>
        <div class="admin-broadcast-flash admin-broadcast-flash--err" role="alert"><?= htmlspecialchars($broadcast_flash_err) ?></div>
    <?php endif; ?>

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

            <a href="children_.php" class="admin-stat-card-wrap" title="ดูรายชื่อเด็ก (Profilechildren)">
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

    <!-- คำขอรออนุมัติ — ศูนย์รวมที่ไอคอนกระดิ่ง -->
    <div class="pending-row">
        <a href="admin_notifications.php#admin-pending-foundations" class="pending-card">
            <div>
                <div class="pending-label">มูลนิธิรออนุมัติ</div>
                <div class="pending-sub">เปิดจากกระดิ่ง / คลิกที่นี่</div>
            </div>
            <div class="pending-count <?= $pending_foundations == 0 ? 'zero' : '' ?>"><?= $pending_foundations ?></div>
        </a>
        <a href="admin_notifications.php#admin-pending-projects" class="pending-card">
            <div>
                <div class="pending-label">โครงการรออนุมัติ</div>
                <div class="pending-sub">เปิดจากกระดิ่ง / คลิกที่นี่</div>
            </div>
            <div class="pending-count <?= $pending_projects == 0 ? 'zero' : '' ?>"><?= $pending_projects ?></div>
        </a>
        <a href="admin_notifications.php#admin-pending-needs" class="pending-card">
            <div>
                <div class="pending-label">สิ่งของรออนุมัติ</div>
                <div class="pending-sub">เปิดจากกระดิ่ง / คลิกที่นี่</div>
            </div>
            <div class="pending-count <?= $pending_needs == 0 ? 'zero' : '' ?>"><?= $pending_needs ?></div>
        </a>
    </div>
    <div class="pending-row pending-row--children-actions">
        <a href="admin_notifications.php#admin-pending-children" class="pending-card">
            <div>
                <div class="pending-label">โปรไฟล์เด็กรออนุมัติ</div>
                <div class="pending-sub">ศูนย์รวมคำขอ — ไอคอนกระดิ่งมุมข้างบน</div>
            </div>
            <div class="pending-count <?= $pending_children_dash === 0 ? 'zero' : '' ?>"><?= $pending_children_dash ?></div>
        </a>
        <button type="button" class="pending-card pending-card--button" id="jsOpenBroadcastModal" aria-haspopup="dialog">
            <div>
                <div class="pending-label">ส่งข้อความแจ้งเตือน</div>
                <div class="pending-sub">ส่งประกาศไปที่กระดิ่งผู้ใช้</div>
            </div>
            <div class="pending-count pending-count--icon">✉</div>
        </button>
        <div class="pending-card pending-card--ghost" aria-hidden="true"></div>
    </div>

    <div class="admin-broadcast-modal" id="broadcastModal" role="dialog" aria-modal="true" aria-labelledby="broadcastModalTitle" hidden>
        <div class="admin-broadcast-modal__backdrop" data-broadcast-close tabindex="-1"></div>
        <div class="admin-broadcast-modal__panel">
            <h2 class="admin-broadcast-modal__title" id="broadcastModalTitle">ส่งข้อความแจ้งเตือน</h2>
            <p class="admin-broadcast-modal__hint">ข้อความจะแสดงที่กระดิ่งของผู้ใช้ที่เลือก (แจ้งเตือนทั่วไป)</p>
            <form method="post" action="admin_dashboard.php">
                <input type="hidden" name="action" value="admin_broadcast">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($broadcast_csrf, ENT_QUOTES, 'UTF-8') ?>">
                <fieldset class="admin-broadcast-fieldset">
                    <legend class="admin-broadcast-legend">ส่งถึง</legend>
                    <label class="admin-broadcast-radio"><input type="radio" name="recipient" value="donors" required> ผู้บริจาค</label>
                    <label class="admin-broadcast-radio"><input type="radio" name="recipient" value="foundations"> มูลนิธิ</label>
                    <label class="admin-broadcast-radio"><input type="radio" name="recipient" value="both" checked> ทั้งผู้บริจาคและมูลนิธิ</label>
                </fieldset>
                <label class="admin-broadcast-label" for="broadcast_message">ข้อความ</label>
                <textarea class="admin-broadcast-textarea" name="broadcast_message" id="broadcast_message" required maxlength="4000" rows="6" placeholder="พิมพ์ข้อความที่ต้องการแจ้ง..."></textarea>
                <div class="admin-broadcast-modal__actions">
                    <button type="button" class="btn-broadcast-cancel" data-broadcast-close>ยกเลิก</button>
                    <button type="submit" class="btn-broadcast-submit">ส่งแจ้งเตือน</button>
                </div>
            </form>
        </div>
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
                <?php if (count($active_projects) > 5): ?>
                    <button type="button" class="section-link section-link-btn" id="btnDashProjectsMoreTop">ดูทั้งหมด</button>
                <?php else: ?>
                    <span class="section-link section-link--muted">ล่าสุดครบแล้ว</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($active_projects)): ?>
                <div class="admin-dash-list" id="adminDashProjectsList">
                    <?php foreach ($active_projects as $idx => $proj): ?>
                        <?php
                            $goal    = (float)($proj['goal_amount'] ?? 0);
                            $current = (float)($proj['current_donate'] ?? 0);
                            $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
                            $st      = $proj['project_status'];
                            $proj_extra = $idx >= 5;
                        ?>
                        <div class="proj-item<?= $proj_extra ? ' proj-item--extra' : '' ?>"<?= $proj_extra ? ' hidden' : '' ?>>
                            <div class="proj-name">
                                <?= htmlspecialchars($proj['project_name']) ?>
                                <?php
                                $projPillClass = ($st === 'approved')
                                    ? 'admin-pill admin-pill--success'
                                    : 'admin-pill admin-pill--neutral';
                                ?>
                                <span class="<?= htmlspecialchars($projPillClass) ?>">
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
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty-text">ยังไม่มีโครงการ</p>
            <?php endif; ?>
        </div>

        <div class="section-box">
            <div class="section-title">
                การบริจาคล่าสุด
                <?php if (count($recent_donations) > 5): ?>
                    <button type="button" class="section-link section-link-btn" id="btnDashDonationsMoreTop">ดูทั้งหมด</button>
                <?php else: ?>
                    <span class="section-link section-link--muted">ล่าสุดครบแล้ว</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($recent_donations)): ?>
                <div class="admin-dash-list" id="adminDashDonationsList">
                    <?php foreach ($recent_donations as $idx => $don): ?>
                        <?php $don_extra = $idx >= 5; ?>
                        <div class="don-item<?= $don_extra ? ' don-item--extra' : '' ?>"<?= $don_extra ? ' hidden' : '' ?>>
                            <div>
                                <div class="don-type">
                                    <?php if (drawdream_donate_cat_label_is_active($don['project_donate'] ?? null)): ?>บริจาคโครงการ
                                    <?php elseif (drawdream_donate_cat_label_is_active($don['needitem_donate'] ?? null)): ?>บริจาคสิ่งของ
                                    <?php elseif (drawdream_donate_cat_label_is_active($don['child_donate'] ?? null)): ?>บริจาคให้เด็ก
                                    <?php else: ?>บริจาค<?php endif; ?>
                                </div>
                                <div class="don-ref"><?= htmlspecialchars($don['omise_charge_id'] ?? '-') ?></div>
                            </div>
                            <div class="don-right">
                                <div class="don-amount"><?= number_format((float)$don['amount'], 0) ?> บาท</div>
                                <div class="don-date"><?= date('d/m/Y H:i', strtotime($don['transfer_datetime'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
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

<script>
(function () {
    const modal = document.getElementById('broadcastModal');
    const openBtn = document.getElementById('jsOpenBroadcastModal');
    if (!modal || !openBtn) return;

    const setOpen = function (open) {
        modal.hidden = !open;
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.classList.toggle('admin-broadcast-modal-open', open);
        if (open) {
            const ta = document.getElementById('broadcast_message');
            if (ta) ta.focus();
        }
    };

    openBtn.addEventListener('click', function () { setOpen(true); });
    modal.querySelectorAll('[data-broadcast-close]').forEach(function (el) {
        el.addEventListener('click', function () { setOpen(false); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) setOpen(false);
    });
})();
</script>

<script>
(function () {
    function expandSection(btnId, wrapId, extraSelector) {
        var btn = document.getElementById(btnId);
        var wrap = document.getElementById(wrapId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            document.querySelectorAll(extraSelector).forEach(function (el) {
                el.hidden = false;
            });
            if (wrap) wrap.style.display = 'none';
        });
    }
    expandSection('btnDashProjectsMoreTop', '', '#adminDashProjectsList .proj-item--extra');
    expandSection('btnDashDonationsMoreTop', '', '#adminDashDonationsList .don-item--extra');
})();
</script>

</body>
</html>