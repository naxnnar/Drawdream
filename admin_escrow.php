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

    // เปลี่ยน status เป็น purchasing
    if ($action === 'start_purchase' && $project_id) {
        $conn->query("UPDATE project SET project_status = 'purchasing' WHERE project_id = $project_id");
        $success = "เริ่มดำเนินการจัดซื้อแล้ว";
    }

    // อัปโหลดหลักฐาน + เปลี่ยน status เป็น done
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
            // บันทึก evidence
            $stmt = $conn->prepare("
                INSERT INTO evidence (project_id, admin_id, evidence_image, description, uploaded_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("iiss", $project_id, $admin_id, $evidence_image, $desc);
            $stmt->execute();

            // เปลี่ยน status เป็น done
            $conn->query("UPDATE project SET project_status = 'done' WHERE project_id = $project_id");

            // คำนวณค่าบริการ 5% และบันทึก fund_disbursement
            $proj = mysqli_fetch_assoc(mysqli_query($conn, "SELECT current_donate, goal_amount FROM project WHERE project_id = $project_id"));
            $total_collected   = (float)($proj['current_donate'] ?? 0);
            $service_fee       = round($total_collected * 0.05, 2);
            $amount_to_transfer = $total_collected; // ไม่หักจากเงินบริจาค

            // ดึง category_id
            $cat = mysqli_fetch_assoc(mysqli_query($conn, "SELECT category_id FROM donate_category WHERE project_donate IS NOT NULL LIMIT 1"));
            $category_id = (int)($cat['category_id'] ?? 1);

            $stmt2 = $conn->prepare("
                INSERT INTO fund_disbursement (category_id, total_collected, amount_to_transfer, transfer_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt2->bind_param("idd", $category_id, $total_collected, $amount_to_transfer);
            $stmt2->execute();

            $success = "อัปโหลดหลักฐานสำเร็จ! โครงการเสร็จสมบูรณ์แล้ว ค่าบริการ 5% = " . number_format($service_fee, 2) . " บาท (แจ้งเตือนมูลนิธิแยกต่างหาก)";
        }
    }
}

// ======== ดึงข้อมูลโครงการ ========

// โครงการที่ครบแล้ว (completed/purchasing) รอจัดซื้อ
$completed_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name, fp.phone, fp.address, fp.bank_name, fp.bank_account_number, fp.bank_account_name
    FROM project p
    LEFT JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status IN ('completed', 'purchasing')
    ORDER BY p.project_status ASC, p.project_id DESC
");

// โครงการที่เสร็จแล้ว
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

// โครงการที่ยังระดมทุนอยู่
$active_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name
    FROM project p
    LEFT JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status = 'approved'
    ORDER BY p.project_id DESC
");

// ยอดรวม escrow
$escrow_total = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(current_donate), 0) AS total 
    FROM project 
    WHERE project_status IN ('completed', 'purchasing')
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

    <!-- ยอดรวม escrow -->
    <div class="escrow-summary">
        <div>เงินพักรวมทั้งหมดที่รอจัดซื้อ</div>
        <div class="escrow-amount"><?= number_format($escrow_total, 2) ?> บาท</div>
    </div>

    <!-- ======== โครงการที่ครบแล้ว ======== -->
    <div class="section-title">โครงการที่ครบยอดแล้ว — พร้อมจัดซื้อ</div>

    <?php if ($completed_projects && mysqli_num_rows($completed_projects) > 0): ?>
        <?php while ($proj = mysqli_fetch_assoc($completed_projects)): ?>
            <?php
                $goal    = (float)($proj['goal_amount'] ?? 0);
                $current = (float)($proj['current_donate'] ?? 0);
                $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
                $is_purchasing = $proj['project_status'] === 'purchasing';
            ?>
            <div class="proj-card <?= $is_purchasing ? 'purchasing' : 'completed' ?>">
                <div class="proj-header">
                    <div>
                        <div class="proj-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                        <div class="proj-foundation"><?= htmlspecialchars($proj['foundation_name'] ?? '-') ?></div>
                    </div>
                    <div class="proj-status-badge <?= $is_purchasing ? 'status-purchasing' : 'status-completed' ?>">
                        <?= $is_purchasing ? 'กำลังจัดซื้อ' : 'ครบยอดแล้ว' ?>
                    </div>
                </div>

                <!-- ข้อมูลยอดเงิน -->
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
                        <div class="money-label">ค่าบริการ 5% (เรียกเก็บมูลนิธิ)</div>
                        <div class="money-value orange"><?= number_format($current * 0.05, 2) ?> บาท</div>
                    </div>
                </div>

                <!-- ข้อมูลมูลนิธิสำหรับจัดส่ง -->
                <div class="delivery-info">
                    <div class="delivery-title">ข้อมูลสำหรับจัดส่ง</div>
                    <div class="delivery-grid">
                        <div>
                            <span class="info-label">เบอร์โทร:</span>
                            <span><?= htmlspecialchars($proj['phone'] ?? '-') ?></span>
                        </div>
                        <div>
                            <span class="info-label">ที่อยู่:</span>
                            <span><?= htmlspecialchars($proj['address'] ?? '-') ?></span>
                        </div>
                        <div>
                            <span class="info-label">ธนาคาร:</span>
                            <span><?= htmlspecialchars($proj['bank_name'] ?? '-') ?></span>
                        </div>
                        <div>
                            <span class="info-label">เลขบัญชี:</span>
                            <span><?= htmlspecialchars($proj['bank_account_number'] ?? '-') ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!$is_purchasing): ?>
                    <!-- ปุ่มเริ่มจัดซื้อ -->
                    <form method="POST">
                        <input type="hidden" name="action" value="start_purchase">
                        <input type="hidden" name="project_id" value="<?= $proj['project_id'] ?>">
                        <button type="submit" class="btn-purchase"
                            onclick="return confirm('เริ่มดำเนินการจัดซื้อสำหรับโครงการนี้?')">
                            เริ่มดำเนินการจัดซื้อ
                        </button>
                    </form>

                <?php else: ?>
                    <!-- ฟอร์มอัปโหลดหลักฐาน -->
                    <div class="evidence-form">
                        <div class="evidence-title">อัปโหลดหลักฐานการจัดส่ง</div>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_evidence">
                            <input type="hidden" name="project_id" value="<?= $proj['project_id'] ?>">
                            <div class="form-group">
                                <label>รูปภาพหลักฐาน *</label>
                                <input type="file" name="evidence_image" accept="image/*" required>
                            </div>
                            <div class="form-group">
                                <label>คำอธิบาย *</label>
                                <textarea name="description" rows="3" placeholder="เช่น: จัดส่งของให้มูลนิธิเรียบร้อยแล้ว วันที่..." required></textarea>
                            </div>
                            <button type="submit" class="btn-evidence">ยืนยันจัดส่งเสร็จแล้ว</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-box">ยังไม่มีโครงการที่ครบยอด</div>
    <?php endif; ?>

    <!-- ======== โครงการที่ยังระดมทุนอยู่ ======== -->
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
                <div class="bar-bg">
                    <div class="bar-fill" style="width:<?= (int)$percent ?>%"></div>
                </div>
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

    <!-- ======== โครงการที่เสร็จแล้ว ======== -->
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

</body>
</html>