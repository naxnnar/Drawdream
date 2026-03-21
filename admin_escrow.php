<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$admin_id = (int)$_SESSION['user_id'];
$success  = "";
$error    = "";

// ======== ประมวลผล POST ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $project_id = (int)($_POST['project_id'] ?? 0);
    $fid        = (int)($_POST['fid'] ?? 0);

    // ---- โครงการ: เปลี่ยน status เป็น purchasing ----
    if ($action === 'start_purchase' && $project_id) {
        $conn->query("UPDATE project SET project_status = 'purchasing' WHERE project_id = $project_id");
        $success = "เริ่มดำเนินการจัดซื้อโครงการแล้ว";
    }

    // ---- โครงการ: อัปโหลดหลักฐาน + เปลี่ยน status เป็น done ----
    if ($action === 'upload_evidence' && $project_id) {
        $desc = trim($_POST['description'] ?? '');
        $evidence_image = '';

        if (isset($_FILES['evidence_image']) && $_FILES['evidence_image']['error'] === 0) {
            $uploadDir = "uploads/evidence/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext     = strtolower(pathinfo($_FILES['evidence_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $newName = time() . "_" . uniqid() . "." . $ext;
                if (move_uploaded_file($_FILES['evidence_image']['tmp_name'], $uploadDir . $newName)) {
                    $evidence_image = $newName;
                }
            } else {
                $error = "อนุญาตเฉพาะไฟล์รูปเท่านั้น";
            }
        }

        if (!$error) {
            $stmt = $conn->prepare("INSERT INTO evidence (project_id, admin_id, evidence_image, description, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("iiss", $project_id, $admin_id, $evidence_image, $desc);
            $stmt->execute();

            $conn->query("UPDATE project SET project_status = 'done' WHERE project_id = $project_id");

            $proj = mysqli_fetch_assoc(mysqli_query($conn, "SELECT current_donate FROM project WHERE project_id = $project_id"));
            $total_collected    = (float)($proj['current_donate'] ?? 0);
            $service_fee        = round($total_collected * 0.05, 2);
            $amount_to_transfer = $total_collected;

            $cat = mysqli_fetch_assoc(mysqli_query($conn, "SELECT category_id FROM donate_category WHERE project_donate IS NOT NULL LIMIT 1"));
            $category_id = (int)($cat['category_id'] ?? 1);

            $stmt2 = $conn->prepare("INSERT INTO fund_disbursement (category_id, total_collected, amount_to_transfer, transfer_at) VALUES (?, ?, ?, NOW())");
            $stmt2->bind_param("idd", $category_id, $total_collected, $amount_to_transfer);
            $stmt2->execute();

            $success = "อัปโหลดหลักฐานสำเร็จ! โครงการเสร็จสมบูรณ์แล้ว ค่าบริการ 5% = " . number_format($service_fee, 2) . " บาท";
        }
    }

    // ---- สิ่งของ: เริ่มจัดซื้อ ----
    if ($action === 'needlist_start_purchase' && $fid) {
        $conn->query("UPDATE foundation_needlist SET approve_item = 'purchasing' WHERE foundation_id = $fid AND approve_item = 'approved'");
        $success = "เริ่มดำเนินการจัดซื้อสิ่งของแล้ว";
    }

    // ---- สิ่งของ: อัปโหลดหลักฐาน + เปลี่ยนเป็น done ----
    if ($action === 'needlist_upload_evidence' && $fid) {
        $desc = trim($_POST['description'] ?? '');
        $evidence_image = '';

        if (isset($_FILES['evidence_image']) && $_FILES['evidence_image']['error'] === 0) {
            $uploadDir = "uploads/evidence/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext     = strtolower(pathinfo($_FILES['evidence_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $newName = time() . "_" . uniqid() . "." . $ext;
                if (move_uploaded_file($_FILES['evidence_image']['tmp_name'], $uploadDir . $newName)) {
                    $evidence_image = $newName;
                }
            } else {
                $error = "อนุญาตเฉพาะไฟล์รูปเท่านั้น";
            }
        }

        if (!$error) {
            // บันทึก evidence โดยใช้ project_id = 0 แยกแยะว่าเป็น needlist
            $stmt = $conn->prepare("INSERT INTO evidence (project_id, admin_id, evidence_image, description, uploaded_at) VALUES (0, ?, ?, ?, NOW())");
            $stmt->bind_param("iss", $admin_id, $evidence_image, $desc);
            $stmt->execute();

            $conn->query("UPDATE foundation_needlist SET approve_item = 'done' WHERE foundation_id = $fid");

            // คำนวณค่าบริการ 5%
            $nl = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(current_donate) AS total FROM foundation_needlist WHERE foundation_id = $fid"));
            $total_collected = (float)($nl['total'] ?? 0);
            $service_fee     = round($total_collected * 0.05, 2);

            $cat = mysqli_fetch_assoc(mysqli_query($conn, "SELECT category_id FROM donate_category WHERE needitem_donate IS NOT NULL LIMIT 1"));
            $category_id = (int)($cat['category_id'] ?? 1);

            $stmt2 = $conn->prepare("INSERT INTO fund_disbursement (category_id, total_collected, amount_to_transfer, transfer_at) VALUES (?, ?, ?, NOW())");
            $stmt2->bind_param("idd", $category_id, $total_collected, $total_collected);
            $stmt2->execute();

            $success = "อัปโหลดหลักฐานสิ่งของสำเร็จ! ค่าบริการ 5% = " . number_format($service_fee, 2) . " บาท";
        }
    }
}

// ======== ดึงข้อมูลโครงการ ========
$completed_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name, fp.phone, fp.address, fp.bank_name, fp.bank_account_number
    FROM project p
    LEFT JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status IN ('completed', 'purchasing')
    ORDER BY p.project_status ASC, p.project_id DESC
");

$done_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name,
           e.evidence_image, e.description AS evidence_desc, e.uploaded_at AS evidence_date
    FROM project p
    LEFT JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    LEFT JOIN evidence e ON e.project_id = p.project_id
    WHERE p.project_status = 'done'
    ORDER BY p.project_id DESC
    LIMIT 10
");

$active_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name
    FROM project p
    LEFT JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status = 'approved'
    ORDER BY p.project_id DESC
");

$escrow_total_project = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(current_donate), 0) AS total FROM project WHERE project_status IN ('completed','purchasing')
"))['total'];

// ======== ดึงข้อมูล Needlist (จัดกลุ่มตาม foundation) ========
// มูลนิธิที่ครบยอดแล้ว (current_donate รวม >= total_price รวม)
$completed_needlist = mysqli_query($conn, "
    SELECT 
        fp.foundation_id, fp.foundation_name, fp.phone, fp.address, fp.bank_name, fp.bank_account_number,
        SUM(nl.total_price) AS goal,
        SUM(nl.current_donate) AS current,
        COUNT(nl.item_id) AS item_count,
        MAX(nl.approve_item) AS approve_status
    FROM foundation_needlist nl
    JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item IN ('approved', 'purchasing')
    GROUP BY fp.foundation_id
    HAVING SUM(nl.current_donate) >= SUM(nl.total_price)
    ORDER BY fp.foundation_id DESC
");

// มูลนิธิที่ยังระดมอยู่
$active_needlist = mysqli_query($conn, "
    SELECT 
        fp.foundation_id, fp.foundation_name,
        SUM(nl.total_price) AS goal,
        SUM(nl.current_donate) AS current,
        COUNT(nl.item_id) AS item_count
    FROM foundation_needlist nl
    JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item = 'approved'
    GROUP BY fp.foundation_id
    HAVING SUM(nl.current_donate) < SUM(nl.total_price)
    ORDER BY fp.foundation_id DESC
");

// มูลนิธิที่เสร็จแล้ว
$done_needlist = mysqli_query($conn, "
    SELECT 
        fp.foundation_id, fp.foundation_name,
        SUM(nl.total_price) AS goal,
        SUM(nl.current_donate) AS current,
        COUNT(nl.item_id) AS item_count
    FROM foundation_needlist nl
    JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item = 'done'
    GROUP BY fp.foundation_id
    ORDER BY fp.foundation_id DESC
    LIMIT 10
");

$escrow_total_needlist = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(current_donate), 0) AS total FROM foundation_needlist WHERE approve_item IN ('approved','purchasing')
"))['total'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escrow | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_escrow.css">
    <style>
        .tab-bar {
            display: flex;
            gap: 0;
            margin-bottom: 30px;
            border-bottom: 2px solid #e5e7eb;
        }
        .tab-btn {
            padding: 12px 32px;
            font-size: 15px;
            font-weight: 600;
            background: none;
            border: none;
            cursor: pointer;
            color: #6b7280;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }
        .tab-btn.active {
            color: #e53e3e;
            border-bottom-color: #e53e3e;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 30px;
        }
        .summary-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        .summary-label { font-size: 13px; color: #6b7280; margin-bottom: 6px; }
        .summary-value { font-size: 24px; font-weight: 700; color: #111; }
        .summary-value.green { color: #16a34a; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="escrow-wrap">
    <div class="page-title">จัดการ Escrow และการจัดซื้อ</div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ยอดรวม 2 ช่อง -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">ยอดบริจาคโครงการที่ครบแล้ว (รอมูลนิธิอัปเดต)</div>
            <div class="summary-value green"><?= number_format($escrow_total_project, 2) ?> บาท</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">เงิน Escrow (สิ่งของ) รอจัดซื้อ</div>
            <div class="summary-value green"><?= number_format($escrow_total_needlist, 2) ?> บาท</div>
        </div>
    </div>

    <!-- Tab Bar -->
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab('project', this)">📦 โครงการ</button>
        <button class="tab-btn" onclick="switchTab('needlist', this)">🛒 รายการสิ่งของ</button>
    </div>

    <!-- ======== TAB: โครงการ ======== -->
    <div id="tab-project" class="tab-content active">

        <div class="section-title">โครงการที่ครบยอดแล้ว — รอมูลนิธิอัปเดตความคืบหน้า</div>
        <p style="color:#6b7280; font-size:13px; margin:-10px 0 20px;">Omise จะโอนเงินให้มูลนิธิโดยตรง มูลนิธิต้องโพสต์ความคืบหน้าภายใน 30 วัน</p>

        <?php if ($completed_projects && mysqli_num_rows($completed_projects) > 0): ?>
            <?php while ($proj = mysqli_fetch_assoc($completed_projects)): ?>
                <?php
                    $goal    = (float)($proj['goal_amount'] ?? 0);
                    $current = (float)($proj['current_donate'] ?? 0);
                    $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
                ?>
                <div class="proj-card completed">
                    <div class="proj-header">
                        <div>
                            <div class="proj-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                            <div class="proj-foundation"><?= htmlspecialchars($proj['foundation_name'] ?? '-') ?></div>
                        </div>
                        <div class="proj-status-badge status-completed">ครบยอดแล้ว</div>
                    </div>
                    <div class="proj-money">
                        <div class="money-item">
                            <div class="money-label">ยอดที่ได้รับ</div>
                            <div class="money-value green"><?= number_format($current, 2) ?> บาท</div>
                        </div>
                        <div class="money-item">
                            <div class="money-label">เป้าหมาย</div>
                            <div class="money-value"><?= number_format($goal, 2) ?> บาท</div>
                        </div>
                        <div class="money-item">
                            <div class="money-label">วันสิ้นสุด</div>
                            <div class="money-value"><?= !empty($proj['end_date']) ? date('d/m/Y', strtotime($proj['end_date'])) : '-' ?></div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-box">ยังไม่มีโครงการที่ครบยอด</div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:40px;">โครงการที่กำลังระดมทุน</div>
        <?php if ($active_projects && mysqli_num_rows($active_projects) > 0): ?>
            <div class="active-grid">
            <?php while ($proj = mysqli_fetch_assoc($active_projects)): ?>
                <?php
                    $goal    = (float)($proj['goal_amount'] ?? 0);
                    $current = (float)($proj['current_donate'] ?? 0);
                    $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
                ?>
                <div class="active-card">
                    <div class="active-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                    <div class="active-foundation"><?= htmlspecialchars($proj['foundation_name'] ?? '-') ?></div>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= (int)$percent ?>%"></div></div>
                    <div class="active-amount">
                        <span><?= number_format($current, 0) ?> บาท</span>
                        <span>เป้า <?= number_format($goal, 0) ?> บาท (<?= round($percent) ?>%)</span>
                    </div>
                    <?php if (!empty($proj['end_date'])): ?>
                        <div class="active-date">หมดเขต: <?= date('d/m/Y', strtotime($proj['end_date'])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">ยังไม่มีโครงการที่กำลังระดมทุน</div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:40px;">โครงการที่เสร็จสมบูรณ์แล้ว</div>
        <?php if ($done_projects && mysqli_num_rows($done_projects) > 0): ?>
            <?php while ($proj = mysqli_fetch_assoc($done_projects)): ?>
                <div class="done-card">
                    <div class="done-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                    <div class="done-foundation"><?= htmlspecialchars($proj['foundation_name'] ?? '-') ?></div>
                    <?php if (!empty($proj['evidence_image'])): ?>
                        <img src="uploads/evidence/<?= htmlspecialchars($proj['evidence_image']) ?>" class="evidence-img" alt="หลักฐาน">
                    <?php endif; ?>
                    <?php if (!empty($proj['evidence_desc'])): ?>
                        <div class="done-desc"><?= htmlspecialchars($proj['evidence_desc']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($proj['evidence_date'])): ?>
                        <div class="done-date">จัดส่งเมื่อ: <?= date('d/m/Y H:i', strtotime($proj['evidence_date'])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-box">ยังไม่มีโครงการที่เสร็จสมบูรณ์</div>
        <?php endif; ?>
    </div>

    <!-- ======== TAB: สิ่งของ ======== -->
    <div id="tab-needlist" class="tab-content">

        <div class="section-title">มูลนิธิที่ครบยอดสิ่งของแล้ว — พร้อมจัดซื้อ</div>

        <?php if ($completed_needlist && mysqli_num_rows($completed_needlist) > 0): ?>
            <?php while ($nl = mysqli_fetch_assoc($completed_needlist)): ?>
                <?php
                    $goal    = (float)($nl['goal'] ?? 0);
                    $current = (float)($nl['current'] ?? 0);
                    $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
                    $is_purchasing = ($nl['approve_status'] === 'purchasing');
                ?>
                <div class="proj-card <?= $is_purchasing ? 'purchasing' : 'completed' ?>">
                    <div class="proj-header">
                        <div>
                            <div class="proj-name"><?= htmlspecialchars($nl['foundation_name']) ?></div>
                            <div class="proj-foundation"><?= (int)$nl['item_count'] ?> รายการสิ่งของ</div>
                        </div>
                        <div class="proj-status-badge <?= $is_purchasing ? 'status-purchasing' : 'status-completed' ?>">
                            <?= $is_purchasing ? 'กำลังจัดซื้อ' : 'ครบยอดแล้ว' ?>
                        </div>
                    </div>
                    <div class="proj-money">
                        <div class="money-item">
                            <div class="money-label">ยอดที่ได้รับ</div>
                            <div class="money-value green"><?= number_format($current, 2) ?> บาท</div>
                        </div>
                        <div class="money-item">
                            <div class="money-label">เป้าหมาย</div>
                            <div class="money-value"><?= number_format($goal, 2) ?> บาท</div>
                        </div>
                        <div class="money-item">
                            <div class="money-label">ค่าบริการ 5%</div>
                            <div class="money-value orange"><?= number_format($current * 0.05, 2) ?> บาท</div>
                        </div>
                    </div>
                    <div class="delivery-info">
                        <div class="delivery-title">ข้อมูลสำหรับจัดส่ง</div>
                        <div class="delivery-grid">
                            <div><span class="info-label">เบอร์โทร:</span> <?= htmlspecialchars($nl['phone'] ?? '-') ?></div>
                            <div><span class="info-label">ที่อยู่:</span> <?= htmlspecialchars($nl['address'] ?? '-') ?></div>
                            <div><span class="info-label">ธนาคาร:</span> <?= htmlspecialchars($nl['bank_name'] ?? '-') ?></div>
                            <div><span class="info-label">เลขบัญชี:</span> <?= htmlspecialchars($nl['bank_account_number'] ?? '-') ?></div>
                        </div>
                    </div>

                    <?php if (!$is_purchasing): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="needlist_start_purchase">
                            <input type="hidden" name="fid" value="<?= $nl['foundation_id'] ?>">
                            <button type="submit" class="btn-purchase" onclick="return confirm('เริ่มดำเนินการจัดซื้อสิ่งของสำหรับมูลนิธินี้?')">เริ่มดำเนินการจัดซื้อ</button>
                        </form>
                    <?php else: ?>
                        <div class="evidence-form">
                            <div class="evidence-title">อัปโหลดหลักฐานการจัดส่งสิ่งของ</div>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="needlist_upload_evidence">
                                <input type="hidden" name="fid" value="<?= $nl['foundation_id'] ?>">
                                <div class="form-group">
                                    <label>รูปภาพหลักฐาน *</label>
                                    <input type="file" name="evidence_image" accept="image/*" required>
                                </div>
                                <div class="form-group">
                                    <label>คำอธิบาย *</label>
                                    <textarea name="description" rows="3" placeholder="เช่น: จัดส่งสิ่งของให้มูลนิธิเรียบร้อยแล้ว วันที่..." required></textarea>
                                </div>
                                <button type="submit" class="btn-evidence">ยืนยันจัดส่งเสร็จแล้ว</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-box">ยังไม่มีมูลนิธิที่ครบยอดสิ่งของ</div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:40px;">มูลนิธิที่กำลังระดมทุนสิ่งของ</div>
        <?php if ($active_needlist && mysqli_num_rows($active_needlist) > 0): ?>
            <div class="active-grid">
            <?php while ($nl = mysqli_fetch_assoc($active_needlist)): ?>
                <?php
                    $goal    = (float)($nl['goal'] ?? 0);
                    $current = (float)($nl['current'] ?? 0);
                    $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
                ?>
                <div class="active-card">
                    <div class="active-name"><?= htmlspecialchars($nl['foundation_name']) ?></div>
                    <div class="active-foundation"><?= (int)$nl['item_count'] ?> รายการสิ่งของ</div>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= (int)$percent ?>%"></div></div>
                    <div class="active-amount">
                        <span><?= number_format($current, 0) ?> บาท</span>
                        <span>เป้า <?= number_format($goal, 0) ?> บาท (<?= round($percent) ?>%)</span>
                    </div>
                </div>
            <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">ยังไม่มีมูลนิธิที่กำลังระดมทุนสิ่งของ</div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:40px;">มูลนิธิที่จัดส่งสิ่งของเสร็จแล้ว</div>
        <?php if ($done_needlist && mysqli_num_rows($done_needlist) > 0): ?>
            <?php while ($nl = mysqli_fetch_assoc($done_needlist)): ?>
                <div class="done-card">
                    <div class="done-name"><?= htmlspecialchars($nl['foundation_name']) ?></div>
                    <div class="done-foundation"><?= (int)$nl['item_count'] ?> รายการ | ยอดรวม <?= number_format((float)$nl['current'], 2) ?> บาท</div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-box">ยังไม่มีมูลนิธิที่จัดส่งสิ่งของเสร็จแล้ว</div>
        <?php endif; ?>

    </div>
</div>

<script>
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    btn.classList.add('active');
}

// จำ tab ที่เลือกไว้หลัง POST
const savedTab = sessionStorage.getItem('escrow_tab');
if (savedTab) {
    const btn = document.querySelector(`.tab-btn[onclick*="${savedTab}"]`);
    if (btn) switchTab(savedTab, btn);
}
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const match = btn.getAttribute('onclick').match(/'(\w+)'/);
        if (match) sessionStorage.setItem('escrow_tab', match[1]);
    });
});
</script>

</body>
</html>