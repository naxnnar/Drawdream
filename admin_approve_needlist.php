<?php
// admin_approve_needlist.php — แอดมินอนุมัติรายการสิ่งของมูลนิธิ

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน approve needlist

session_start();
include 'db.php';

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: welcome.php");
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);

$msg = "";
$error = "";

/**
 * @return array<int,array{slot:int,category:string,item_name:string,qty:float,price:float,line_total:float}>
 */
function admin_needlist_parse_items_json(array $row): array
{
    $raw = trim((string)($row['need_items_json'] ?? ''));
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return [];
    }
    if (!is_array($decoded)) {
        return [];
    }
    $namePool = array_values(array_filter(array_map('trim', explode(',', (string)($row['item_name'] ?? '')))));
    $out = [];
    foreach ($decoded as $idx => $li) {
        if (!is_array($li)) {
            continue;
        }
        $slot = (int)($li['slot'] ?? ($idx + 1));
        $qty = (float)($li['จำนวนสิ่งของ'] ?? ($li['qty_needed'] ?? ($li['qty'] ?? 0)));
        $price = (float)($li['ราคาต่อชิ้น'] ?? ($li['price_estimate'] ?? ($li['price'] ?? 0)));
        $lineTotal = (float)($li['ราคารวม'] ?? ($li['line_total'] ?? ($qty * $price)));
        $cat = trim((string)($li['หมวดหมู่สิ่งของ'] ?? ($li['category'] ?? '')));
        $itemName = trim((string)($li['ชื่อสิ่งของ'] ?? ($li['item_name'] ?? '')));
        if ($itemName === '') {
            $itemName = trim((string)($namePool[$idx] ?? ''));
        }
        $out[] = [
            'slot' => $slot > 0 ? $slot : ($idx + 1),
            'category' => $cat,
            'item_name' => $itemName,
            'qty' => $qty > 0 ? $qty : 0.0,
            'price' => $price > 0 ? $price : 0.0,
            'line_total' => $lineTotal > 0 ? $lineTotal : ($qty * $price),
        ];
    }
    $hasPrice = false;
    $qtySum = 0.0;
    foreach ($out as $r) {
        if ((float)($r['price'] ?? 0) > 0) {
            $hasPrice = true;
        }
        $qtySum += max(0.0, (float)($r['qty'] ?? 0));
    }
    if (!$hasPrice) {
        $fallbackTotal = (float)($row['total_price'] ?? 0);
        $fallbackUnit = ($qtySum > 0 && $fallbackTotal > 0) ? ($fallbackTotal / $qtySum) : 0.0;
        if ($fallbackUnit > 0) {
            foreach ($out as $i => $r) {
                $q = (float)($r['qty'] ?? 0);
                $out[$i]['price'] = $fallbackUnit;
                $out[$i]['line_total'] = $q * $fallbackUnit;
            }
        }
    }
    return $out;
}

/**
 * ลบข้อมูลราคาต่อหน่วยออกจาก need_items_json ก่อนบันทึก
 * คงไว้เฉพาะข้อมูลรายการและจำนวนตามที่ต้องใช้แสดงผล
 */
function admin_needlist_strip_unit_price_json(?string $rawJson): ?string
{
    $raw = trim((string)$rawJson);
    if ($raw === '') {
        return null;
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($decoded)) {
        return null;
    }
    $out = [];
    foreach ($decoded as $idx => $li) {
        if (!is_array($li)) {
            continue;
        }
        $slot = (int)($li['slot'] ?? ($idx + 1));
        $category = trim((string)($li['หมวดหมู่สิ่งของ'] ?? ($li['category'] ?? '')));
        $itemName = trim((string)($li['ชื่อสิ่งของ'] ?? ($li['item_name'] ?? '')));
        $qty = (float)($li['จำนวนสิ่งของ'] ?? ($li['qty_needed'] ?? ($li['qty'] ?? 0)));
        $out[] = [
            'หมวดหมู่สิ่งของ' => $category,
            'ชื่อสิ่งของ' => $itemName,
            'จำนวนสิ่งของ' => $qty > 0 ? $qty : 0.0,
        ];
    }
    $encoded = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : null;
}

// อนุมัติ/ปฏิเสธ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $rejectNote = trim((string)($_POST['reject_note'] ?? ''));
    $priceAdjustNote = trim((string)($_POST['price_adjust_note'] ?? ''));
    $adminTotalInput = trim((string)($_POST['admin_total_price'] ?? ''));
    $adminTotalPrice = null;

    if ($adminTotalInput !== '') {
        $adminTotalInput = str_replace([',', ' '], '', $adminTotalInput);
        $adminTotalPrice = (float)$adminTotalInput;
        if ($adminTotalPrice <= 0) {
            $error = 'ราคาสุดท้ายที่แอดมินกำหนดต้องมากกว่า 0';
        }
    }

    $newStatus = null;
    if ($action === 'approve') $newStatus = 'approved';
    if ($action === 'reject')  $newStatus = 'rejected';

    $submittedTotal = 0.0;
    $needItemsJsonSanitized = null;
    if ($item_id > 0) {
        $stOld = $conn->prepare('SELECT total_price, submitted_total_price, need_items_json FROM foundation_needlist WHERE item_id = ? LIMIT 1');
        if ($stOld) {
            $stOld->bind_param('i', $item_id);
            $stOld->execute();
            $oldRow = $stOld->get_result()->fetch_assoc();
            $submittedTotal = (float)($oldRow['submitted_total_price'] ?? 0);
            if ($submittedTotal <= 0) {
                $submittedTotal = (float)($oldRow['total_price'] ?? 0);
            }
            $needItemsJsonSanitized = admin_needlist_strip_unit_price_json((string)($oldRow['need_items_json'] ?? ''));
        }
    }

    if ($item_id <= 0 || !in_array($newStatus, ['approved','rejected'], true)) {
        $error = "ข้อมูลไม่ถูกต้อง";
    } elseif ($newStatus === 'rejected' && $rejectNote === '') {
        $error = "กรุณากรอกเหตุผลเมื่อปฏิเสธ";
    } else {
        require_once __DIR__ . '/includes/needlist_donate_window.php';

        $donateEndSql = null;
        if ($newStatus === 'approved') {
            $sn = $conn->prepare("SELECT created_at FROM foundation_needlist WHERE item_id = ? AND approve_item = 'pending' LIMIT 1");
            if (!$sn) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $sn->bind_param("i", $item_id);
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

        if ($newStatus === 'approved' && $adminTotalPrice === null) {
            $adminTotalPrice = $submittedTotal;
        }
        $reviewNoteForSave = ($newStatus === 'approved') ? $priceAdjustNote : $rejectNote;

        if ($error !== '') {
            // ข้าม execute
        } elseif ($newStatus === 'approved' && $donateEndSql !== null) {
            $stmt = $conn->prepare("
                UPDATE foundation_needlist
                SET approve_item=?,
                    review_note=?,
                    submitted_total_price = COALESCE(submitted_total_price, total_price),
                    total_price=?,
                    approved_total_price=?,
                    price_reviewed_at=NOW(),
                    need_items_json = COALESCE(?, need_items_json),
                    donate_window_end_at=?
                WHERE item_id=? AND approve_item='pending'
            ");
            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param("ssddssi", $newStatus, $reviewNoteForSave, $adminTotalPrice, $adminTotalPrice, $needItemsJsonSanitized, $donateEndSql, $item_id);
            }
        } elseif ($newStatus === 'approved') {
            $error = "ไม่สามารถคำนวณวันปิดรับบริจาคอัตโนมัติได้";
        } else {
            $stmt = $conn->prepare("
                UPDATE foundation_needlist
                SET approve_item=?,
                    review_note=?,
                    donate_window_end_at=NULL
                WHERE item_id=? AND approve_item='pending'
            ");
            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param("ssi", $newStatus, $reviewNoteForSave, $item_id);
            }
        }

        if ($error === '' && isset($stmt) && $stmt instanceof mysqli_stmt) {
            if ($stmt->execute()) {
                require_once __DIR__ . '/includes/notification_audit.php';
                drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_need:' . $item_id);
                $action_type = ($newStatus === 'approved') ? 'Approve_Need' : 'Reject_Need';
                $stFu = $conn->prepare(
                    "SELECT fp.user_id, nl.item_name FROM foundation_needlist nl
                     INNER JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
                     WHERE nl.item_id = ? LIMIT 1"
                );
                $stFu->bind_param('i', $item_id);
                $stFu->execute();
                $nr = $stFu->get_result()->fetch_assoc();
                $fu = (int)($nr['user_id'] ?? 0);
                $iname = (string)($nr['item_name'] ?? '');
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
                drawdream_log_admin_action($conn, $uid, $action_type, $item_id, $reviewNoteForSave, $fu > 0 ? $fu : null, $notifKind);
                $msg = ($newStatus === 'approved') ? "อนุมัติรายการแล้ว" : "ปฏิเสธรายการแล้ว";
                header('Location: admin_notifications.php?done=need&msg=' . urlencode($msg) . '#admin-pending-needs');
                exit();
            } else {
                $error = "อัปเดตไม่สำเร็จ: " . $stmt->error;
            }
        }
    }
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

// ดึงรายการ pending + ชื่อมูลนิธิ
$sql = "
  SELECT nl.*, fp.foundation_name
  FROM foundation_needlist nl
  JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
  WHERE nl.approve_item='pending'
  ORDER BY nl.urgent DESC, nl.item_id DESC
";
$result = mysqli_query($conn, $sql);
if (!$result) die("Query failed: " . mysqli_error($conn));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/navbar.css">
    <title>อนุมัติรายการสิ่งของ | Admin</title>
    <link rel="stylesheet" href="css/admin.css">
</head>
<body class="admin-approve-needlist-page">

<?php include 'navbar.php'; ?>

<div class="wrap">
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
                $lineItems = admin_needlist_parse_items_json($row);
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
            <form class="need-approve-card" method="post">
                <input type="hidden" name="item_id" value="<?= (int)$row['item_id'] ?>">

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
                            <span>รายละเอียดรายการย่อยที่กรอก</span>
                            <div class="need-line-items">
                                <?php foreach ($lineItems as $li): ?>
                                    <div class="need-line-item">
                                        <b>#<?= (int)$li['slot'] ?></b>
                                        <span><?= htmlspecialchars($li['item_name'] !== '' ? $li['item_name'] : ($li['category'] !== '' ? $li['category'] : '-')) ?></span>
                                        <span><?= number_format((float)$li['qty'], 0) ?> ชิ้น</span>
                                        <span></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="need-field need-field--full">
                            <span>หมายเหตุจากมูลนิธิ</span>
                            <strong><?= $foundationNote !== '' ? nl2br(htmlspecialchars($foundationNote)) : '-' ?></strong>
                        </div>
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

                <div class="admin-actions need-actions-row">
                    <button class="admin-btn approve" name="action" value="approve"
                            onclick="return confirm('ยืนยันอนุมัติรายการนี้?');">อนุมัติ</button>
                    <button class="admin-btn reject" name="action" value="reject"
                            onclick="return confirm('ยืนยันปฏิเสธรายการนี้? (ต้องมีเหตุผล)');">ไม่อนุมัติ</button>
                </div>
            </form>
        <?php endwhile; ?>
        </div>
    <?php else: ?>
        <p>ตอนนี้ไม่มีรายการ pending ✅</p>
    <?php endif; ?>
</div>

</body>
</html>