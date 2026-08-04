<?php
// admin_escrow.php — จัดการเงินค้ำ / escrow

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน escrow

include 'db.php';
require_once __DIR__ . '/includes/admin_audit_migrate.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/escrow_funds_schema.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/includes/drawdream_project_service_charge.php';
require_once __DIR__ . '/includes/notification_audit.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$admin_id = (int)$_SESSION['user_id'];
$success  = "";
$error    = "";

// schema: รันโดย tools/run_migrations.php ตอน deploy เท่านั้น

// รับ success message จาก redirect
if (isset($_GET['success']) && $_GET['success'] === 'transferred') {
    $success = "ยืนยันโอนเงินและส่งแจ้งเตือนมูลนิธิเรียบร้อยแล้ว ✅";
}

// ======== ประมวลผล POST ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    drawdream_csrf_require_valid('admin_escrow.php');
    $action     = $_POST['action'] ?? '';
    $project_id = (int)($_POST['project_id'] ?? 0);
    $item_id    = (int)($_POST['item_id'] ?? 0);

    // ===== โครงการ: ยืนยันโอนเงิน + แจ้งมูลนิธิ =====
    if ($action === 'confirm_transfer' && $project_id) {
        $ps = $conn->prepare(
            "SELECT p.project_name, fp.user_id, fp.foundation_name, p.service_charge_paid_at
             FROM foundation_project p
             JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
             WHERE p.project_id = ?"
        );
        $ps->bind_param('i', $project_id);
        $ps->execute();
        $proj = $ps->get_result()->fetch_assoc();
        if ($proj && empty($proj['service_charge_paid_at'])) {
            $error = 'มูลนิธิยังไม่ได้ชำระค่าบริการระบบ — รอมูลนิธิชำระก่อนยืนยันโอนเงิน';
        } elseif ($proj) {
            $upd = $conn->prepare("UPDATE foundation_project SET project_status = 'purchasing' WHERE project_id = ?");
            $upd->bind_param('i', $project_id);
            $upd->execute();
            drawdream_escrow_funds_release_holding_for_project($conn, $project_id);
            $title   = "พร้อมอัปเดตผลลัพธ์โครงการแล้ว";
            $message = "แอดมินยืนยันโอนเงิน escrow ให้โครงการ \"{$proj['project_name']}\" แล้ว คุณสามารถเข้าไปอัปเดตผลลัพธ์โครงการได้ทันที";
            $link    = "foundation_post_update.php?project_id=" . (int)$project_id;
            drawdream_send_notification($conn, (int)$proj['user_id'], '', $title, $message, $link);
            header("Location: admin_escrow.php?success=transferred");
            exit();
        }
    }

    // ===== สิ่งของ: เริ่มจัดซื้อ =====
    if ($action === 'start_purchase' && $item_id) {
        $chk = $conn->prepare(
            'SELECT service_charge_paid_at FROM foundation_needlist WHERE item_id = ? LIMIT 1'
        );
        $paidAt = null;
        if ($chk) {
            $chk->bind_param('i', $item_id);
            $chk->execute();
            $paidAt = $chk->get_result()->fetch_assoc()['service_charge_paid_at'] ?? null;
        }
        if (empty($paidAt)) {
            $error = 'มูลนิธิยังไม่ได้ชำระค่าบริการระบบ — รอมูลนิธิชำระก่อนเริ่มจัดซื้อ';
        } else {
            $stmt = $conn->prepare("UPDATE foundation_needlist SET approve_item = 'purchasing' WHERE item_id = ?");
            $stmt->bind_param("i", $item_id);
            $stmt->execute();
            $success = "เริ่มดำเนินการจัดซื้อแล้ว";
        }
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
            $chk = $conn->prepare(
                'SELECT service_charge_paid_at FROM foundation_needlist WHERE item_id = ? LIMIT 1'
            );
            $paidAt = null;
            if ($chk) {
                $chk->bind_param('i', $item_id);
                $chk->execute();
                $paidAt = $chk->get_result()->fetch_assoc()['service_charge_paid_at'] ?? null;
            }
            if (empty($paidAt)) {
                $error = 'มูลนิธิยังไม่ได้ชำระค่าบริการระบบ — ไม่สามารถยืนยันจัดส่งได้';
            }
        }

        if (!$error) {
            $imgJson = json_encode([$evidence_image], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($imgJson) || $imgJson === '') {
                $imgJson = '[]';
            }
            $stmt2 = $conn->prepare("UPDATE foundation_needlist SET approve_item = 'done', admin_delivery_text = ?, admin_delivery_images = ?, admin_delivery_at = NOW() WHERE item_id = ?");
            $stmt2->bind_param("ssi", $desc, $imgJson, $item_id);
            $stmt2->execute();
            drawdream_escrow_funds_release_holding_for_need_item($conn, $item_id);

            $need = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT nl.item_name, fp.user_id FROM foundation_needlist nl
                 JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
                 WHERE nl.item_id = $item_id"
            ));
            if ($need) {
                $title   = "พร้อมอัปเดตผลลัพธ์สิ่งของแล้ว";
                $message = "รายการ \"{$need['item_name']}\" ถูกจัดซื้อและจัดส่งเรียบร้อยแล้ว คุณสามารถอัปเดตผลลัพธ์สิ่งของให้ผู้บริจาคทราบได้";
                $link    = "foundation_post_needlist_result.php";
                drawdream_send_notification($conn, (int)$need['user_id'], '', $title, $message, $link);
            }
            $success = "อัปโหลดหลักฐานสำเร็จ! รายการสิ่งของเสร็จสมบูรณ์แล้ว";
        }
    }
}

// ======== ดึงข้อมูล: โครงการ ========
$completed_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name, fp.phone, fp.address, fp.bank_name, fp.bank_account_number
    FROM foundation_project p JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status IN ('completed','purchasing')
    ORDER BY p.project_status ASC, p.project_id DESC
");
$active_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name FROM foundation_project p
    JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status = 'approved' ORDER BY p.project_id DESC
");
$done_projects = mysqli_query($conn, "
    SELECT p.*, fp.foundation_name, p.update_images, p.update_text, p.update_at
    FROM foundation_project p JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
    WHERE p.project_status = 'done' ORDER BY p.project_id DESC LIMIT 10
");
$escrow_project_total = drawdream_escrow_project_holding_total_display($conn);

// ======== ดึงข้อมูล: สิ่งของ ========
// ยอดต่อรายการมาจาก current_donate (สะสมจากบริจาครวมของมูลนิธิตาม check_needlist_payment)
$ready_needs = mysqli_query($conn, "
    SELECT nl.*, fp.foundation_name, fp.phone, fp.address, nl.current_donate AS donated_sum
    FROM foundation_needlist nl JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item IN ('approved','purchasing')
    HAVING nl.current_donate >= nl.total_price
    ORDER BY nl.approve_item ASC, nl.item_id DESC
");
$active_needs = mysqli_query($conn, "
    SELECT nl.*, fp.foundation_name, nl.current_donate AS donated_sum
    FROM foundation_needlist nl JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item = 'approved'
    HAVING nl.current_donate < nl.total_price
    ORDER BY nl.item_id DESC
");
$done_needs = mysqli_query($conn, "
    SELECT nl.*, fp.foundation_name FROM foundation_needlist nl
    JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    WHERE nl.approve_item = 'done' ORDER BY nl.item_id DESC LIMIT 10
");
$escrow_need_total = drawdream_escrow_need_item_holding_total_display($conn);

/**
 * @param array<string,mixed> $need
 * @return array<int, array{name:string, qty:float, unit_price:float, line_total:float}>
 */
function drawdream_needlist_delivery_lines(array $need): array
{
    $lines = [];
    $parsed = foundation_needlist_admin_line_items_from_row($need);
    foreach ($parsed as $idx => $li) {
        $name = trim((string)($li['item_name'] ?? ''));
        $qty = (float)($li['qty'] ?? 0);
        $unit = (float)($li['price'] ?? 0);
        $sum = (float)($li['line_total'] ?? 0);
        if ($name !== '' || $qty > 0 || $unit > 0 || $sum > 0) {
            $lines[] = [
                'name' => $name !== '' ? $name : 'รายการที่ ' . ((int)($li['slot'] ?? ($idx + 1))),
                'qty' => $qty,
                'unit_price' => $unit,
                'line_total' => $sum,
            ];
        }
    }

    if ($lines === []) {
        $fallbackName = trim((string)($need['item_name'] ?? ''));
        $fallbackQty = (float)($need['qty_needed'] ?? 0);
        $fallbackTotal = (float)($need['total_price'] ?? 0);
        $fallbackUnit = ($fallbackQty > 0) ? ($fallbackTotal / $fallbackQty) : 0.0;
        if ($fallbackName !== '' || $fallbackQty > 0 || $fallbackTotal > 0) {
            $lines[] = [
                'name' => $fallbackName !== '' ? $fallbackName : 'รายการสิ่งของ',
                'qty' => $fallbackQty,
                'unit_price' => $fallbackUnit,
                'line_total' => $fallbackTotal,
            ];
        }
    }

    return $lines;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
                $serviceFee = (float)($proj['service_charge'] ?? 0);
                if ($serviceFee <= 0 && $goal > 0 && $current >= $goal - 1e-6) {
                    $serviceFee = drawdream_needlist_compute_service_charge($current);
                }
                $scPaid = !empty($proj['service_charge_paid_at']);
                $scPaidFmt = $scPaid ? date('d/m/Y H:i', strtotime((string)$proj['service_charge_paid_at'])) : '';
                $is_done = $proj['project_status'] === 'purchasing';
                $foundationUpdated = $is_done && drawdream_project_has_outcome_posted($proj);
                $foundationUpdateFmt = '';
                if ($foundationUpdated) {
                    $updateRaw = trim((string)($proj['update_at'] ?? ''));
                    if ($updateRaw !== '' && !str_starts_with($updateRaw, '0000') && strtotime($updateRaw) !== false) {
                        $foundationUpdateFmt = date('d/m/Y H:i', strtotime($updateRaw));
                    }
                }
                ?>
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
                        <div class="money-value orange"><?= number_format($serviceFee, 2) ?> บาท</div>
                    </div>
                </div>
                <?php if ($scPaid): ?>
                    <p class="sc-block-hint" style="color:#15803d;font-weight:600;margin:0 0 12px;">
                        ✅ มูลนิธิชำระค่าบริการแล้ว<?= $scPaidFmt !== '' ? ' — ' . htmlspecialchars($scPaidFmt) : '' ?>
                    </p>
                <?php else: ?>
                    <p class="sc-block-hint" style="color:#b45309;margin:0 0 12px;">
                        ⏳ รอมูลนิธิชำระค่าบริการ (<?= number_format($serviceFee, 2) ?> บาท) ก่อนยืนยันโอนเงิน
                    </p>
                <?php endif; ?>
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
                        <?= drawdream_csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_transfer">
                        <input type="hidden" name="project_id" value="<?= $proj['project_id'] ?>">
                        <button type="submit" class="btn-purchase"<?= $scPaid ? '' : ' disabled title="รอมูลนิธิชำระค่าบริการก่อน"' ?> onclick="return confirm('ยืนยันโอนเงิน + ส่งแจ้งเตือนให้มูลนิธิ?')">
                            ✅ ยืนยันโอนเงิน + แจ้งมูลนิธิ
                        </button>
                    </form>
                <?php else: ?>
                    <div class="notified-badge<?= $foundationUpdated ? ' notified-badge--updated' : '' ?>">
                        📨 แจ้งมูลนิธิแล้ว —
                        <?= $foundationUpdated ? 'มูลนิธิอัปเดตแล้ว' : 'รอมูลนิธิอัปเดตความคืบหน้า' ?>
                        <?php if ($foundationUpdated && $foundationUpdateFmt !== ''): ?>
                            <span class="notified-badge__time">(<?= htmlspecialchars($foundationUpdateFmt) ?>)</span>
                        <?php endif; ?>
                    </div>
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
            while ($proj = mysqli_fetch_assoc($done_projects)):
                $projImgs = json_decode((string)($proj['update_images'] ?? ''), true);
                $projImg = '';
                if (is_array($projImgs)) {
                    foreach ($projImgs as $pi) {
                        $bn = basename((string)$pi);
                        if ($bn !== '') { $projImg = $bn; break; }
                    }
                }
                $projDesc = trim((string)($proj['update_text'] ?? ''));
                $projDateRaw = trim((string)($proj['update_at'] ?? ''));
                ?>
            <div class="done-card">
                <div class="done-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                <div class="done-foundation"><?= htmlspecialchars($proj['foundation_name'] ?? '-') ?></div>
                <?php if ($projImg !== ''): ?>
                    <img src="uploads/evidence/<?= htmlspecialchars($projImg) ?>" class="evidence-img" alt="หลักฐาน">
                <?php endif; ?>
                <?php if ($projDesc !== ''): ?>
                    <div class="done-desc"><?= htmlspecialchars($projDesc) ?></div>
                <?php endif; ?>
                <?php if ($projDateRaw !== '' && strtotime($projDateRaw) !== false): ?>
                    <div class="done-date">จัดส่งเมื่อ: <?= date('d/m/Y H:i', strtotime($projDateRaw)) ?></div>
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
                $serviceFee = (float)($need['service_charge'] ?? 0);
                if ($serviceFee <= 0 && $total > 0 && $donated >= $total) {
                    $serviceFee = drawdream_needlist_compute_service_charge($donated);
                }
                $is_purchasing = $need['approve_item'] === 'purchasing';
                $scPaid = !empty($need['service_charge_paid_at']);
                $scPaidFmt = $scPaid ? date('d/m/Y H:i', strtotime((string)$need['service_charge_paid_at'])) : '';
                $deliveryLines = drawdream_needlist_delivery_lines($need); ?>
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
                        <div class="money-value orange"><?= number_format($serviceFee, 2) ?> บาท</div>
                    </div>
                </div>
                <div class="sc-payment-status <?= $scPaid ? 'sc-payment-status--paid' : 'sc-payment-status--wait' ?>">
                    <?php if ($scPaid): ?>
                        ✅ มูลนิธิชำระค่าบริการแล้ว<?= $scPaidFmt !== '' ? ' — ' . htmlspecialchars($scPaidFmt) : '' ?>
                    <?php else: ?>
                        ⏳ รอมูลนิธิชำระค่าบริการก่อนแอดมินจัดส่ง
                    <?php endif; ?>
                </div>
                <?php if ($deliveryLines !== []): ?>
                <div class="delivery-items-box">
                    <div class="delivery-title">รายการที่ต้องจัดส่งให้มูลนิธิ</div>
                    <div class="delivery-items-head">
                        <span>ชื่อสิ่งของ</span>
                        <span>จำนวนชิ้น</span>
                        <span>ราคาต่อชิ้น</span>
                    </div>
                    <?php foreach ($deliveryLines as $line): ?>
                        <div class="delivery-items-row">
                            <span><?= htmlspecialchars((string)$line['name']) ?></span>
                            <span><?= number_format((float)$line['qty'], ((float)$line['qty'] === floor((float)$line['qty'])) ? 0 : 2) ?></span>
                            <span><?= number_format((float)$line['unit_price'], 2) ?> บาท</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
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
                <?php if (!$scPaid): ?>
                    <p class="sc-block-hint">มูลนิธิต้องชำระค่าบริการระบบ (<?= number_format($serviceFee, 2) ?> บาท) ก่อนแอดมินจึงจะเริ่มจัดซื้อและยืนยันจัดส่งได้</p>
                <?php elseif (!$is_purchasing): ?>
                    <form method="POST">
                        <?= drawdream_csrf_field() ?>
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
                            <?= drawdream_csrf_field() ?>
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