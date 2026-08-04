<?php
// admin_approve_needlist.php — แอดมินอนุมัติรายการสิ่งของมูลนิธิ

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน approve needlist

include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
drawdream_ensure_needlist_schema($conn);

if (!function_exists('foundation_needlist_item_filenames_from_row')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ระบบ needlist schema ยังไม่ครบ — กรุณา deploy includes/drawdream_needlist_schema.php คู่กับหน้านี้';
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$focusItemId = (int)($_GET['item_id'] ?? 0);
$postItemId = 0;
$successItemId = (int)($_GET['success'] ?? 0);
$successAction = trim((string)($_GET['action'] ?? ''));
$successRow = null;

$msg = '';
$error = '';

/**
 * รวบรวมราคาต่อรายการจากฟอร์มอนุมัติ → JSON + ยอดรวม
 *
 * @param array<int,array{slot:int,category:string,item_name:string,qty:float,price:float,line_total:float}> $lineItems
 * @return array{error:string,total:float,items_json:?string,pricing_json:?string}
 */
function admin_needlist_pricing_from_post(array $lineItems, array $post, array $namePool, bool $strictFromPost = false): array
{
    $rowsForEncode = [];
    foreach ($lineItems as $idx => $li) {
        $slot = (int)($li['slot'] ?? 0);
        $qty = (float)($li['qty'] ?? 0);
        if ($slot <= 0 || $qty <= 0) {
            continue;
        }
        $rawPrice = str_replace([',', ' '], '', trim((string)($post['item_price_idx_' . $idx] ?? '')));
        if ($rawPrice === '' && $slot > 0) {
            $rawPrice = str_replace([',', ' '], '', trim((string)($post['item_price_' . $slot] ?? '')));
        }
        if ($rawPrice === '' && !$strictFromPost) {
            $rawPrice = (string)($li['price'] ?? '0');
        }
        if ($rawPrice === '') {
            return ['error' => "กรุณาระบุราคารายการที่ {$slot}", 'total' => 0.0, 'items_json' => null, 'pricing_json' => null];
        }
        $newPrice = drawdream_needlist_round_money((float)$rawPrice);
        if ($newPrice <= 0) {
            return ['error' => "ราคารายการที่ {$slot} ต้องมากกว่า 0", 'total' => 0.0, 'items_json' => null, 'pricing_json' => null];
        }
        $itemLabel = trim((string)($li['item_name'] ?? ''));
        if ($itemLabel === '') {
            $itemLabel = trim((string)($namePool[$idx] ?? ''));
        }
        $rowsForEncode[] = [
            'slot' => $slot,
            'category' => (string)($li['category'] ?? ''),
            'item_name' => $itemLabel,
            'qty' => $qty,
            'price' => $newPrice,
        ];
    }
    if ($rowsForEncode === []) {
        return ['error' => 'ไม่พบรายการสิ่งของย่อยสำหรับปรับราคา', 'total' => 0.0, 'items_json' => null, 'pricing_json' => null];
    }
    $encoded = foundation_needlist_encode_line_items_json($rowsForEncode);
    return [
        'error' => '',
        'total' => $encoded['total'],
        'items_json' => $encoded['items_json'],
        'pricing_json' => $encoded['pricing_json'],
    ];
}

// อนุมัติ/ปฏิเสธ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postItemId = (int)($_POST['item_id'] ?? 0);
    if (!drawdream_csrf_verify()) {
        $error = 'เซสชันหมดอายุหรือโทเคนไม่ถูกต้อง — กรุณารีเฟรชหน้าแล้วลองอนุมัติอีกครั้ง';
        drawdream_csrf_rotate();
        if ($postItemId > 0) {
            $focusItemId = $postItemId;
        }
    } else {
    $item_id = $postItemId;
    $action  = strtolower(trim((string)($_POST['need_admin_action'] ?? $_POST['action'] ?? '')));
    $rejectNote = trim((string)($_POST['reject_note'] ?? ''));
    $priceAdjustNote = trim((string)($_POST['price_adjust_note'] ?? ''));
    $adminTotalInput = trim((string)($_POST['admin_total_price'] ?? ''));
    $adminTotalPrice = null;
    $needItemsPricingJson = null;
    $needItemsJson = null;

    if ($adminTotalInput !== '' && $action === 'approve') {
        $adminTotalInput = str_replace([',', ' '], '', $adminTotalInput);
        $adminTotalPrice = (float)$adminTotalInput;
        if ($adminTotalPrice <= 0) {
            $error = 'ราคาสุดท้ายที่แอดมินกำหนดต้องมากกว่า 0';
        }
    }

    $newStatus = null;
    if ($action === 'approve') {
        $newStatus = 'approved';
    }
    if ($action === 'reject') {
        $newStatus = 'rejected';
    }

    $submittedTotal = 0.0;
    $oldRow = null;
    $lineItemsApprove = [];
    if ($item_id > 0) {
        $stOld = $conn->prepare(
            'SELECT * FROM foundation_needlist WHERE item_id = ? LIMIT 1'
        );
        if ($stOld) {
            $stOld->bind_param('i', $item_id);
            $stOld->execute();
            $oldRow = $stOld->get_result()->fetch_assoc();
            if ($oldRow) {
                $submittedTotal = (float)($oldRow['submitted_total_price'] ?? 0);
                if ($submittedTotal <= 0) {
                    $submittedTotal = (float)($oldRow['total_price'] ?? 0);
                }
                $lineItemsApprove = foundation_needlist_review_line_items_from_row($oldRow);
                if ($newStatus === 'approved' && count($lineItemsApprove) > 0) {
                    $namePool = array_values(array_filter(array_map('trim', explode(',', (string)($oldRow['item_name'] ?? '')))));
                    $built = admin_needlist_pricing_from_post($lineItemsApprove, $_POST, $namePool, true);
                    if ($built['error'] === '') {
                        $adminTotalPrice = $built['total'];
                        $needItemsPricingJson = $built['pricing_json'];
                        $needItemsJson = $built['items_json'];
                    } elseif ($error === '') {
                        $error = (string)$built['error'];
                    }
                }
                if ($error === '' && $newStatus === 'approved' && count($lineItemsApprove) === 0 && is_array($oldRow)
                    && ($needItemsJson === null || $needItemsPricingJson === null
                        || $adminTotalPrice === null || (float)$adminTotalPrice <= 0)) {
                    $totalHint = ($adminTotalPrice !== null && (float)$adminTotalPrice > 0)
                        ? (float)$adminTotalPrice
                        : $submittedTotal;
                    $payload = foundation_needlist_approval_payload_from_row($oldRow, $totalHint > 0 ? $totalHint : null);
                    if ($payload['total'] > 0) {
                        $adminTotalPrice = (float)$payload['total'];
                        $needItemsJson = $payload['items_json'];
                        $needItemsPricingJson = $payload['pricing_json'];
                    }
                }
            }
        }
    }

    if ($error === '' && ($item_id <= 0 || !in_array($newStatus, ['approved', 'rejected'], true))) {
        $error = ($action === '')
            ? 'ไม่ได้รับคำสั่งอนุมัติ/ปฏิเสธ — กรุณารีเฟรชหน้าแล้วลองอีกครั้ง'
            : 'ข้อมูลไม่ถูกต้อง';
    }

    if ($error === '' && $newStatus === 'approved') {
        if ($adminTotalPrice === null || (float)$adminTotalPrice <= 0) {
            $error = 'ไม่มีราคาที่อนุมัติได้ — กรุณาระบุราคาหรือตรวจรายการย่อย';
        } elseif ($needItemsJson === null || $needItemsPricingJson === null) {
            $stReady = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? LIMIT 1');
            if ($stReady) {
                $stReady->bind_param('i', $item_id);
                $stReady->execute();
                $readyRow = $stReady->get_result()->fetch_assoc();
                if (is_array($readyRow)) {
                    $payload = foundation_needlist_approval_payload_from_row(
                        $readyRow,
                        (float)$adminTotalPrice
                    );
                    if ($payload['total'] > 0) {
                        $adminTotalPrice = (float)$payload['total'];
                        $needItemsJson = $payload['items_json'];
                        $needItemsPricingJson = $payload['pricing_json'];
                    }
                }
            }
            if ($needItemsJson === null || $needItemsPricingJson === null) {
                $error = 'ไม่สามารถสร้างรายการราคาสำหรับอนุมัติได้';
            }
        }
    }

    if ($error === '' && $newStatus === 'rejected' && $rejectNote === '') {
        $error = 'กรุณากรอกเหตุผลเมื่อปฏิเสธ';
    }

    if ($error === '') {
        require_once __DIR__ . '/includes/needlist_donate_window.php';

        $donateEndSql = null;
        if ($newStatus === 'approved') {
            $sn = $conn->prepare("SELECT created_at FROM foundation_needlist WHERE item_id = ? AND LOWER(TRIM(approve_item)) = 'pending' LIMIT 1");
            if (!$sn) {
                $error = 'Prepare failed: ' . $conn->error;
            } else {
                $sn->bind_param('i', $item_id);
                $sn->execute();
                $nrow = $sn->get_result()->fetch_assoc();
                $reviewedAtRaw = trim((string)($nrow['created_at'] ?? ''));
                try {
                    $from = ($reviewedAtRaw !== '' && !str_starts_with($reviewedAtRaw, '0000-00-00'))
                        ? new DateTimeImmutable($reviewedAtRaw)
                        : new DateTimeImmutable('now');
                } catch (Throwable $e) {
                    $from = new DateTimeImmutable('now');
                }
                $donateEndSql = drawdream_needlist_compute_donate_window_end('', $from);
            }
        }

        if ($newStatus === 'approved' && $adminTotalPrice === null && $error === '') {
            $adminTotalPrice = $submittedTotal;
        }
        if ($newStatus === 'approved' && ($adminTotalPrice === null || (float)$adminTotalPrice <= 0)) {
            $error = 'ไม่มีราคาที่อนุมัติได้';
        }

        $auditNote = ($newStatus === 'approved') ? $priceAdjustNote : $rejectNote;
        $stmt = null;

        if ($error === '' && $newStatus === 'approved') {
            if ($donateEndSql === null || trim((string)$donateEndSql) === '') {
                $error = 'ไม่สามารถคำนวณวันปิดรับบริจาคอัตโนมัติได้';
            } else {
                $hasJson = is_string($needItemsPricingJson) && $needItemsPricingJson !== '';
                if ($hasJson) {
                    $itemsJsonBind = is_string($needItemsJson) && $needItemsJson !== '' ? $needItemsJson : '[]';
                    $stmt = $conn->prepare("
                        UPDATE foundation_needlist
                        SET approve_item=?,
                            foundation_original_total_price = COALESCE(foundation_original_total_price, submitted_total_price, total_price),
                            foundation_original_need_items_json = COALESCE(foundation_original_need_items_json, submitted_need_items_json, need_items_json),
                            foundation_original_need_items_pricing_json = COALESCE(foundation_original_need_items_pricing_json, submitted_need_items_pricing_json, need_items_pricing_json),
                            submitted_total_price=?,
                            submitted_need_items_json=?,
                            submitted_need_items_pricing_json=?,
                            total_price=?,
                            price_reviewed_at=NOW(),
                            need_items_json = ?,
                            need_items_pricing_json = ?,
                            donate_window_end_at=?
                        WHERE item_id=? AND LOWER(TRIM(approve_item))='pending'
                    ");
                    if ($stmt) {
                        $stmt->bind_param(
                            'sdssdsssi',
                            $newStatus,
                            $adminTotalPrice,
                            $itemsJsonBind,
                            $needItemsPricingJson,
                            $adminTotalPrice,
                            $itemsJsonBind,
                            $needItemsPricingJson,
                            $donateEndSql,
                            $item_id
                        );
                    }
                } else {
                    $stmt = $conn->prepare("
                        UPDATE foundation_needlist
                        SET approve_item=?,
                            foundation_original_total_price = COALESCE(foundation_original_total_price, submitted_total_price, total_price),
                            foundation_original_need_items_json = COALESCE(foundation_original_need_items_json, submitted_need_items_json, need_items_json),
                            foundation_original_need_items_pricing_json = COALESCE(foundation_original_need_items_pricing_json, submitted_need_items_pricing_json, need_items_pricing_json),
                            submitted_total_price=?,
                            submitted_need_items_pricing_json=?,
                            total_price=?,
                            price_reviewed_at=NOW(),
                            donate_window_end_at=?
                        WHERE item_id=? AND LOWER(TRIM(approve_item))='pending'
                    ");
                    if ($stmt) {
                        $stmt->bind_param(
                            'sdsdsi',
                            $newStatus,
                            $adminTotalPrice,
                            $needItemsPricingJson,
                            $adminTotalPrice,
                            $donateEndSql,
                            $item_id
                        );
                    }
                }
                if (!$stmt) {
                    $error = 'Prepare failed: ' . $conn->error;
                }
            }
        } elseif ($error === '' && $newStatus === 'rejected') {
            $stmt = $conn->prepare("
                UPDATE foundation_needlist
                SET approve_item=?,
                    donate_window_end_at=NULL
                WHERE item_id=? AND LOWER(TRIM(approve_item))='pending'
            ");
            if (!$stmt) {
                $error = 'Prepare failed: ' . $conn->error;
            } else {
                $stmt->bind_param('si', $newStatus, $item_id);
            }
        }

        if ($error === '' && $stmt instanceof mysqli_stmt) {
            if (!$stmt->execute()) {
                $error = 'อัปเดตไม่สำเร็จ: ' . $stmt->error;
            } elseif ($stmt->affected_rows < 1) {
                $error = 'ไม่พบรายการที่ยังรออนุมัติ — อาจถูกดำเนินการไปแล้ว กรุณารีเฟรชหน้า';
            } else {
                try {
                    require_once __DIR__ . '/includes/notification_audit.php';
                    drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_need:' . $item_id);
                    $action_type = ($newStatus === 'approved') ? 'Approve_Need' : 'Reject_Need';
                    $stFu = $conn->prepare(
                        "SELECT fp.user_id, nl.item_name FROM foundation_needlist nl
                         INNER JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
                         WHERE nl.item_id = ? LIMIT 1"
                    );
                    $fu = 0;
                    $iname = '';
                    if ($stFu) {
                        $stFu->bind_param('i', $item_id);
                        $stFu->execute();
                        $nr = $stFu->get_result()->fetch_assoc();
                        $fu = (int)($nr['user_id'] ?? 0);
                        $iname = (string)($nr['item_name'] ?? '');
                    }
                    $notifKind = $newStatus === 'approved' ? 'need_approved' : 'need_rejected';
                    if ($newStatus === 'approved') {
                        $approvedTotal = $adminTotalPrice ?? $submittedTotal;
                        $priceChanged = abs((float)$approvedTotal - (float)$submittedTotal) > 0.0001;
                        $finalMsg = 'รายการ "' . $iname . '" ผ่านการตรวจสอบแล้ว (ระบบจะปิดรับบริจาคอัตโนมัติใน 1 เดือน)';
                        $finalMsg .= ' ยอดเป้าหมายที่อนุมัติ: ' . number_format((float)$approvedTotal, 2) . ' บาท';
                        if ($priceChanged) {
                            $finalMsg .= ' (ปรับจาก ' . number_format((float)$submittedTotal, 2) . ' บาท)';
                            if ($priceAdjustNote !== '') {
                                $finalMsg .= ' เหตุผลปรับราคา: ' . $priceAdjustNote;
                            }
                        }
                        drawdream_send_notification(
                            $conn,
                            $fu,
                            'need_approved',
                            'รายการสิ่งของได้รับการอนุมัติ',
                            $finalMsg,
                            'foundation.php',
                            'fdn_need:' . $item_id
                        );
                    } else {
                        $nb = 'รายการ "' . $iname . '" ไม่ผ่านการอนุมัติ';
                        if ($rejectNote !== '') {
                            $nb .= ' เหตุผล: ' . $rejectNote;
                        }
                        drawdream_send_notification(
                            $conn,
                            $fu,
                            'need_rejected',
                            'รายการสิ่งของไม่ผ่านการอนุมัติ',
                            $nb,
                            'foundation.php',
                            'fdn_need:' . $item_id
                        );
                    }
                    drawdream_log_admin_action($conn, $uid, $action_type, $item_id, $auditNote, $fu > 0 ? $fu : null, $notifKind);
                } catch (Throwable $e) {
                    // อนุมัติสำเร็จแล้ว — แจ้งเตือนล้มเหลวไม่ควรค้างหน้าเดิม
                }

                drawdream_csrf_rotate();
                $doneAction = ($newStatus === 'approved') ? 'approved' : 'rejected';
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Location: admin_approve_needlist.php?success=1&item_id=' . $item_id . '&action=' . rawurlencode($doneAction), true, 303);
                exit();
            }
        }
    }

    if ($error !== '' && $postItemId > 0) {
        $focusItemId = $postItemId;
    }
    }
}

if ($successItemId > 0 && in_array($successAction, ['approved', 'rejected'], true)) {
    $stSuccess = $conn->prepare(
        'SELECT nl.item_id, nl.item_name, nl.approve_item, fp.foundation_name
         FROM foundation_needlist nl
         JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
         WHERE nl.item_id = ? LIMIT 1'
    );
    if ($stSuccess) {
        $stSuccess->bind_param('i', $successItemId);
        $stSuccess->execute();
        $successRow = $stSuccess->get_result()->fetch_assoc();
    }
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

// ดึงรายการ pending + ชื่อมูลนิธิ (ไม่โหลดตอนแสดงหน้าสำเร็จ)
$result = null;
if ($successRow === null) {
    $sql = "
      SELECT nl.*, fp.foundation_name
      FROM foundation_needlist nl
      JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
      WHERE nl.approve_item='pending'
      ORDER BY nl.urgent DESC, nl.item_id DESC
    ";
    if ($focusItemId > 0) {
        $sql = "
          SELECT nl.*, fp.foundation_name
          FROM foundation_needlist nl
          JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
          WHERE nl.approve_item='pending' AND nl.item_id = " . (int)$focusItemId . "
          ORDER BY nl.item_id DESC
        ";
    }
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die('Query failed: ' . mysqli_error($conn));
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="css/navbar.css">
    <title>อนุมัติรายการสิ่งของ | Admin</title>
    <link rel="stylesheet" href="css/admin.css?v=5">
</head>
<body class="admin-approve-needlist-page">

<?php include 'navbar.php'; ?>

<div class="wrap">
    <?php if (is_array($successRow)): ?>
        <?php
        $successName = trim((string)($successRow['item_name'] ?? ''));
        $successFdn = trim((string)($successRow['foundation_name'] ?? ''));
        $isApproved = $successAction === 'approved';
        ?>
        <div class="need-approve-success" role="status">
            <div class="need-approve-success__icon" aria-hidden="true"><?= $isApproved ? '✓' : '!' ?></div>
            <h2 class="need-approve-success__title"><?= $isApproved ? 'อนุมัติรายการเรียบร้อยแล้ว' : 'ปฏิเสธรายการเรียบร้อยแล้ว' ?></h2>
            <p class="need-approve-success__sub">
                <?= htmlspecialchars($successName !== '' ? $successName : ('รายการ #' . $successItemId), ENT_QUOTES, 'UTF-8') ?>
                <?php if ($successFdn !== ''): ?>
                    · <?= htmlspecialchars($successFdn, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </p>
            <?php if ($isApproved): ?>
                <p class="need-approve-success__hint">มูลนิธิได้รับแจ้งเตือนแล้ว — รายการพร้อมเปิดรับบริจาคตามระยะเวลาที่กำหนด</p>
            <?php endif; ?>
            <div class="need-approve-success__actions">
                <a class="admin-btn approve" href="admin_notifications.php#admin-pending-needs">กลับศูนย์แจ้งเตือน</a>
                <a class="admin-btn admin-btn--ghost" href="admin_needlist_directory.php">ดูรายการสิ่งของทั้งหมด</a>
            </div>
        </div>
    <?php else: ?>
    <h2>รายการสิ่งของที่รออนุมัติ (pending)</h2>

    <?php if ($error): ?>
        <div class="msg err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="msg ok"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <div class="need-approve-card-list">
        <?php while($row = mysqli_fetch_assoc($result)): ?>
            <?php
                $total = (float)($row['total_price'] ?? 0);
                $qtyForUnit = (float)($row['qty_needed'] ?? 0);
                $submittedTotal = (float)($row['submitted_total_price'] ?? 0);
                if ($submittedTotal <= 0) {
                    $submittedTotal = $total;
                }
                $itemImages = foundation_needlist_item_filenames_from_row($row);
                $fdnNeedAdm = foundation_needlist_normalize_filename((string)($row['need_foundation_image'] ?? ''));
                $foundationLines = foundation_needlist_review_line_items_from_row($row);
                $adminLines = foundation_needlist_admin_line_items_from_row($row);
                $lineItems = $foundationLines;
                if ($adminLines === [] && $foundationLines !== []) {
                    $adminLines = $foundationLines;
                }
                $lineCats = [];
                foreach ($lineItems as $liCat) {
                    $cv = trim((string)($liCat['category'] ?? ''));
                    if ($cv !== '') {
                        $lineCats[] = $cv;
                    }
                }
                $lineCategorySummary = implode(' | ', array_values(array_unique($lineCats)));
                if ($lineCategorySummary === '') {
                    $lineCategorySummary = '-';
                }
                $allowAnyBrand = ((int)($row['allow_other_brand'] ?? 0) === 1);
                $desiredBrand = trim((string)($row['desired_brand'] ?? ''));
                $foundationNote = trim((string)($row['note'] ?? ''));
            ?>
            <form class="need-approve-card" method="post" id="need-item-<?= (int)$row['item_id'] ?>" action="admin_approve_needlist.php?item_id=<?= (int)$row['item_id'] ?>" novalidate>
                <?= drawdream_csrf_field() ?>
                <input type="hidden" name="item_id" value="<?= (int)$row['item_id'] ?>">
                <input type="hidden" name="need_admin_action" value="" class="js-need-admin-action">

                <div class="need-approve-card__head">
                    <h3>ตรวจสอบรายการสิ่งของ</h3>
                    <?php if ((int)$row['urgent'] === 1): ?>
                        <span class="urgent-tag">ต้องการด่วน</span>
                    <?php endif; ?>
                </div>

                <div class="need-approve-card__body">
                    <div class="need-approve-media">
                        <?php if ($itemImages !== []): ?>
                            <?php foreach ($itemImages as $imgName): ?>
                                <img class="admin-thumb" src="uploads/needs/<?= htmlspecialchars($imgName) ?>" alt="รูปสิ่งของ">
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="admin-noimg">ไม่มีรูปสิ่งของ</div>
                        <?php endif; ?>
                        <?php if ($fdnNeedAdm !== ''): ?>
                            <img class="admin-thumb admin-thumb-fdn" src="uploads/needs/<?= htmlspecialchars($fdnNeedAdm) ?>" alt="มูลนิธิ" title="รูปมูลนิธิ">
                        <?php endif; ?>
                    </div>

                    <div class="need-approve-grid">
                        <div class="need-field"><span>มูลนิธิ</span><strong><?= htmlspecialchars($row['foundation_name']) ?></strong></div>
                        <div class="need-field"><span>หมวด</span><strong><?= htmlspecialchars($lineCategorySummary) ?></strong></div>
                        <div class="need-field need-field--full"><span>รายการ</span><strong><?= htmlspecialchars($row['item_name']) ?></strong></div>
                        <div class="need-field"><span>จำนวน</span><strong><?= (int)$row['qty_needed'] ?></strong></div>
                        <div class="need-field"><span>ยอดที่มูลนิธิเสนอ</span><strong><?= number_format($submittedTotal, 2) ?> บาท</strong></div>
                        <div class="need-field"><span>แบรนด์ที่ต้องการ</span><strong><?= $allowAnyBrand ? 'ยอมรับทุกแบรนด์' : ($desiredBrand !== '' ? htmlspecialchars($desiredBrand) : '-') ?></strong></div>
                        <div class="need-field"><span>ยอดรวมที่ใช้ปัจจุบัน</span><strong><?= number_format($total, 2) ?> บาท</strong></div>
                        <?php if ($lineItems !== []): ?>
                        <div class="need-field need-field--full">
                            <span>ปรับราคาตามรายการสิ่งของ (บาท/ชิ้น)</span>
                            <p class="need-approve-price-hint">แก้ราคาได้ทีละรายการ ระบบจะรวมเป็นยอดสุดท้ายอัตโนมัติ</p>
                            <table class="need-approve-price-table">
                                <thead>
                                    <tr>
                                        <th>รายการ</th>
                                        <th>จำนวน</th>
                                        <th>ราคา/ชิ้น (มูลนิธิ)</th>
                                        <th>ราคา/ชิ้น (แอดมิน)</th>
                                        <th>รวม</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lineItems as $idx => $li):
                                        $adminLi = $adminLines[$idx] ?? $li;
                                        $adminUnit = (float)($adminLi['price'] ?? $li['price']);
                                        $adminLineTotal = (float)($adminLi['line_total'] ?? ($adminUnit * (float)$li['qty']));
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($li['item_name'] !== '' ? $li['item_name'] : ($li['category'] !== '' ? $li['category'] : '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= number_format((float)$li['qty'], 0) ?> ชิ้น</td>
                                        <td><?= number_format((float)$li['price'], 2) ?> บาท</td>
                                        <td>
                                            <input
                                                type="number"
                                                name="item_price_idx_<?= (int)$idx ?>"
                                                class="need-approve-line-price"
                                                data-qty="<?= (float)$li['qty'] ?>"
                                                data-slot="<?= (int)$li['slot'] ?>"
                                                data-idx="<?= (int)$idx ?>"
                                                min="0.01"
                                                step="0.01"
                                                inputmode="decimal"
                                                value="<?= htmlspecialchars(number_format($adminUnit, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                        </td>
                                        <td class="need-approve-line-total"><?= number_format($adminLineTotal, 2) ?> บาท</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="need-approve-grand-label">ราคารวมที่อนุมัติ</td>
                                        <td class="need-approve-grand-total" id="need-approve-grand-<?= (int)$row['item_id'] ?>">
                                            <?= number_format(array_sum(array_column($adminLines ?: $lineItems, 'line_total')), 2) ?> บาท
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="need-field">
                            <label for="admin_total_price_<?= (int)$row['item_id'] ?>">ราคาสุดท้าย (แอดมิน)</label>
                            <input
                                id="admin_total_price_<?= (int)$row['item_id'] ?>"
                                type="number"
                                name="admin_total_price"
                                min="0.01"
                                step="0.01"
                                value="<?= htmlspecialchars(number_format($total, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>
                        <?php endif; ?>
                        <div class="need-field need-field--full">
                            <span>หมายเหตุจากมูลนิธิ</span>
                            <strong><?= $foundationNote !== '' ? nl2br(htmlspecialchars($foundationNote)) : '-' ?></strong>
                        </div>
                        <div class="need-field">
                            <label for="need_price_note_<?= (int)$row['item_id'] ?>">เหตุผลการปรับราคา (ส่งแจ้งเตือนมูลนิธิ)</label>
                            <textarea id="need_price_note_<?= (int)$row['item_id'] ?>" class="admin-note" name="price_adjust_note" placeholder="เช่น ปรับตามราคากลาง/ใบเสนอราคาจริง"></textarea>
                        </div>
                        <div class="need-field need-field--full">
                            <label for="need_reject_note_<?= (int)$row['item_id'] ?>">เหตุผล (กรณีปฏิเสธ)</label>
                            <textarea id="need_reject_note_<?= (int)$row['item_id'] ?>" class="admin-note" name="reject_note" placeholder="กรอกเหตุผลเมื่อปฏิเสธ"></textarea>
                        </div>
                    </div>
                </div>

                <?php if ($postItemId === (int)$row['item_id'] && $error !== ''): ?>
                <div class="msg err need-approve-card__error" role="alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="admin-actions need-actions-row">
                    <button type="submit" data-need-action="approve" class="admin-btn approve js-need-approve-btn">อนุมัติ</button>
                    <button type="submit" data-need-action="reject" class="admin-btn reject js-need-reject-btn">ไม่อนุมัติ</button>
                </div>
            </form>
        <?php endwhile; ?>
        </div>
    <?php else: ?>
        <p>ตอนนี้ไม่มีรายการ pending ✅</p>
        <p><a href="admin_notifications.php#admin-pending-needs">← กลับศูนย์แจ้งเตือน</a></p>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($error && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<?php require_once __DIR__ . '/includes/vendor_assets.php'; echo drawdream_sweetalert2_js_tag(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var msg = <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>;
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'error', title: 'อนุมัติไม่สำเร็จ', text: msg, confirmButtonText: 'ตกลง' });
    } else {
        alert(msg);
    }
});
</script>
<?php endif; ?>

<?php if ($focusItemId > 0): ?>
<script>
(function () {
    var el = document.getElementById('need-item-<?= $focusItemId ?>');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        el.classList.add('need-approve-card--focus');
    }
})();
</script>
<?php endif; ?>

<script>
(function () {
    function parsePrice(raw) {
        var n = parseFloat(String(raw || '').replace(/,/g, ''));
        return isFinite(n) ? n : 0;
    }

    function validateLinePrices(form) {
        var bad = null;
        form.querySelectorAll('.need-approve-line-price').forEach(function (inp) {
            if (bad) {
                return;
            }
            if (parsePrice(inp.value) <= 0) {
                bad = inp;
            }
        });
        if (bad) {
            alert('กรุณาระบุราคาต่อรายการให้มากกว่า 0');
            bad.focus();
            return false;
        }
        return true;
    }

    document.querySelectorAll('.need-approve-card').forEach(function (form) {
        var approveBtn = form.querySelector('.js-need-approve-btn');
        var rejectBtn = form.querySelector('.js-need-reject-btn');
        var actionField = form.querySelector('.js-need-admin-action');

        function setAction(val) {
            if (actionField) {
                actionField.value = val;
            }
        }

        form.addEventListener('submit', function (e) {
            var sub = e.submitter;
            var decision = '';
            if (sub && sub.getAttribute('data-need-action')) {
                decision = sub.getAttribute('data-need-action');
            } else if (actionField && actionField.value) {
                decision = actionField.value;
            }

            if (decision === 'reject') {
                var t = form.querySelector('[name=reject_note]');
                if (!t || !t.value.trim()) {
                    e.preventDefault();
                    alert('กรุณากรอกเหตุผลเมื่อปฏิเสธ');
                    if (t) {
                        t.focus();
                    }
                    return;
                }
                if (!confirm('ยืนยันปฏิเสธรายการนี้?')) {
                    e.preventDefault();
                    return;
                }
                setAction('reject');
            } else if (decision === 'approve') {
                if (!validateLinePrices(form)) {
                    e.preventDefault();
                    return;
                }
                if (!confirm('ยืนยันอนุมัติรายการนี้?')) {
                    e.preventDefault();
                    return;
                }
                setAction('approve');
            } else {
                e.preventDefault();
                alert('ไม่ได้รับคำสั่งอนุมัติ/ปฏิเสธ — กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
                return;
            }

            var busyBtn = (decision === 'reject') ? rejectBtn : approveBtn;
            if (busyBtn) {
                busyBtn.classList.add('is-busy');
                busyBtn.disabled = true;
                busyBtn.textContent = 'กำลังบันทึก…';
            }
        });

        if (approveBtn) {
            approveBtn.addEventListener('click', function () {
                setAction('approve');
            });
        }
        if (rejectBtn) {
            rejectBtn.addEventListener('click', function () {
                setAction('reject');
            });
        }

        var lineInputs = form.querySelectorAll('.need-approve-line-price');
        if (!lineInputs.length) return;
        var grandEl = form.querySelector('.need-approve-grand-total');
        if (!grandEl) return;
        function recalc() {
            var total = 0;
            lineInputs.forEach(function (inp) {
                var qty = parseFloat(inp.dataset.qty || '0');
                var price = parsePrice(inp.value);
                var lineTotal = qty * price;
                total += lineTotal;
                var lineCell = inp.closest('tr').querySelector('.need-approve-line-total');
                if (lineCell) {
                    lineCell.textContent = lineTotal.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท';
                }
            });
            grandEl.textContent = total.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท';
        }
        lineInputs.forEach(function (inp) { inp.addEventListener('input', recalc); });
    });
})();
</script>

</body>
</html>