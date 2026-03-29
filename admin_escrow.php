<?php
// ไฟล์นี้: admin_escrow.php
// หน้าที่: จัดการ Escrow — แท็บโครงการ และ แท็บรายการสิ่งของ
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$admin_id = (int)$_SESSION['user_id'];
$success  = "";
$error    = "";

// รับ success message จาก redirect
if (isset($_GET['success']) && $_GET['success'] === 'transferred') {
    $success = "ยืนยันโอนเงินและส่งแจ้งเตือนมูลนิธิเรียบร้อยแล้ว ✅";
}

// ======== ประมวลผล POST ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $project_id = (int)($_POST['project_id'] ?? 0);
    $item_id    = (int)($_POST['item_id'] ?? 0);

    // ===== โครงการ: ยืนยันโอนเงิน + แจ้งมูลนิธิ =====
    if ($action === 'confirm_transfer' && $project_id) {
        $proj = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT p.project_name, fp.user_id, fp.foundation_name
             FROM project p
             JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
             WHERE p.project_id = $project_id"
        ));
        if ($proj) {
            $conn->query("UPDATE project SET project_status = 'purchasing' WHERE project_id = $project_id");
            $title   = "ยอดบริจาคโครงการครบแล้ว!";
            $message = "โครงการ \"{$proj['project_name']}\" ได้รับยอดบริจาคครบแล้ว กรุณาอัปเดตความคืบหน้าของโครงการ";
            $link    = "foundation_notifications.php";
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read) VALUES (?, 'project_funded', ?, ?, ?, 0)");
            $stmt->bind_param("isss", $proj['user_id'], $title, $message, $link);
            $stmt->execute();
            header("Location: admin_escrow.php?success=transferred");
            exit();
        }
    }

    // ===== สิ่งของ: เริ่มจัดซื้อ =====
    if ($action === 'start_purchase' && $item_id) {
        $stmt = $conn->prepare("UPDATE foundation_needlist SET approve_item = 'purchasing' WHERE item_id = ?");
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $success = "เริ่มดำเนินการจัดซื้อแล้ว";
    }

    // ===== สิ่งของ: อัปโหลดหลักฐาน → done =====
    if ($action === 'upload_evidence' && $item_id) {
        $desc = trim($_POST['description'] ?? '');
        $evidence_image = '';

        if (isset($_FILES['evidence_image']) && $_FILES['evidence_image']['error'] === 0) {
            $uploadDir = "uploads/evidence/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext     = strtolower(pathinfo($_FILES['evidence_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed)) {
                $newName = time() . "_" . uniqid() . "." . $ext;
                if (move_uploaded_file($_FILES['evidence_image']['tmp_name'], $uploadDir . $newName)) {
                    $evidence_image = $newName;
                } else { $error = "อัปโหลดไฟล์ไม่สำเร็จ"; }
            } else { $error = "อนุญาตเฉพาะไฟล์รูปเท่านั้น"; }
        } else { $error = "กรุณาเลือกรูปหลักฐาน"; }

        if (!$error) {
            $full_desc = "[needlist_item_id:{$item_id}] " . $desc;
            $stmt = $conn->prepare("INSERT INTO evidence (project_id, admin_id, evidence_image, description, uploaded_at) VALUES (0, ?, ?, ?, NOW())");
            $stmt->bind_param("iss", $admin_id, $evidence_image, $full_desc);
            $stmt->execute();

            $stmt2 = $conn->prepare("UPDATE foundation_needlist SET approve_item = 'done' WHERE item_id = ?");
            $stmt2->bind_param("i", $item_id);
            $stmt2->execute();

            $need = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT nl.item_name, fp.user_id FROM foundation_needlist nl
                 JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
                 WHERE nl.item_id = $item_id"
            ));
            if ($need) {
                $title   = "จัดส่งสิ่งของเรียบร้อยแล้ว!";
                $message = "รายการ \"{$need['item_name']}\" ถูกจัดซื้อและจัดส่งให้มูลนิธิเรียบร้อยแล้ว";
                $link    = "foundation_notifications.php";
                $stmt3 = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read) VALUES (?, 'needlist_done', ?, ?, ?, 0)");
                $stmt3->bind_param("isss", $need['user_id'], $title, $message, $link);
                $stmt3->execute();
            }
            $success = "อัปโหลดหลักฐานสำเร็จ! รายการสิ่งของเสร็จสมบูรณ์แล้ว";
        }
    }
}

// ======== ดึงข้อมูล: โครงการ ========
$completed_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name, fp.phone, fp.address, fp.bank_name, fp.bank_account_number
    FROM project p JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status IN ('completed','purchasing')
    ORDER BY p.project_status ASC, p.project_id DESC
");
$active_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name FROM project p
    JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status = 'approved' ORDER BY p.project_id DESC
");
$done_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name, e.evidence_image, e.description AS evidence_desc, e.uploaded_at AS evidence_date
    FROM project p JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    LEFT JOIN evidence e ON e.project_id = p.project_id
    WHERE p.project_status = 'done' ORDER BY p.project_id DESC LIMIT 10
");
$escrow_project_total = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(current_donate),0) AS total FROM project WHERE project_status IN ('completed','purchasing')"
))['total'];

// ======== ดึงข้อมูล: สิ่งของ ========
$ready_needs = mysqli_query($conn, "
    SELECT nl.*, fp.foundation_name, fp.phone, fp.address,
           COALESCE((SELECT SUM(d.amount) FROM donation d
                     WHERE d.category_id = 3 AND d.target_id = nl.item_id AND d.payment_status = 'completed'), 0) AS donated_sum
    FROM foundation_needlist nl JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item IN ('approved','purchasing')
    HAVING donated_sum >= nl.total_price
    ORDER BY nl.approve_item ASC, nl.item_id DESC
");
$active_needs = mysqli_query($conn, "
    SELECT nl.*, fp.foundation_name,
           COALESCE((SELECT SUM(d.amount) FROM donation d
                     WHERE d.category_id = 3 AND d.target_id = nl.item_id AND d.payment_status = 'completed'), 0) AS donated_sum
    FROM foundation_needlist nl JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item = 'approved'
    HAVING donated_sum < nl.total_price
    ORDER BY nl.item_id DESC
");
$done_needs = mysqli_query($conn, "
    SELECT nl.*, fp.foundation_name FROM foundation_needlist nl
    JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item = 'done' ORDER BY nl.item_id DESC LIMIT 10
");
$escrow_need_total = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(d.amount),0) AS total FROM donation d
     WHERE d.category_id = 3 AND d.payment_status = 'completed'
     AND d.target_id IN (SELECT item_id FROM foundation_needlist WHERE approve_item IN ('approved','purchasing'))"
))['total'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escrow | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_escrow.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="escrow-wrap">
    <div class="page-title">จัดการ Escrow และการจัดซื้อ</div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- แท็บ -->
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab('project', this)">💰 โครงการ</button>
        <button class="tab-btn" onclick="switchTab('needlist', this)">📦 รายการสิ่งของ</button>
    </div>

    <!-- ======== แท็บ: โครงการ ======== -->
    <div class="tab-content active" id="tab-project">

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">เงินพักรวมทั้งหมด (โครงการ)</div>
                <div class="summary-value green"><?= number_format($escrow_project_total, 2) ?> บาท</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">โครงการรอจัดการ</div>
                <div class="summary-value"><?= mysqli_num_rows($completed_projects) ?> รายการ</div>
            </div>
        </div>

        <div class="section-title">โครงการที่ครบยอดแล้ว — รอยืนยันโอนเงิน</div>
        <?php if (mysqli_num_rows($completed_projects) > 0):
            while ($proj = mysqli_fetch_assoc($completed_projects)):
                $goal    = (float)($proj['goal_amount'] ?? 0);
                $current = (float)($proj['current_donate'] ?? 0);
                $is_done = $proj['project_status'] === 'purchasing'; ?>
            <div class="proj-card <?= $is_done ? 'purchasing' : 'completed' ?>">
                <div class="proj-header">
                    <div>
                        <div class="proj-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                        <div class="proj-foundation"><?= htmlspecialchars($proj['foundation_name'] ?? '-') ?></div>
                    </div>
                    <div class="proj-status-badge <?= $is_done ? 'status-purchasing' : 'status-completed' ?>">
                        <?= $is_done ? 'แจ้งมูลนิธิแล้ว' : 'ครบยอดแล้ว' ?>
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
                    <div class="delivery-title">ข้อมูลมูลนิธิ</div>
                    <div class="delivery-grid">
                        <div><span class="info-label">เบอร์โทร:</span><?= htmlspecialchars($proj['phone'] ?? '-') ?></div>
                        <div><span class="info-label">ที่อยู่:</span><?= htmlspecialchars($proj['address'] ?? '-') ?></div>
                        <div><span class="info-label">ธนาคาร:</span><?= htmlspecialchars($proj['bank_name'] ?? '-') ?></div>
                        <div><span class="info-label">เลขบัญชี:</span><?= htmlspecialchars($proj['bank_account_number'] ?? '-') ?></div>
                    </div>
                </div>
                <?php if (!$is_done): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="confirm_transfer">
                        <input type="hidden" name="project_id" value="<?= $proj['project_id'] ?>">
                        <button type="submit" class="btn-purchase" onclick="return confirm('ยืนยันโอนเงิน + ส่งแจ้งเตือนให้มูลนิธิ?')">
                            ✅ ยืนยันโอนเงิน + แจ้งมูลนิธิ
                        </button>
                    </form>
                <?php else: ?>
                    <div class="notified-badge">📨 แจ้งมูลนิธิแล้ว — รอมูลนิธิอัปเดตความคืบหน้า</div>
                <?php endif; ?>
            </div>
        <?php endwhile; else: ?>
            <div class="empty-box">ยังไม่มีโครงการที่ครบยอด</div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:40px;">โครงการที่กำลังระดมทุน</div>
        <?php if ($active_projects && mysqli_num_rows($active_projects) > 0): ?>
            <div class="active-grid">
            <?php while ($proj = mysqli_fetch_assoc($active_projects)):
                $goal    = (float)($proj['goal_amount'] ?? 0);
                $current = (float)($proj['current_donate'] ?? 0);
                $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0; ?>
                <div class="active-card">
                    <div class="active-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                    <div class="active-foundation"><?= htmlspecialchars($proj['foundation_name'] ?? '-') ?></div>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= (int)$percent ?>%"></div></div>
                    <div class="active-amount">
                        <span><?= number_format($current, 0) ?> บาท</span>
                        <span>เป้า <?= number_format($goal, 0) ?> (<?= round($percent) ?>%)</span>
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
        <?php if ($done_projects && mysqli_num_rows($done_projects) > 0):
            while ($proj = mysqli_fetch_assoc($done_projects)): ?>
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
        <?php endwhile; else: ?>
            <div class="empty-box">ยังไม่มีโครงการที่เสร็จสมบูรณ์</div>
        <?php endif; ?>

    </div><!-- /tab-project -->

    <!-- ======== แท็บ: สิ่งของ ======== -->
    <div class="tab-content" id="tab-needlist">

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">เงินบริจาครวม (รายการสิ่งของ)</div>
                <div class="summary-value green"><?= number_format($escrow_need_total, 2) ?> บาท</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">รายการพร้อมจัดซื้อ</div>
                <div class="summary-value"><?= mysqli_num_rows($ready_needs) ?> รายการ</div>
            </div>
        </div>

        <div class="section-title">รายการที่ครบยอดแล้ว — พร้อมจัดซื้อ</div>
        <?php if ($ready_needs && mysqli_num_rows($ready_needs) > 0):
            while ($need = mysqli_fetch_assoc($ready_needs)):
                $donated = (float)$need['donated_sum'];
                $total   = (float)$need['total_price'];
                $is_purchasing = $need['approve_item'] === 'purchasing'; ?>
            <div class="proj-card <?= $is_purchasing ? 'purchasing' : 'completed' ?>">
                <div class="proj-header">
                    <div>
                        <div class="proj-name"><?= htmlspecialchars($need['item_name']) ?></div>
                        <div class="proj-foundation"><?= htmlspecialchars($need['foundation_name']) ?></div>
                    </div>
                    <div class="proj-status-badge <?= $is_purchasing ? 'status-purchasing' : 'status-completed' ?>">
                        <?= $is_purchasing ? 'กำลังจัดซื้อ' : 'ครบยอดแล้ว' ?>
                    </div>
                </div>
                <div class="proj-money">
                    <div class="money-item">
                        <div class="money-label">ยอดที่ได้รับ</div>
                        <div class="money-value green"><?= number_format($donated, 2) ?> บาท</div>
                    </div>
                    <div class="money-item">
                        <div class="money-label">เป้าหมาย</div>
                        <div class="money-value"><?= number_format($total, 2) ?> บาท</div>
                    </div>
                    <div class="money-item">
                        <div class="money-label">ค่าบริการ 5%</div>
                        <div class="money-value orange"><?= number_format($donated * 0.05, 2) ?> บาท</div>
                    </div>
                </div>
                <div class="delivery-info">
                    <div class="delivery-title">ข้อมูลสำหรับจัดส่ง</div>
                    <div class="delivery-grid">
                        <div><span class="info-label">เบอร์โทร:</span><?= htmlspecialchars($need['phone'] ?? '-') ?></div>
                        <div><span class="info-label">ที่อยู่:</span><?= htmlspecialchars($need['address'] ?? '-') ?></div>
                        <div><span class="info-label">หมวดหมู่:</span><?= htmlspecialchars($need['category'] ?? '-') ?></div>
                        <div><span class="info-label">จำนวน:</span><?= (int)$need['qty_needed'] ?> ชิ้น</div>
                    </div>
                    <?php if (!empty($need['note'])): ?>
                        <div style="margin-top:8px;font-size:13px;color:#555;">
                            <span class="info-label">หมายเหตุ:</span><?= htmlspecialchars($need['note']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!$is_purchasing): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="start_purchase">
                        <input type="hidden" name="item_id" value="<?= $need['item_id'] ?>">
                        <button type="submit" class="btn-purchase" onclick="return confirm('เริ่มดำเนินการจัดซื้อรายการนี้?')">
                            🛒 เริ่มดำเนินการจัดซื้อ
                        </button>
                    </form>
                <?php else: ?>
                    <div class="evidence-form">
                        <div class="evidence-title">📸 อัปโหลดหลักฐานการจัดส่ง</div>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_evidence">
                            <input type="hidden" name="item_id" value="<?= $need['item_id'] ?>">
                            <div class="form-group">
                                <label>รูปภาพหลักฐาน *</label>
                                <input type="file" name="evidence_image" accept="image/*" required>
                            </div>
                            <div class="form-group">
                                <label>คำอธิบาย *</label>
                                <textarea name="description" rows="3" placeholder="เช่น: จัดส่งสิ่งของให้มูลนิธิเรียบร้อยแล้ว วันที่..." required></textarea>
                            </div>
                            <button type="submit" class="btn-evidence">✅ ยืนยันจัดส่งเสร็จแล้ว</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endwhile; else: ?>
            <div class="empty-box">ยังไม่มีรายการที่ครบยอดพร้อมจัดซื้อ</div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:40px;">รายการที่กำลังระดมทุน</div>
        <?php if ($active_needs && mysqli_num_rows($active_needs) > 0): ?>
            <div class="active-grid">
            <?php while ($need = mysqli_fetch_assoc($active_needs)):
                $donated = (float)$need['donated_sum'];
                $total   = (float)$need['total_price'];
                $percent = ($total > 0) ? min(100, ($donated / $total) * 100) : 0; ?>
                <div class="active-card">
                    <div class="active-name"><?= htmlspecialchars($need['item_name']) ?></div>
                    <div class="active-foundation"><?= htmlspecialchars($need['foundation_name']) ?></div>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= (int)$percent ?>%"></div></div>
                    <div class="active-amount">
                        <span><?= number_format($donated, 0) ?> บาท</span>
                        <span>เป้า <?= number_format($total, 0) ?> (<?= round($percent) ?>%)</span>
                    </div>
                </div>
            <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">ยังไม่มีรายการที่กำลังระดมทุน</div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:40px;">รายการที่เสร็จสมบูรณ์แล้ว</div>
        <?php if ($done_needs && mysqli_num_rows($done_needs) > 0):
            while ($need = mysqli_fetch_assoc($done_needs)): ?>
            <div class="done-card">
                <div class="done-name"><?= htmlspecialchars($need['item_name']) ?></div>
                <div class="done-foundation"><?= htmlspecialchars($need['foundation_name']) ?></div>
                <div class="done-date">จัดส่งเสร็จแล้ว ✅</div>
            </div>
        <?php endwhile; else: ?>
            <div class="empty-box">ยังไม่มีรายการที่เสร็จสมบูรณ์</div>
        <?php endif; ?>

    </div><!-- /tab-needlist -->
</div>

<script>
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    btn.classList.add('active');
}
</script>
</body>
</html>