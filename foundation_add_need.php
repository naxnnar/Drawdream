<?php
// foundation_add_need.php — มูลนิธิเสนอรายการสิ่งของ

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน add need

include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
drawdream_ensure_needlist_schema($conn);
require_once __DIR__ . '/includes/needlist_donate_window.php';
require_once __DIR__ . '/includes/drawdream_upload.php';
require_once __DIR__ . '/includes/drawdream_image_compress.php';
require_once __DIR__ . '/includes/foundation_donor_preview.php';
require_once __DIR__ . '/includes/foundation_need_flash.php';

$needMaxUploadBytes = drawdream_needlist_max_upload_bytes();
$needMaxUploadLabel = drawdream_format_bytes_mb_label($needMaxUploadBytes);
$needServerUploadBytes = drawdream_parse_ini_size((string)ini_get('upload_max_filesize'));

drawdream_foundation_require_management_access();

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_active_account($conn);

require_once __DIR__ . '/includes/drawdream_user_error.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    header('Location: login.php');
    exit;
}

// ดึง foundation_id + ชื่อมูลนิธิ (ใช้แจ้งเตือน)
$stmt = $conn->prepare("SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id=? LIMIT 1");
if (!$stmt) {
    drawdream_user_error_redirect(
        'โหลดข้อมูลมูลนิธิไม่สำเร็จ กรุณาลองใหม่ภายหลัง',
        'foundation.php',
        'foundation_add_need prepare foundation: ' . $conn->error
    );
}
$stmt->bind_param("i", $uid);
$stmt->execute();
$fp = $stmt->get_result()->fetch_assoc();
if (!$fp) {
    drawdream_user_error_redirect('ยังไม่มีโปรไฟล์มูลนิธิ กรุณาสร้างก่อน', 'update_profile.php');
}
$foundation_id = (int)$fp['foundation_id'];
$foundation_display_name = trim((string)($fp['foundation_name'] ?? ''));
$needProposeBlock = drawdream_foundation_needlist_propose_blocked($conn, $foundation_id);

$editItemPg = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editItemPg > 0) {
    $es = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? AND foundation_id = ? LIMIT 1');
    if ($es) {
        $es->bind_param('ii', $editItemPg, $foundation_id);
        $es->execute();
        $editRow = $es->get_result()->fetch_assoc();
    }
    if (!$editRow) {
        $editItemPg = 0;
    } elseif (!drawdream_foundation_needlist_may_edit($editRow)) {
        header('Location: foundation.php?need_edit_locked=1#my-needlist-section');
        exit;
    }
}
$isCreateModeLocked = ($editItemPg <= 0 && !empty($needProposeBlock['blocked']));
if ($isCreateModeLocked && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reason = (string)($needProposeBlock['reason'] ?? 'approved_open');
    $endAt = isset($needProposeBlock['donate_end_at']) ? trim((string)$needProposeBlock['donate_end_at']) : '';
    $q = 'need_round_wait=1&reason=' . rawurlencode($reason !== '' ? $reason : 'approved_open');
    if ($endAt !== '') {
        $q .= '&next=' . rawurlencode($endAt);
    }
    header('Location: foundation.php?' . $q . '#my-needlist-section');
    exit;
}

$needReturnTo = drawdream_foundation_need_return_to_resolve(false);

$error   = "";
$success = "";
if (isset($_GET['msg']) && trim((string)$_GET['msg']) !== '') {
    $error = trim((string)$_GET['msg']);
}

require_once __DIR__ . '/includes/needlist_category_catalog.php';

/**
 * Parse legacy/new list fields into unique trimmed tokens.
 * Supports values separated by | , newline and Thai comma.
 *
 * @return string[]
 */
function drawdream_need_tokens_from_db($raw)
{
    $txt = trim((string)$raw);
    if ($txt === '') {
        return [];
    }
    $parts = preg_split('/\s*(?:\||,|،|\R)\s*/u', $txt);
    if (!is_array($parts)) {
        return [];
    }
    $clean = [];
    foreach ($parts as $p) {
        $t = trim((string)$p);
        if ($t !== '') {
            $clean[] = $t;
        }
    }
    return array_values(array_unique($clean));
}

/**
 * Decode line-items JSON from foundation_needlist.need_items_json.
 *
 * @return array<int,array<string,mixed>>
 */
function drawdream_need_items_from_json($raw): array
{
    $txt = trim((string)$raw);
    if ($txt === '') {
        return [];
    }
    try {
        $decoded = json_decode($txt, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return [];
    }
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cat = trim((string)($row['หมวดหมู่สิ่งของ'] ?? ($row['category'] ?? '')));
        $itemName = trim((string)($row['ชื่อสิ่งของ'] ?? ($row['item_name'] ?? '')));
        $qty = (float)($row['จำนวนสิ่งของ'] ?? ($row['qty_needed'] ?? ($row['qty'] ?? 0)));
        $price = (float)($row['ราคาต่อชิ้น'] ?? ($row['price_estimate'] ?? ($row['price'] ?? 0)));
        if ($cat === '' || $qty <= 0) {
            continue;
        }
        $out[] = [
            'category' => $cat,
            'item_name' => $itemName,
            'qty' => $qty,
            'price' => $price > 0 ? $price : 0.0,
        ];
    }
    return $out;
}

/**
 * @return array<int,array{price:float,line_total:float}>
 */
function drawdream_need_pricing_from_json($raw): array
{
    $txt = trim((string)$raw);
    if ($txt === '') {
        return [];
    }
    try {
        $decoded = json_decode($txt, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return [];
    }
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $price = (float)($row['ราคาต่อชิ้น'] ?? ($row['price_estimate'] ?? ($row['price'] ?? 0)));
        $sum = (float)($row['ราคารวม'] ?? ($row['line_total'] ?? 0));
        $out[] = [
            'price' => drawdream_needlist_round_money($price > 0 ? $price : 0.0),
            'line_total' => drawdream_needlist_round_money($sum > 0 ? $sum : 0.0),
        ];
    }
    return $out;
}

if ($editRow && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $cats = [];
    $opts = drawdream_need_tokens_from_db($editRow['item_name'] ?? '');
    $itemRowsFromJson = drawdream_need_items_from_json($editRow['need_items_json'] ?? '');
    $itemPricingRows = drawdream_need_pricing_from_json($editRow['need_items_pricing_json'] ?? '');
    for ($i = 1; $i <= 5; $i++) {
        $_POST['item_category_' . $i] = '';
        $_POST['item_option_' . $i] = '';
        $_POST['item_custom_' . $i] = '';
        $_POST['item_price_' . $i] = '';
        $_POST['item_qty_' . $i] = '';
    }
    $qtyFromDb = (float)($editRow['qty_needed'] ?? 0);
    if ($qtyFromDb <= 0) {
        $qtyFromDb = 1;
    }
    $goalFromTotal = (float)($editRow['total_price'] ?? 0);
    $priceFromDb = $goalFromTotal > 0 ? ($goalFromTotal / $qtyFromDb) : 0.0;
    $slotIdx = 1;
    $sourceRows = [];
    if (!empty($itemRowsFromJson)) {
        $sourceRows = $itemRowsFromJson;
    } else {
        foreach ($opts as $itemName) {
            $sourceRows[] = [
                'category' => '',
                'item_name' => $itemName,
                'qty' => $qtyFromDb,
                'price' => $priceFromDb,
            ];
        }
    }
    foreach ($sourceRows as $rowData) {
        if ($slotIdx > 5) {
            break;
        }
        $fallbackName = $opts[$slotIdx - 1] ?? '';
        $itemName = trim((string)($rowData['item_name'] ?? $fallbackName));
        $rowCat = trim((string)($rowData['category'] ?? ''));
        $rowQty = (float)($rowData['qty'] ?? 0);
        $pricingData = $itemPricingRows[$slotIdx - 1] ?? [];
        $rowPrice = (float)($pricingData['price'] ?? ($rowData['price'] ?? 0));
        if ($rowQty <= 0) {
            $rowQty = $qtyFromDb;
        }
        if ($rowPrice <= 0) {
            $rowPrice = $priceFromDb;
        }
        $matchedCategory = drawdream_needlist_resolve_item_category($itemName, $rowCat);
        $_POST['item_category_' . $slotIdx] = $matchedCategory;
        if (in_array($itemName, $categoryItems[$matchedCategory] ?? [], true)) {
            $_POST['item_option_' . $slotIdx] = $itemName;
        } else {
            $_POST['item_option_' . $slotIdx] = '__other__';
            $_POST['item_custom_' . $slotIdx] = $itemName;
        }
        $_POST['item_price_' . $slotIdx] = $rowPrice > 0 ? (string)round($rowPrice, 2) : '';
        $_POST['item_qty_' . $slotIdx] = $rowQty > 0 ? (string)(int)$rowQty : '';
        $slotIdx++;
    }
    $_POST['desired_brand'] = (string)($editRow['desired_brand'] ?? '');
    if ((int)($editRow['allow_other_brand'] ?? 0) === 1) {
        $_POST['allow_any_brand'] = '1';
    }
    if ($goalFromTotal <= 0) {
        $goalFromTotal = ($qtyFromDb > 0 ? $qtyFromDb : 1) * $priceFromDb;
    }
    $_POST['goal_amount'] = (string)(int)round($goalFromTotal);
    $rawNote = (string)($editRow['note'] ?? '');
    $lines = preg_split('/\R/u', $rawNote, 2);
    if (preg_match('/^ระยะเวลา:\s*(.+)$/u', $lines[0] ?? '', $pm)) {
        $_POST['note'] = isset($lines[1]) ? trim((string)$lines[1]) : '';
    } else {
        $_POST['note'] = trim($rawNote);
    }
    if ((int)($editRow['urgent'] ?? 0) === 1) {
        $_POST['urgent'] = '1';
    }
}

if (isset($_POST['submit'])) {
    drawdream_csrf_require_valid('foundation_add_need.php');
    $itemIdEdit = (int)($_POST['item_id'] ?? 0);
    if ($itemIdEdit <= 0) {
        $blockPost = drawdream_foundation_needlist_propose_blocked($conn, $foundation_id);
        if (!empty($blockPost['blocked'])) {
            switch ($blockPost['reason'] ?? '') {
                case 'pending':
                    $error = 'มีรายการสิ่งของที่รอการตรวจสอบจากแอดมิน — จึงยังเสนอรายการเพิ่มไม่ได้';
                    break;
                case 'purchasing':
                    $error = 'รายการสิ่งของอยู่ในขั้นตอนจัดซื้อ — จึงยังเสนอรายการเพิ่มไม่ได้';
                    break;
                default:
                    $error = 'รายการสิ่งของรอบปัจจุบันยังเปิดรับบริจาคอยู่ ระบบจะเปิดให้เสนอรอบใหม่เมื่อครบ 1 เดือน';
                    break;
            }
        }
    }
    $existingNeedRow = null;
    if ($itemIdEdit > 0) {
        $chkOwn = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? AND foundation_id = ? LIMIT 1');
        if (!$chkOwn) {
            $error = 'Prepare failed: ' . $conn->error;
        } else {
            $chkOwn->bind_param('ii', $itemIdEdit, $foundation_id);
            $chkOwn->execute();
            $existingNeedRow = $chkOwn->get_result()->fetch_assoc();
            if (!$existingNeedRow) {
                $error = 'ไม่พบรายการสิ่งของหรือไม่มีสิทธิแก้ไข';
            } elseif (!drawdream_foundation_needlist_may_edit($existingNeedRow)) {
                $error = 'รายการนี้มีผู้บริจาคแล้วหรืออยู่ขั้นตอนถัดไป จึงแก้ไขไม่ได้';
            }
        }
    }

    if ($error !== '') {
        // ข้าม validation เมื่อตรวจสิทธิ์แก้ไขไม่ผ่าน
    } else {

    $lineItems = [];
    $itemNames = [];
    $goal = 0.0;
    for ($slot = 1; $slot <= 5; $slot++) {
        $cat = trim((string)($_POST['item_category_' . $slot] ?? ''));
        $opt = trim((string)($_POST['item_option_' . $slot] ?? ''));
        $custom = trim((string)($_POST['item_custom_' . $slot] ?? ''));
        $priceSlot = (float)($_POST['item_price_' . $slot] ?? 0);
        $qtySlot = (float)($_POST['item_qty_' . $slot] ?? 0);

        $hasAny = ($cat !== '' || $opt !== '' || $custom !== '' || $priceSlot > 0 || $qtySlot > 0);
        if (!$hasAny) {
            continue;
        }
        if ($cat === '' || !in_array($cat, $itemCategories, true)) {
            $error = "ช่องรายการที่ {$slot}: กรุณาเลือกหมวดหมู่สิ่งของ";
            break;
        }
        if ($cat === 'อื่นๆ ที่จำเป็นเฉพาะทาง' && $opt === '') {
            $opt = '__other__';
            $_POST['item_option_' . $slot] = '__other__';
        }
        $allowedOptions = $categoryItems[$cat] ?? [];
        $allowedWithOther = array_merge($allowedOptions, ['__other__']);
        if ($opt === '' || !in_array($opt, $allowedWithOther, true)) {
            $error = "ช่องรายการที่ {$slot}: กรุณาเลือกรายการสิ่งของตามหมวด";
            break;
        }
        $itemName = $opt;
        if ($opt === '__other__') {
            if ($custom === '') {
                $error = "ช่องรายการที่ {$slot}: กรุณาระบุรายการอื่นๆ";
                break;
            }
            if (mb_strlen($custom, 'UTF-8') > 200) {
                $error = "ช่องรายการที่ {$slot}: รายการอื่นๆ ต้องไม่เกิน 200 ตัวอักษร";
                break;
            }
            $itemName = $custom;
        }
        if ($priceSlot <= 0) {
            $error = "ช่องรายการที่ {$slot}: กรุณากรอกราคาที่มากกว่า 0";
            break;
        }
        if ($qtySlot <= 0) {
            $error = "ช่องรายการที่ {$slot}: กรุณากรอกจำนวนชิ้นที่มากกว่า 0";
            break;
        }
        $lineTotal = $priceSlot * $qtySlot;
        $goal += $lineTotal;
        $lineItems[] = [
            'slot' => $slot,
            'category' => $cat,
            'item_name' => $itemName,
            'price' => $priceSlot,
            'qty' => $qtySlot,
            'line_total' => $lineTotal,
        ];
        $itemNames[] = $itemName;
    }

    if ($error === '' && count($lineItems) < 1) {
        $error = "กรุณากรอกอย่างน้อย 1 รายการสิ่งของ";
    }
    if ($error === '' && count($lineItems) > 5) {
        $error = "กรอกรายการได้สูงสุด 5 ช่อง";
    }

    $desiredBrand = trim((string)($_POST['desired_brand'] ?? ''));
    $allow_other = isset($_POST['allow_any_brand']) ? 1 : 0;
    $urgent      = isset($_POST['urgent']) ? 1 : 0;
    $note        = trim($_POST['note'] ?? '');
    $qty         = 0.0;
    $item_name = foundation_needlist_build_item_name_label($itemNames);
    $needItemsJson = '';
    $needItemsPricingJson = '';
    foreach ($lineItems as $li) {
        $qty += (float)$li['qty'];
    }
    if ($error === '') {
        $lineSummary = [];
        foreach ($lineItems as $li) {
            $lineSummary[] = sprintf(
                '[%d] %s | %s | %s ชิ้น × %s บาท = %s บาท',
                (int)$li['slot'],
                (string)$li['category'],
                (string)$li['item_name'],
                number_format((float)$li['qty'], 0),
                number_format((float)$li['price'], 2),
                number_format((float)$li['line_total'], 2)
            );
        }
        $lineSummaryText = implode("\n", $lineSummary);
        if ($allow_other !== 1 && $desiredBrand === '') {
            $error = 'กรุณากรอกแบรนด์ที่ต้องการ หรือเลือกว่ายอมรับแบรนด์ไหนก็ได้';
        }
        if ($error === '' && mb_strlen($desiredBrand, 'UTF-8') > 200) {
            $error = 'แบรนด์ที่ต้องการต้องไม่เกิน 200 ตัวอักษร';
        }
        $encodedLines = foundation_needlist_encode_line_items_json(array_map(static function (array $li): array {
            return [
                'slot' => (int)($li['slot'] ?? 0),
                'category' => (string)($li['category'] ?? ''),
                'item_name' => (string)($li['item_name'] ?? ''),
                'qty' => (float)($li['qty'] ?? 0),
                'price' => (float)($li['price'] ?? 0),
                'line_total' => (float)($li['line_total'] ?? 0),
            ];
        }, $lineItems));
        $needItemsJson = foundation_needlist_items_json_strip_prices($encodedLines['items_json']);
        $needItemsPricingJson = $encodedLines['pricing_json'];
        $_POST['goal_amount'] = (string)round($goal, 2);
        $_POST['desired_brand'] = $desiredBrand;
        if ($allow_other === 1) {
            $_POST['allow_any_brand'] = '1';
        } else {
            unset($_POST['allow_any_brand']);
        }
    }

    if ($error === "") {
        if ($goal <= 0) {
            $error = "ยอดเป้าหมายเงินบริจาคต้องมากกว่า 0";
        }
    }

    // อัปโหลดรูป (บังคับอย่างน้อย 1 รูปสิ่งของ + รูปมูลนิธิ — หรือคงรูปเดิมตอนแก้ไข)
    $uploadedImages = [];
    if ($error === "" && isset($_FILES['item_image']) && is_array($_FILES['item_image']['name'])) {
        $uploadDir = drawdream_needlist_upload_dir();
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $names = $_FILES['item_image']['name'];
        $tmpNames = $_FILES['item_image']['tmp_name'];
        $sizes = $_FILES['item_image']['size'];
        $errors = $_FILES['item_image']['error'];

        $pickedCount = 0;
        foreach ($names as $nm) {
            if (trim((string)$nm) !== '') $pickedCount++;
        }

        if ($pickedCount > 3) {
            $error = "อัปโหลดได้สูงสุด 3 รูป";
        } else {
            foreach ($names as $idx => $imageName) {
                if (trim((string)$imageName) === '') continue;

                $errCode = (int)$errors[$idx];
                if ($errCode === UPLOAD_ERR_NO_FILE) continue;
                if ($errCode !== UPLOAD_ERR_OK) {
                    $error = drawdream_upload_error_message_th($errCode, 'รูปสิ่งของ');
                    break;
                }

                $fileSize = (int)$sizes[$idx];
                $tmpPath = (string)$tmpNames[$idx];
                if (!drawdream_upload_is_image_tmp($tmpPath, (string)$imageName)) {
                    $error = "อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp";
                    break;
                }

                $resolvedExt = drawdream_upload_resolve_image_ext($tmpPath, (string)$imageName);
                $forceJpeg = drawdream_upload_needs_jpeg_output($tmpPath, (string)$imageName, $fileSize, $needMaxUploadBytes);
                $outExt = $forceJpeg ? 'jpg' : $resolvedExt;
                $safeName = time() . "_" . uniqid() . "_" . $idx . "." . $outExt;
                $targetPath = $uploadDir . $safeName;
                if (!drawdream_store_compressed_upload($tmpPath, $targetPath, $needMaxUploadBytes, $forceJpeg)) {
                    $error = $forceJpeg
                        ? "บีบอัด/แปลงรูปไม่สำเร็จ — ลองบันทึกเป็น JPG แล้วอัปโหลดใหม่"
                        : "อัปโหลดรูปไม่สำเร็จ";
                    break;
                }

                $uploadedImages[] = $safeName;
            }
        }
    }

    $needFoundationImageDb = '';
    if ($error === "" && isset($_FILES['foundation_need_image'])) {
        $ff = $_FILES['foundation_need_image'];
        $errF = (int)($ff['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errF === UPLOAD_ERR_OK) {
            $uploadDir = drawdream_needlist_upload_dir();
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $imgName = (string)($ff['name'] ?? '');
            $tmpF = (string)($ff['tmp_name'] ?? '');
            $sizeF = (int)($ff['size'] ?? 0);
            if (!drawdream_upload_is_image_tmp($tmpF, $imgName)) {
                $error = "รูปมูลนิธิ อนุญาตเฉพาะไฟล์รูป";
            } elseif ($tmpF === '' || !is_uploaded_file($tmpF)) {
                $error = "อัปโหลดรูปมูลนิธิไม่สำเร็จ";
            } else {
                $forceJpeg = drawdream_upload_needs_jpeg_output($tmpF, $imgName, $sizeF, $needMaxUploadBytes);
                $outExt = $forceJpeg ? 'jpg' : drawdream_upload_resolve_image_ext($tmpF, $imgName);
                $safeF = time() . "_" . uniqid('', true) . "_fdn." . $outExt;
                if (!drawdream_store_compressed_upload($tmpF, $uploadDir . $safeF, $needMaxUploadBytes, $forceJpeg)) {
                    $error = $forceJpeg
                        ? "บีบอัด/แปลงรูปมูลนิธิไม่สำเร็จ — ลองบันทึกเป็น JPG แล้วอัปโหลดใหม่"
                        : "บันทึกไฟล์รูปมูลนิธิไม่สำเร็จ";
                } else {
                    $needFoundationImageDb = $safeF;
                }
            }
        } elseif ($errF !== UPLOAD_ERR_NO_FILE) {
            $error = drawdream_upload_error_message_th($errF, 'รูปมูลนิธิ');
        }
    }

    } // end skip validation when $error set early

    if ($error === '') {
        $checkIm0 = $uploadedImages[0] ?? '';
        $checkIm1 = $uploadedImages[1] ?? '';
        $checkIm2 = $uploadedImages[2] ?? '';
        $checkFdn = $needFoundationImageDb;
        if ($itemIdEdit > 0 && $existingNeedRow) {
            $mergedCheck = foundation_needlist_item_filenames_from_row($existingNeedRow);
            while (count($mergedCheck) < 3) {
                $mergedCheck[] = '';
            }
            if ($checkIm0 === '' && trim((string)($mergedCheck[0] ?? '')) !== '') {
                $checkIm0 = (string)$mergedCheck[0];
            }
            if ($checkIm1 === '' && trim((string)($mergedCheck[1] ?? '')) !== '') {
                $checkIm1 = (string)$mergedCheck[1];
            }
            if ($checkIm2 === '' && trim((string)($mergedCheck[2] ?? '')) !== '') {
                $checkIm2 = (string)$mergedCheck[2];
            }
            if ($checkFdn === '') {
                $checkFdn = foundation_needlist_normalize_filename((string)($existingNeedRow['need_foundation_image'] ?? ''));
            }
        }
        $checkRow = [
            'need_items_json' => $needItemsJson,
            'need_items_pricing_json' => $needItemsPricingJson,
            'item_name' => $item_name,
            'item_image' => $checkIm0,
            'item_image_2' => $checkIm1,
            'item_image_3' => $checkIm2,
            'need_foundation_image' => $checkFdn,
        ];
        if (!foundation_needlist_is_donation_ready($checkRow)) {
            $error = 'ข้อมูลยังไม่ครบสำหรับส่งให้แอดมิน: ' . foundation_needlist_readiness_message_th($checkRow);
        }
    }

    // บันทึก
    if ($error === "") {
        $total_price = $goal;

        $slot0 = $uploadedImages[0] ?? '';
        $slot1 = $uploadedImages[1] ?? '';
        $slot2 = $uploadedImages[2] ?? '';

        if ($itemIdEdit > 0 && $existingNeedRow) {
            $merged = foundation_needlist_item_filenames_from_row($existingNeedRow);
            while (count($merged) < 3) {
                $merged[] = '';
            }
            $merged = array_slice($merged, 0, 3);
            if ($slot0 !== '') {
                $merged[0] = $slot0;
            }
            if ($slot1 !== '') {
                $merged[1] = $slot1;
            }
            if ($slot2 !== '') {
                $merged[2] = $slot2;
            }
            $im0 = $merged[0] ?? '';
            $im1 = $merged[1] ?? '';
            $im2 = $merged[2] ?? '';

            $nfFinal = foundation_needlist_normalize_filename((string)($existingNeedRow['need_foundation_image'] ?? ''));
            if ($needFoundationImageDb !== '') {
                $nfFinal = $needFoundationImageDb;
            }

            $hasSubmittedNeedItemsJson = false;
            $chkSubmittedItemsJson = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'submitted_need_items_json'");
            if ($chkSubmittedItemsJson && $chkSubmittedItemsJson->num_rows > 0) {
                $hasSubmittedNeedItemsJson = true;
            }
            $hasFoundationOriginal = false;
            $chkFoundationOriginal = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'foundation_original_total_price'");
            if ($chkFoundationOriginal && $chkFoundationOriginal->num_rows > 0) {
                $hasFoundationOriginal = true;
            }

            $sqlU = "UPDATE foundation_needlist SET
                item_name = ?, desired_brand = ?, allow_other_brand = ?,
                qty_needed = ?, urgent = ?,
                item_image = ?, item_image_2 = ?, item_image_3 = ?, need_foundation_image = ?,
                note = ?, total_price = ?, submitted_total_price = ?,
                need_items_json = ?, need_items_pricing_json = ?, submitted_need_items_pricing_json = ?";
            if ($hasSubmittedNeedItemsJson) {
                $sqlU .= ", submitted_need_items_json = ?";
            }
            if ($hasFoundationOriginal) {
                $sqlU .= ", foundation_original_total_price = COALESCE(foundation_original_total_price, ?),
                    foundation_original_need_items_pricing_json = COALESCE(foundation_original_need_items_pricing_json, ?)";
                if ($hasSubmittedNeedItemsJson) {
                    $sqlU .= ", foundation_original_need_items_json = COALESCE(foundation_original_need_items_json, ?)";
                }
            }
            $sqlU .= " WHERE item_id = ? AND foundation_id = ?";
            $stmt = $conn->prepare($sqlU);

            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                if ($hasSubmittedNeedItemsJson && $hasFoundationOriginal) {
                    $updTypes = 'ssidisssssddssssdssii';
                    $stmt->bind_param(
                        $updTypes,
                        $item_name, $desiredBrand,
                        $allow_other, $qty, $urgent,
                        $im0, $im1, $im2, $nfFinal,
                        $note, $total_price, $total_price, $needItemsJson, $needItemsPricingJson, $needItemsPricingJson,
                        $needItemsJson,
                        $total_price, $needItemsPricingJson, $needItemsJson,
                        $itemIdEdit, $foundation_id
                    );
                } elseif ($hasSubmittedNeedItemsJson) {
                    $updTypes = 'ssidisssssddssssii';
                    $stmt->bind_param(
                        $updTypes,
                        $item_name, $desiredBrand,
                        $allow_other, $qty, $urgent,
                        $im0, $im1, $im2, $nfFinal,
                        $note, $total_price, $total_price, $needItemsJson, $needItemsPricingJson, $needItemsPricingJson,
                        $needItemsJson,
                        $itemIdEdit, $foundation_id
                    );
                } elseif ($hasFoundationOriginal) {
                    $updTypes = 'ssidisssssddsssdsii';
                    $stmt->bind_param(
                        $updTypes,
                        $item_name, $desiredBrand,
                        $allow_other, $qty, $urgent,
                        $im0, $im1, $im2, $nfFinal,
                        $note, $total_price, $total_price, $needItemsJson, $needItemsPricingJson, $needItemsPricingJson,
                        $total_price, $needItemsPricingJson,
                        $itemIdEdit, $foundation_id
                    );
                } else {
                    $updTypes = 'ssidisssssddsssii';
                    $stmt->bind_param(
                        $updTypes,
                        $item_name, $desiredBrand,
                        $allow_other, $qty, $urgent,
                        $im0, $im1, $im2, $nfFinal,
                        $note, $total_price, $total_price, $needItemsJson, $needItemsPricingJson, $needItemsPricingJson,
                        $itemIdEdit, $foundation_id
                    );
                }

                try {
                    $saved = $stmt->execute();
                } catch (mysqli_sql_exception $e) {
                    $saved = false;
                    if (str_contains($e->getMessage(), 'item_name')) {
                        $error = 'ชื่อรายการสิ่งของรวมยาวเกินไป — ลองใช้ชื่อสั้นลงหรือลดจำนวนรายการ';
                    } else {
                        $error = 'บันทึกไม่สำเร็จ กรุณาตรวจสอบข้อมูลอีกครั้ง';
                    }
                }

                if ($error === '' && !empty($saved) && is_array($existingNeedRow)) {
                    $resubmit = drawdream_foundation_needlist_resubmit_on_save($existingNeedRow);
                    if ($resubmit) {
                        $stPend = $conn->prepare(
                            "UPDATE foundation_needlist
                             SET approve_item='pending', donate_window_end_at=NULL
                             WHERE item_id = ? AND foundation_id = ?"
                        );
                        if ($stPend) {
                            $stPend->bind_param('ii', $itemIdEdit, $foundation_id);
                            $stPend->execute();
                        }
                        require_once __DIR__ . '/includes/notification_audit.php';
                        drawdream_notify_admins_need_submitted(
                            $conn,
                            (int)$itemIdEdit,
                            $item_name,
                            $foundation_display_name,
                            (float)$total_price,
                            (int)$urgent === 1
                        );
                    }
                    $flashKey = $resubmit ? 'resubmitted' : 'updated';
                    drawdream_foundation_need_save_redirect($flashKey, trim((string)($_POST['return_to'] ?? '')));
                }
                if ($error === '') {
                    $error = 'บันทึกไม่สำเร็จ: ' . $stmt->error;
                }
            }
        } else {
            $im0 = $slot0;
            $im1 = $slot1;
            $im2 = $slot2;

            $sql  = "INSERT INTO foundation_needlist 
                 (foundation_id, item_name, desired_brand, allow_other_brand,
                  qty_needed, urgent, item_image, item_image_2, item_image_3, need_foundation_image, note, total_price, submitted_total_price, approve_item)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                // รองรับทั้ง schema ใหม่ (มี need_items_json) และ schema เก่าที่ยังไม่ migration
                $hasNeedItemsJson = false;
                $hasNeedItemsPricingJson = false;
                $chkNeedJson = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'need_items_json'");
                if ($chkNeedJson && $chkNeedJson->num_rows > 0) {
                    $hasNeedItemsJson = true;
                }
                $chkNeedPricingJson = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'need_items_pricing_json'");
                if ($chkNeedPricingJson && $chkNeedPricingJson->num_rows > 0) {
                    $hasNeedItemsPricingJson = true;
                }
                if ($hasNeedItemsJson && $hasNeedItemsPricingJson) {
                    $sql = "INSERT INTO foundation_needlist
                        (foundation_id, item_name, desired_brand, allow_other_brand,
                         qty_needed, urgent, item_image, item_image_2, item_image_3, need_foundation_image, note, total_price, submitted_total_price, need_items_json, need_items_pricing_json, submitted_need_items_pricing_json, approve_item)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        $error = "Prepare failed: " . $conn->error;
                    }
                } elseif ($hasNeedItemsJson) {
                    $sql = "INSERT INTO foundation_needlist
                        (foundation_id, item_name, desired_brand, allow_other_brand,
                         qty_needed, urgent, item_image, item_image_2, item_image_3, need_foundation_image, note, total_price, submitted_total_price, need_items_json, approve_item)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        $error = "Prepare failed: " . $conn->error;
                    }
                }
            }

            if ($error === '' && $stmt) {
                if ($hasNeedItemsJson && $hasNeedItemsPricingJson) {
                    $stmt->bind_param(
                        'issidisssssddsss',
                        $foundation_id, $item_name, $desiredBrand,
                        $allow_other, $qty, $urgent, $im0, $im1, $im2, $needFoundationImageDb, $note, $total_price, $total_price, $needItemsJson, $needItemsPricingJson, $needItemsPricingJson
                    );
                } elseif ($hasNeedItemsJson) {
                    $stmt->bind_param(
                        'issidisssssdds',
                        $foundation_id, $item_name, $desiredBrand,
                        $allow_other, $qty, $urgent, $im0, $im1, $im2, $needFoundationImageDb, $note, $total_price, $total_price, $needItemsJson
                    );
                } else {
                    $stmt->bind_param(
                        'issidisssssdd',
                        $foundation_id, $item_name, $desiredBrand,
                        $allow_other, $qty, $urgent, $im0, $im1, $im2, $needFoundationImageDb, $note, $total_price, $total_price
                    );
                }

                try {
                    $saved = $stmt->execute();
                } catch (mysqli_sql_exception $e) {
                    $saved = false;
                    if (str_contains($e->getMessage(), 'item_name')) {
                        $error = 'ชื่อรายการสิ่งของรวมยาวเกินไป — ลองใช้ชื่อสั้นลงหรือลดจำนวนรายการ';
                    } else {
                        $error = 'บันทึกไม่สำเร็จ กรุณาตรวจสอบข้อมูลอีกครั้ง';
                    }
                }

                if ($error === '' && !empty($saved)) {
                    $newItemId = (int)$conn->insert_id;
                    if ($newItemId > 0) {
                        require_once __DIR__ . '/includes/notification_audit.php';
                        drawdream_ensure_notifications_table($conn);
                        drawdream_record_foundation_submitted_need($conn, $uid, $newItemId, $item_name, $total_price, $foundation_display_name, $urgent === 1);
                        drawdream_notify_admins_need_submitted($conn, $newItemId, $item_name, $foundation_display_name, $total_price, $urgent === 1);
                    }
                    drawdream_foundation_need_save_redirect('created', trim((string)($_POST['return_to'] ?? '')));
                }
                if ($error === '') {
                    $error = 'บันทึกไม่สำเร็จ: ' . $stmt->error;
                }
            }
        }
    }
}

$hiddenItemId = (int)($_POST['item_id'] ?? $editItemPg);
$isEditForm = $hiddenItemId > 0;
$needWizardInitialStep = 1;
if ($error !== '') {
    if (preg_match('/แบรนด์|รูป|อัปโหลด|มูลนิธิ|ไฟล์/u', $error)) {
        $needWizardInitialStep = 2;
    } elseif (preg_match('/รายการ|ช่อง|หมวด|จำนวน|เป้าหมาย|กรอกอย่างน้อย/u', $error)) {
        $needWizardInitialStep = 1;
    } else {
        $needWizardInitialStep = 2;
    }
}
$thumbRow = null;
if ($hiddenItemId > 0) {
    $trTh = $conn->prepare('SELECT item_image, item_image_2, item_image_3, need_foundation_image FROM foundation_needlist WHERE item_id = ? AND foundation_id = ? LIMIT 1');
    if ($trTh) {
        $trTh->bind_param('ii', $hiddenItemId, $foundation_id);
        $trTh->execute();
        $thumbRow = $trTh->get_result()->fetch_assoc();
    }
}
$pageTitle = $isEditForm ? 'แก้ไขรายการสิ่งของมูลนิธิ' : 'เสนอสิ่งของมูลนิธิ';
$editResubmitApproved = $isEditForm
    && is_array($editRow)
    && strtolower(trim((string)($editRow['approve_item'] ?? ''))) === 'approved';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?> | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css?v=31">
    <link rel="stylesheet" href="css/foundation_manage.css?v=2">
    <style>
        .need-wizard-banner { background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:12px 14px;margin:0 0 14px;color:#9a3412;font-size:.9rem;line-height:1.5; }
        .need-wizard-tabs { display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;padding:0;list-style:none; }
        .need-wizard-tabs li { padding:8px 14px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;font-size:.85rem;color:#64748b; }
        .need-wizard-tabs li.need-wizard-tabs__active { background:#4A5BA8;border-color:#4A5BA8;color:#fff;font-weight:600; }
        .need-wizard-tabs li.need-wizard-tabs__warn { box-shadow: inset 0 0 0 2px #f87171; }
        .need-checklist { margin:0 0 16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;list-style:none;display:grid;gap:6px; }
        .need-checklist li { font-size:.86rem;color:#64748b; }
        .need-checklist li.need-checklist__ok { color:#166534; }
        .need-checklist li.need-checklist__ok::before { content:'✓ '; font-weight:700; }
        .need-checklist li.need-checklist__no::before { content:'○ '; }
        .need-form-steps { position:relative; }
        .need-form-step { visibility:hidden;height:0;overflow:hidden;opacity:0;pointer-events:none; }
        .need-form-step.need-form-step--active { visibility:visible;height:auto;overflow:visible;opacity:1;pointer-events:auto; }
        .need-wizard-nav { display:flex;gap:10px;justify-content:flex-end;margin:16px 0 8px; }
        .need-wizard-nav button { padding:10px 18px;border-radius:10px;border:1px solid #d1d5db;background:#fff;cursor:pointer;font-size:.9rem; }
        .need-wizard-nav .need-step-next { background:#4A5BA8;color:#fff;border-color:#4A5BA8; }
        .need-preview-summary { border:1px dashed #cbd5e1;border-radius:12px;padding:14px;background:#fff;font-size:.9rem;line-height:1.55; }
        .need-preview-modal { display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;align-items:center;justify-content:center;padding:16px; }
        .need-preview-modal.need-preview-modal--open { display:flex; }
        .need-preview-modal__box { max-width:520px;width:100%;background:#fff;border-radius:14px;padding:18px 20px;max-height:85vh;overflow:auto; }
        .need-form-file-sink { position:fixed;left:0;top:0;width:1px;height:1px;overflow:hidden;opacity:0;z-index:-1; }
        .need-form-file-sink .need-image-file-input { width:1px;height:1px;font-size:0; }
    </style>
</head>
<body class="foundation-add-need-page">

<?php include 'navbar.php'; ?>

<div class="add-need-container">
    <p class="add-need-back"><a href="<?= htmlspecialchars($needReturnTo, ENT_QUOTES, 'UTF-8') ?>" class="add-need-back-link" data-foundation-back>← กลับ</a></p>
    <h2><?= htmlspecialchars($pageTitle) ?></h2>
    <?php if ($editResubmitApproved): ?>
        <div class="alert alert-warning needlist-flash" role="status">
            รายการนี้อนุมัติแล้วแต่ยังไม่มียอดบริจาค — เมื่อบันทึก ระบบจะส่งให้แอดมินตรวจอนุมัติใหม่
        </div>
    <?php endif; ?>
    <?php if (!$isEditForm): ?>
        <div class="alert alert-success" style="background:#eef6ff;border:1px solid #cfe1ff;color:#23417c;">
            รอบรับบริจาครายการสิ่งของจะปิดอัตโนมัติเมื่อครบ 1 เดือนนับจากวันที่แอดมินอนุมัติรายการ
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error" id="needFormError" role="alert">
            <?= htmlspecialchars($error) ?>
            <?php if ($needWizardInitialStep === 2): ?>
                <p class="need-form-error-hint" style="margin:8px 0 0;font-size:.9rem;">กรุณาไปที่แท็บ <strong>2. รูปและแบรนด์</strong> ด้านล่างเพื่อแก้ไข (ยังกรอกขั้นที่ 1 ต่อได้ตามปกติ)</p>
            <?php elseif ($needWizardInitialStep === 1): ?>
                <p class="need-form-error-hint" style="margin:8px 0 0;font-size:.9rem;">แก้ไขได้ที่แท็บ <strong>1. รายการสิ่งของ</strong></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="need-wizard-banner" role="note">
        <strong>สำคัญ:</strong> ถ้าไม่กรอกรายการย่อยครบ (ชื่อ · ราคา · จำนวน · รูป) ผู้บริจาคจะเลือกสิ่งของไม่ได้ และรายการจะไม่ผ่านการอนุมัติ
    </div>
    <ol class="need-wizard-tabs" id="needWizardTabs" aria-label="ขั้นตอนฟอร์ม">
        <li<?= $needWizardInitialStep === 1 ? ' class="need-wizard-tabs__active"' : '' ?> data-step-tab="1">1. รายการสิ่งของ</li>
        <?php
        $tab2Class = [];
        if ($needWizardInitialStep === 2) {
            $tab2Class[] = 'need-wizard-tabs__active';
        }
        if ($error !== '' && $needWizardInitialStep === 2) {
            $tab2Class[] = 'need-wizard-tabs__warn';
        }
        $tab2ClassAttr = $tab2Class !== [] ? ' class="' . implode(' ', $tab2Class) . '"' : '';
        ?>
        <li<?= $tab2ClassAttr ?> data-step-tab="2">2. รูปและแบรนด์</li>
        <li<?= $needWizardInitialStep === 3 ? ' class="need-wizard-tabs__active"' : '' ?> data-step-tab="3">3. สรุปก่อนส่ง</li>
    </ol>
    <ul class="need-checklist" id="needChecklist" aria-live="polite">
        <li class="need-checklist__no" data-check="items">รายการย่อยอย่างน้อย 1 รายการ</li>
        <li class="need-checklist__no" data-check="item-img">รูปสิ่งของอย่างน้อย 1 รูป</li>
        <li class="need-checklist__no" data-check="fdn-img">รูปมูลนิธิ</li>
        <li class="need-checklist__no" data-check="total">ยอดรวมตรงกับผลรวมรายการ</li>
    </ul>

    <form method="post" enctype="multipart/form-data" id="needMainForm">
        <?= drawdream_csrf_field() ?>
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($needReturnTo, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($hiddenItemId > 0): ?>
        <input type="hidden" name="item_id" value="<?= (int)$hiddenItemId ?>">
        <?php endif; ?>

        <div class="need-form-step<?= $needWizardInitialStep === 1 ? ' need-form-step--active' : '' ?>" data-step="1">
        <div class="form-row">
            <div class="form-col">

                <div class="form-group">
                    <label>รายการสิ่งของ (สูงสุด 5 ช่อง)</label>
                    <small style="color:#6b7280;display:block;margin-bottom:8px;">
                        แต่ละช่อง: เลือกหมวดหมู่ > เลือกสิ่งของ (หรือระบุเอง) > กรอกราคา > กรอกจำนวนชิ้น
                    </small>
                    <?php for ($slot = 1; $slot <= 5; $slot++): ?>
                        <?php
                        $catVal = (string)($_POST['item_category_' . $slot] ?? '');
                        $optVal = (string)($_POST['item_option_' . $slot] ?? '');
                        $customVal = (string)($_POST['item_custom_' . $slot] ?? '');
                        $priceVal = (string)($_POST['item_price_' . $slot] ?? '');
                        $qtyVal = (string)($_POST['item_qty_' . $slot] ?? '');
                        ?>
                        <div class="item-check-group need-line-slot" data-slot="<?= (int)$slot ?>">
                            <div class="item-check-group-title">รายการที่ <?= (int)$slot ?></div>
                            <div style="display:grid;grid-template-columns:2.1fr 2.1fr 1fr 1fr;gap:8px;">
                                <select name="item_category_<?= $slot ?>" class="need-slot-category" data-slot="<?= (int)$slot ?>">
                                    <option value="">เลือกหมวดหมู่</option>
                                    <?php foreach ($itemCategories as $category): ?>
                                        <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= $catVal === $category ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="item_option_<?= $slot ?>" class="need-slot-item" data-slot="<?= (int)$slot ?>" data-selected="<?= htmlspecialchars($optVal, ENT_QUOTES, 'UTF-8') ?>">
                                    <option value="">เลือกรายการสิ่งของ</option>
                                </select>
                                <input type="text" inputmode="decimal" name="item_price_<?= $slot ?>" class="need-slot-price" data-slot="<?= (int)$slot ?>" value="<?= htmlspecialchars($priceVal, ENT_QUOTES, 'UTF-8') ?>" placeholder="ราคา/ชิ้น">
                                <input type="text" inputmode="numeric" name="item_qty_<?= $slot ?>" class="need-slot-qty" data-slot="<?= (int)$slot ?>" value="<?= htmlspecialchars($qtyVal, ENT_QUOTES, 'UTF-8') ?>" placeholder="จำนวน">
                            </div>
                            <small class="need-slot-item-hint" data-slot="<?= (int)$slot ?>" style="display:none;color:#6b7280;margin-top:6px;">
                                หมวดอื่นๆ ใช้การกรอกรายการเองในช่องด้านล่าง
                            </small>
                            <div style="margin-top:8px;display:none;" class="need-slot-custom-wrap" data-slot="<?= (int)$slot ?>">
                                <input type="text" name="item_custom_<?= $slot ?>" class="need-slot-custom" data-slot="<?= (int)$slot ?>" maxlength="200" value="<?= htmlspecialchars($customVal, ENT_QUOTES, 'UTF-8') ?>" placeholder="ระบุรายการอื่นๆ">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

            </div>
        </div>
        </div><!-- step 1 -->

        <div class="need-form-step<?= $needWizardInitialStep === 2 ? ' need-form-step--active' : '' ?>" data-step="2">
        <div class="form-row">
            <div class="form-col">

                <div class="total-box" id="totalBox">
                    เป้าหมาย: 0 บาท
                </div>

                <div class="form-group">
                    <label>ยอดเป้าหมายเงินบริจาค (บาท)</label>
                    <input type="text" id="goalAmountDisplay" value="<?= htmlspecialchars($_POST['goal_amount'] ?? '0', ENT_QUOTES, 'UTF-8') ?>" readonly>
                    <input type="hidden" name="goal_amount" id="goalAmount" value="<?= htmlspecialchars($_POST['goal_amount'] ?? '0', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <?php if ($thumbRow && foundation_needlist_item_filenames_from_row($thumbRow) !== []): ?>
                <div class="form-group need-current-files">
                    <label>รูปสิ่งของที่มีอยู่</label>
                    <div class="need-current-thumbs">
                        <?php foreach (foundation_needlist_item_filenames_from_row($thumbRow) as $fn): ?>
                            <img src="uploads/needs/<?= htmlspecialchars($fn) ?>" alt="" class="need-current-thumb">
                        <?php endforeach; ?>
                    </div>
                    <small style="color:#6b7280;">อัปโหลดรูปใหม่เพื่อแทนที่ตามลำดับ (ช่อง 1–3)</small>
                </div>
                <?php endif; ?>
                <?php $currentFoundationNeedImage = $thumbRow ? foundation_needlist_normalize_filename((string)($thumbRow['need_foundation_image'] ?? '')) : ''; ?>
                <?php if ($currentFoundationNeedImage !== ''): ?>
                <div class="form-group need-current-files">
                    <label>รูปมูลนิธิปัจจุบัน</label>
                    <div class="need-current-thumbs">
                        <img src="uploads/needs/<?= htmlspecialchars($currentFoundationNeedImage) ?>" alt="" class="need-current-thumb">
                    </div>
                </div>
                <?php endif; ?>

                <div class="foundation-need-images form-group foundation-need-images--prominent">
                    <div class="need-images-duo">
                        <div class="need-images-duo__col">
                            <label class="need-images-duo__label">รูปสิ่งของ <span class="need-img-required">(บังคับ · สูงสุด 3 รูป)</span></label>
                            <p class="need-img-lead need-img-lead--compact">สินค้า / แพ็กที่ต้องการให้ผู้บริจาคเห็น</p>
                            <div class="image-upload-box">
                                <div class="upload-label" id="uploadLabel">
                                    <div class="upload-icon">📷</div>
                                    <div>เลือกรูปได้สูงสุด 3 รูป</div>
                                    <div class="upload-hint">เลือกรูปใหญ่ได้ ระบบบีบอัดอัตโนมัติก่อนส่ง (ไม่เกิน <?= htmlspecialchars($needMaxUploadLabel, ENT_QUOTES, 'UTF-8') ?>)</div>
                                </div>
                                <div class="image-upload-toolbar">
                                    <button type="button" class="btn-need-pick-img" id="btnNeedPickImg">เลือก / เพิ่มรูป</button>
                                </div>
                            </div>
                            <div id="imagePreviewList" class="upload-preview-list"></div>
                        </div>
                        <div class="need-images-duo__col need-images-duo__col--fdn">
                            <label class="need-images-duo__label">รูปมูลนิธิ <span class="need-img-required">(บังคับ · 1 รูป)</span></label>
                            <p class="need-img-lead need-img-lead--compact">แยกจากรูปสิ่งของ — โลโก้ ทีมงาน หรือภาพกิจกรรม</p>
                            <div class="image-upload-box image-upload-box--compact">
                                <div class="upload-label upload-label--compact" id="foundationUploadLabel">
                                    <div class="upload-icon upload-icon--sm">🏛️</div>
                                    <div class="upload-hint">เลือกรูปใหญ่ได้ ระบบบีบอัดอัตโนมัติก่อนส่ง (ไม่เกิน <?= htmlspecialchars($needMaxUploadLabel, ENT_QUOTES, 'UTF-8') ?>)</div>
                                </div>
                                <div class="image-upload-toolbar">
                                    <button type="button" class="btn-need-pick-img btn-need-pick-img--secondary" id="btnFoundationNeedImg">เลือกรูปมูลนิธิ</button>
                                </div>
                            </div>
                            <div id="foundationNeedPreview" class="foundation-need-fdn-preview"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>แบรนด์สินค้า</label>
                    <div class="checkbox-group">
                        <input type="checkbox" name="allow_any_brand" id="allowAnyBrand" <?= !empty($_POST['allow_any_brand']) ? 'checked' : '' ?>>
                        <label for="allowAnyBrand">ยอมรับแบรนด์ไหนก็ได้</label>
                    </div>
                    <input type="text" name="desired_brand" id="desiredBrandInput" value="<?= htmlspecialchars((string)($_POST['desired_brand'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="กรอกแบรนด์ที่ต้องการ">
                </div>

            </div>
        </div>
        </div><!-- step 2 -->

        <div class="need-form-step<?= $needWizardInitialStep === 3 ? ' need-form-step--active' : '' ?>" data-step="3">
        <div class="form-row">
            <div class="form-col">

                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="note" rows="3" placeholder="เช่น: รายละเอียดเพิ่มเติมเกี่ยวกับสิ่งของหรือการจัดส่ง"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" name="urgent" id="urgent" <?= !empty($_POST['urgent']) ? 'checked' : '' ?>>
                    <label for="urgent">ต้องการด่วน</label>
                </div>

                <div class="form-group" style="margin-top:14px;">
                    <label>สรุปก่อนส่ง</label>
                    <div class="need-preview-summary" id="needPreviewSummary">กรอกข้อมูลในขั้นที่ 1–2 แล้วกด «ดูตัวอย่าง»</div>
                    <button type="button" class="btn-need-pick-img" id="btnNeedPreview" style="margin-top:10px;">ดูตัวอย่างหน้าบริจาค</button>
                </div>

            </div>
        </div>
        </div><!-- step 3 -->

        <div class="need-wizard-nav">
            <button type="button" id="needStepPrev" style="display:none;">ย้อนกลับ</button>
            <button type="button" class="need-step-next" id="needStepNext">ถัดไป</button>
        </div>

        <button type="submit" name="submit" class="btn-submit" id="needSubmitBtn" style="display:none;"><?= $isEditForm ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูล' ?></button>

        <div class="need-form-file-sink" aria-hidden="true">
            <input type="file" name="item_image[]" id="fileInput" class="need-image-file-input" accept="image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,.heic,.heif" multiple tabindex="-1">
            <input type="file" name="foundation_need_image" id="foundationNeedImageInput" class="need-image-file-input" accept="image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,.heic,.heif" tabindex="-1">
        </div>
    </form>

    <div class="need-preview-modal" id="needPreviewModal" role="dialog" aria-modal="true" aria-labelledby="needPreviewTitle">
        <div class="need-preview-modal__box">
            <h3 id="needPreviewTitle" style="margin:0 0 10px;">ตัวอย่างที่ผู้บริจาคจะเห็น</h3>
            <div id="needPreviewBody"></div>
            <button type="button" class="btn-need-pick-img" id="needPreviewClose" style="margin-top:14px;">ปิด</button>
        </div>
    </div>
</div>

<script src="js/drawdream-image-compress.js?v=3"></script>
<script>
const goalAmount  = document.getElementById('goalAmount');
const goalAmountDisplay = document.getElementById('goalAmountDisplay');
const totalBox = document.getElementById('totalBox');
const urgentCheckbox = document.getElementById('urgent');
const allowAnyBrandCheckbox = document.getElementById('allowAnyBrand');
const desiredBrandInput = document.getElementById('desiredBrandInput');
const fileInput = document.getElementById('fileInput');
const btnNeedPickImg = document.getElementById('btnNeedPickImg');
const previewList = document.getElementById('imagePreviewList');
const needForm = document.getElementById('needMainForm');
const MAX_NEED_IMAGES = 3;
const MAX_NEED_IMAGE_BYTES = <?= (int)$needMaxUploadBytes ?>;
const MAX_NEED_SERVER_BYTES = <?= (int)$needServerUploadBytes ?>;
const MAX_NEED_IMAGE_LABEL = <?= json_encode($needMaxUploadLabel, JSON_UNESCAPED_UNICODE) ?>;
const slotCategoryEls = Array.from(document.querySelectorAll('.need-slot-category'));
const slotItemEls = Array.from(document.querySelectorAll('.need-slot-item'));
const slotPriceEls = Array.from(document.querySelectorAll('.need-slot-price'));
const slotQtyEls = Array.from(document.querySelectorAll('.need-slot-qty'));
const slotCustomWrapEls = Array.from(document.querySelectorAll('.need-slot-custom-wrap'));
const slotCustomEls = Array.from(document.querySelectorAll('.need-slot-custom'));
const categoryItemsMap = <?= json_encode($categoryItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
/** @type {File[]} */
let selectedFiles = [];
let needImagePickBusy = false;

function needImageSignature(file) {
    return `${file.name}|${file.size}|${file.lastModified}`;
}

function syncNeedFileInput() {
    const dt = new DataTransfer();
    selectedFiles.forEach((f) => dt.items.add(f));
    fileInput.files = dt.files;
}

function defaultNeedUploadLabelHtml() {
    return `
            <div class="upload-icon">📷</div>
            <div>เลือกรูปได้สูงสุด 3 รูป</div>
            <div class="upload-hint">รองรับ JPG, PNG, GIF, WEBP — เลือกรูปใหญ่ได้ ระบบบีบอัดอัตโนมัติก่อนส่ง (ไม่เกิน ${MAX_NEED_IMAGE_LABEL})</div>`;
}

function updateNeedUploadChrome() {
    const label = document.getElementById('uploadLabel');
    const n = selectedFiles.length;
    if (!label || !btnNeedPickImg) return;

    if (n === 0) {
        label.innerHTML = defaultNeedUploadLabelHtml();
        btnNeedPickImg.textContent = 'เลือกรูป';
        btnNeedPickImg.disabled = false;
        return;
    }

    const names = selectedFiles.map((f) => f.name).join(', ');
    label.innerHTML = `
            <div class="upload-icon">✓</div>
            <div class="need-upload-count">เลือกแล้ว ${n} / ${MAX_NEED_IMAGES} รูป</div>
            <div class="upload-hint need-upload-names">${names}</div>`;

    if (n >= MAX_NEED_IMAGES) {
        btnNeedPickImg.textContent = 'ครบ 3 รูปแล้ว';
        btnNeedPickImg.disabled = true;
    } else {
        btnNeedPickImg.textContent = `เพิ่มรูป (เหลือได้อีก ${MAX_NEED_IMAGES - n})`;
        btnNeedPickImg.disabled = false;
    }
}

function addNeedFilesFromPicker(incoming) {
    const arr = Array.from(incoming || []);
    if (!arr.length) return Promise.resolve();

    const room = MAX_NEED_IMAGES - selectedFiles.length;
    if (room <= 0) {
        drawdreamAlert('อัปโหลดได้สูงสุด 3 รูป\nกด «นำออก» บนรูปเพื่อลบแล้วเลือกใหม่');
        fileInput.value = '';
        return Promise.resolve();
    }

    const existing = new Set(selectedFiles.map(needImageSignature));
    const toPush = [];
    let skipped = 0;
    let compressed = 0;
    needImagePickBusy = true;
    if (btnNeedPickImg) {
        btnNeedPickImg.disabled = true;
        btnNeedPickImg.textContent = 'กำลังบีบอัดรูป…';
    }

    const work = (async () => {
        try {
            for (const f of arr) {
                if (toPush.length >= room) {
                    break;
                }
                if (!drawdreamImageCompress.isImageFile(f)) {
                    drawdreamAlert('ข้ามไฟล์ที่ไม่ใช่รูป: ' + f.name);
                    skipped++;
                    continue;
                }

                let fileToAdd = f;
                if (f.size > MAX_NEED_IMAGE_BYTES) {
                    try {
                        const before = f.size;
                        fileToAdd = await drawdreamImageCompress.ensureImageWithinLimit(f, MAX_NEED_IMAGE_BYTES, MAX_NEED_SERVER_BYTES);
                        if (fileToAdd.size < before) compressed++;
                    } catch (err) {
                        drawdreamAlert('บีบอัดรูปไม่สำเร็จ: ' + f.name);
                        skipped++;
                        continue;
                    }
                }

                const sig = needImageSignature(fileToAdd);
                if (existing.has(sig)) {
                    skipped++;
                    continue;
                }
                existing.add(sig);
                toPush.push(fileToAdd);
            }

            if (arr.length > room) {
                drawdreamAlert('เลือกครั้งนี้มีมากกว่าที่เหลือ — เพิ่มได้อีกสูงสุด ' + room + ' รูป (รวมไม่เกิน 3 รูป)');
            } else if (skipped > 0 && toPush.length === 0) {
                drawdreamAlert('ไม่มีไฟล์ที่เพิ่มได้ (ซ้ำ ชนิดไฟล์ หรือบีบอัดไม่สำเร็จ)');
            } else if (compressed > 0) {
                drawdreamAlert('บีบอัดรูปอัตโนมัติ ' + compressed + ' ไฟล์ให้ไม่เกิน ' + MAX_NEED_IMAGE_LABEL);
            }

            selectedFiles = selectedFiles.concat(toPush);
            syncNeedFileInput();
            updateNeedUploadChrome();
            renderPreviews();
            if (typeof updateNeedChecklist === 'function') updateNeedChecklist();
        } finally {
            needImagePickBusy = false;
            updateNeedUploadChrome();
        }
    })();

    return work;
}

async function ensureAllNeedImagesWithinLimit() {
    const next = [];
    for (const f of selectedFiles) {
        if (f.size <= MAX_NEED_IMAGE_BYTES) {
            next.push(f);
            continue;
        }
        next.push(await drawdreamImageCompress.ensureImageWithinLimit(f, MAX_NEED_IMAGE_BYTES, MAX_NEED_SERVER_BYTES));
    }
    selectedFiles = next;
    syncNeedFileInput();

    if (selectedFoundationFile) {
        if (selectedFoundationFile.size <= MAX_NEED_IMAGE_BYTES) {
            syncFoundationNeedFileInput();
            return;
        }
        selectedFoundationFile = await drawdreamImageCompress.ensureImageWithinLimit(
            selectedFoundationFile,
            MAX_NEED_IMAGE_BYTES,
            MAX_NEED_SERVER_BYTES
        );
        syncFoundationNeedFileInput();
    }
}

function removeNeedFileAt(index) {
    if (index < 0 || index >= selectedFiles.length) return;
    selectedFiles.splice(index, 1);
    syncNeedFileInput();
    updateNeedUploadChrome();
    renderPreviews();
    if (typeof updateNeedChecklist === 'function') updateNeedChecklist();
}

function updateTotal() {
    let g = 0;
    slotPriceEls.forEach((pEl, idx) => {
        const qEl = slotQtyEls[idx];
        const price = parseFloat((pEl && pEl.value) || '0');
        const qty = parseFloat((qEl && qEl.value) || '0');
        if (price > 0 && qty > 0) {
            g += (price * qty);
        }
    });
    goalAmount.value = String(g.toFixed(2));
    if (goalAmountDisplay) {
        goalAmountDisplay.value = g.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    totalBox.textContent = "เป้าหมาย: " + g.toLocaleString('th-TH', { minimumFractionDigits: 0 }) + " บาท";
}

function sanitizePriceInput(el) {
    if (!el) return;
    let v = String(el.value || '');
    v = v.replace(/,/g, '.');
    v = v.replace(/[^0-9.]/g, '');
    const firstDot = v.indexOf('.');
    if (firstDot !== -1) {
        v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, '');
    }
    el.value = v;
}

function sanitizeQtyInput(el) {
    if (!el) return;
    el.value = String(el.value || '').replace(/\D/g, '');
}

function syncSlotItemOptions(slot, opts = {}) {
    const forceResetItem = Boolean(opts.forceResetItem);
    const forceClearCustom = Boolean(opts.forceClearCustom);
    const catEl = document.querySelector(`.need-slot-category[data-slot="${slot}"]`);
    const itemEl = document.querySelector(`.need-slot-item[data-slot="${slot}"]`);
    const customWrap = document.querySelector(`.need-slot-custom-wrap[data-slot="${slot}"]`);
    const customInput = document.querySelector(`.need-slot-custom[data-slot="${slot}"]`);
    const itemHint = document.querySelector(`.need-slot-item-hint[data-slot="${slot}"]`);
    if (!catEl || !itemEl) return;
    const selectedCategory = catEl.value || '';
    const itemOptions = Array.isArray(categoryItemsMap[selectedCategory]) ? categoryItemsMap[selectedCategory] : [];
    const previous = forceResetItem ? '' : (itemEl.value || itemEl.getAttribute('data-selected') || '');
    itemEl.innerHTML = '';
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = 'เลือกรายการสิ่งของ';
    itemEl.appendChild(defaultOpt);
    itemOptions.forEach((name) => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        itemEl.appendChild(opt);
    });
    const otherOpt = document.createElement('option');
    otherOpt.value = '__other__';
    otherOpt.textContent = 'อื่นๆ (ระบุเอง)';
    itemEl.appendChild(otherOpt);
    if (previous && Array.from(itemEl.options).some((x) => x.value === previous)) {
        itemEl.value = previous;
    } else {
        itemEl.value = '';
    }
    if (selectedCategory === 'อื่นๆ ที่จำเป็นเฉพาะทาง') {
        itemEl.value = '__other__';
        itemEl.disabled = true;
        if (itemHint) itemHint.style.display = 'block';
    } else {
        itemEl.disabled = false;
        if (itemHint) itemHint.style.display = 'none';
    }
    if (customWrap) {
        customWrap.style.display = itemEl.value === '__other__' ? '' : 'none';
    }
    if (forceClearCustom && customInput) {
        customInput.value = '';
    }
}

slotCategoryEls.forEach((catEl) => {
    const slot = catEl.getAttribute('data-slot');
    syncSlotItemOptions(slot);
    catEl.addEventListener('change', () => {
        syncSlotItemOptions(slot, { forceResetItem: true, forceClearCustom: true });
    });
});
slotItemEls.forEach((itemEl) => {
    const slot = itemEl.getAttribute('data-slot');
    itemEl.addEventListener('change', () => {
        const customWrap = document.querySelector(`.need-slot-custom-wrap[data-slot="${slot}"]`);
        if (customWrap) {
            customWrap.style.display = itemEl.value === '__other__' ? '' : 'none';
        }
    });
});
slotPriceEls.forEach((el) => {
    el.addEventListener('input', () => {
        sanitizePriceInput(el);
        updateTotal();
    });
});
slotQtyEls.forEach((el) => {
    el.addEventListener('input', () => {
        sanitizeQtyInput(el);
        updateTotal();
    });
});

function syncDesiredBrandState() {
    if (!allowAnyBrandCheckbox || !desiredBrandInput) return;
    const allowAny = allowAnyBrandCheckbox.checked;
    desiredBrandInput.disabled = allowAny;
    desiredBrandInput.placeholder = allowAny ? 'เลือกยอมรับแบรนด์ไหนก็ได้แล้ว' : 'กรอกแบรนด์ที่ต้องการ';
    if (allowAny) {
        desiredBrandInput.value = '';
    }
}
if (allowAnyBrandCheckbox) {
    allowAnyBrandCheckbox.addEventListener('change', syncDesiredBrandState);
}

function renderPreviews() {
    previewList.innerHTML = '';
    if (!selectedFiles.length) return;

    selectedFiles.forEach((file, index) => {
        const item = document.createElement('div');
        item.className = 'upload-preview-item';

        const img = document.createElement('img');
        img.className = 'upload-preview-img';
        img.alt = 'preview';

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'upload-preview-remove';
        removeBtn.title = 'นำรูปนี้ออก';
        removeBtn.setAttribute('aria-label', 'นำรูปนี้ออก');
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', () => removeNeedFileAt(index));

        const badge = document.createElement('span');
        badge.className = 'upload-preview-urgent';
        badge.textContent = 'ต้องการด่วน';
        if (!urgentCheckbox.checked) badge.style.display = 'none';

        const cap = document.createElement('div');
        cap.className = 'upload-preview-cap';
        cap.textContent = `รูปที่ ${index + 1}`;

        const reader = new FileReader();
        reader.onload = function(evt) {
            img.src = evt.target.result;
        };
        reader.readAsDataURL(file);

        item.appendChild(img);
        item.appendChild(removeBtn);
        item.appendChild(badge);
        item.appendChild(cap);
        previewList.appendChild(item);
    });
}

fileInput.addEventListener('change', function(e) {
    addNeedFilesFromPicker(e.target.files);
});

if (btnNeedPickImg) {
    btnNeedPickImg.addEventListener('click', () => fileInput.click());
}

const foundationNeedImageInput = document.getElementById('foundationNeedImageInput');
const btnFoundationNeedImg = document.getElementById('btnFoundationNeedImg');
const foundationNeedPreview = document.getElementById('foundationNeedPreview');
/** @type {File|null} */
let selectedFoundationFile = null;

function syncFoundationNeedFileInput() {
    if (!foundationNeedImageInput) return;
    const dt = new DataTransfer();
    if (selectedFoundationFile) {
        dt.items.add(selectedFoundationFile);
    }
    foundationNeedImageInput.files = dt.files;
}

function buildNeedFormData() {
    if (!needForm) throw new Error('no form');
    const fd = new FormData(needForm);
    if (typeof fd.delete === 'function') {
        fd.delete('item_image[]');
        fd.delete('foundation_need_image');
    }
    selectedFiles.forEach((file) => {
        fd.append('item_image[]', file, file.name || 'item.jpg');
    });
    if (selectedFoundationFile) {
        fd.append('foundation_need_image', selectedFoundationFile, selectedFoundationFile.name || 'foundation.jpg');
    }
    if (!fd.has('submit')) {
        fd.append('submit', '1');
    }
    return fd;
}

async function submitNeedFormViaFetch() {
    if (!needForm) throw new Error('no form');
    const fd = buildNeedFormData();
    const postUrl = needForm.getAttribute('action') || window.location.href;
    const res = await fetch(postUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
    });
    if (res.redirected) {
        window.location.assign(res.url);
        return;
    }
    const html = await res.text();
    document.open();
    document.write(html);
    document.close();
}

function clearFoundationNeedPreview() {
    if (foundationNeedPreview) {
        foundationNeedPreview.innerHTML = '';
    }
}

if (btnFoundationNeedImg && foundationNeedImageInput) {
    btnFoundationNeedImg.addEventListener('click', () => foundationNeedImageInput.click());
}

if (foundationNeedImageInput) {
    foundationNeedImageInput.addEventListener('change', async function() {
        clearFoundationNeedPreview();
        const f = this.files && this.files[0];
        if (!f) {
            selectedFoundationFile = null;
            syncFoundationNeedFileInput();
            return;
        }
        if (!drawdreamImageCompress.isImageFile(f)) {
            drawdreamAlert('กรุณาเลือกไฟล์รูปเท่านั้น');
            this.value = '';
            return;
        }

        if (btnFoundationNeedImg) {
            btnFoundationNeedImg.disabled = true;
            btnFoundationNeedImg.textContent = 'กำลังประมวลผลรูป…';
        }
        let processed = f;
        if (f.size > MAX_NEED_IMAGE_BYTES) {
            try {
                const before = f.size;
                processed = await drawdreamImageCompress.ensureImageWithinLimit(f, MAX_NEED_IMAGE_BYTES, MAX_NEED_SERVER_BYTES);
                if (processed.size < before) {
                    drawdreamAlert('บีบอัดรูปมูลนิธิอัตโนมัติจาก ' + Math.round(before / 1024) + ' KB เป็น ' + Math.round(processed.size / 1024) + ' KB');
                }
            } catch (err) {
                drawdreamAlert('บีบอัดรูปไม่สำเร็จ — ลองเลือกไฟล์อื่น');
                this.value = '';
                selectedFoundationFile = null;
                syncFoundationNeedFileInput();
                if (btnFoundationNeedImg) {
                    btnFoundationNeedImg.disabled = false;
                    btnFoundationNeedImg.textContent = 'เลือกรูปมูลนิธิ';
                }
                return;
            }
        }

        selectedFoundationFile = processed;
        if (processed !== f) {
            syncFoundationNeedFileInput();
        }
        const wrap = document.createElement('div');
        wrap.className = 'upload-preview-item foundation-fdn-preview-item';
        const img = document.createElement('img');
        img.className = 'upload-preview-img';
        img.alt = '';
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'upload-preview-remove';
        rm.title = 'นำรูปมูลนิธิออก';
        rm.setAttribute('aria-label', 'นำรูปมูลนิธิออก');
        rm.textContent = '×';
        rm.addEventListener('click', () => {
            selectedFoundationFile = null;
            foundationNeedImageInput.value = '';
            syncFoundationNeedFileInput();
            clearFoundationNeedPreview();
            if (typeof updateNeedChecklist === 'function') updateNeedChecklist();
        });
        const reader = new FileReader();
        reader.onload = (evt) => { img.src = evt.target.result; };
        reader.readAsDataURL(processed);
        wrap.appendChild(img);
        wrap.appendChild(rm);
        foundationNeedPreview.appendChild(wrap);
        if (typeof updateNeedChecklist === 'function') updateNeedChecklist();
        if (btnFoundationNeedImg) {
            btnFoundationNeedImg.disabled = false;
            btnFoundationNeedImg.textContent = 'เลือกรูปมูลนิธิ';
        }
    });
}

urgentCheckbox.addEventListener('change', renderPreviews);

updateNeedUploadChrome();
updateTotal();
syncDesiredBrandState();

(function needFormWizard() {
    const NEED_WIZARD_INITIAL_STEP = <?= (int)$needWizardInitialStep ?>;
    const HAS_EXISTING_ITEM_IMGS = <?= json_encode($thumbRow && foundation_needlist_item_filenames_from_row($thumbRow) !== []) ?>;
    const HAS_EXISTING_FDN_IMG = <?= json_encode($currentFoundationNeedImage !== '') ?>;
    let currentStep = 1;
    const maxStep = 3;
    const steps = Array.from(document.querySelectorAll('.need-form-step'));
    const tabs = Array.from(document.querySelectorAll('#needWizardTabs [data-step-tab]'));
    const btnPrev = document.getElementById('needStepPrev');
    const btnNext = document.getElementById('needStepNext');
    const btnSubmit = document.getElementById('needSubmitBtn');
    const previewSummary = document.getElementById('needPreviewSummary');
    const previewModal = document.getElementById('needPreviewModal');
    const previewBody = document.getElementById('needPreviewBody');
    const btnPreview = document.getElementById('btnNeedPreview');
    const btnPreviewClose = document.getElementById('needPreviewClose');

    function countValidItems() {
        let n = 0;
        slotPriceEls.forEach((pEl, idx) => {
            const qEl = slotQtyEls[idx];
            const catEl = slotCategoryEls[idx];
            const itemEl = slotItemEls[idx];
            const price = parseFloat((pEl && pEl.value) || '0');
            const qty = parseFloat((qEl && qEl.value) || '0');
            const hasCat = catEl && catEl.value;
            const hasItem = itemEl && itemEl.value;
            if (hasCat && hasItem && price > 0 && qty > 0) n++;
        });
        return n;
    }

    function updateChecklist() {
        const itemsOk = countValidItems() >= 1;
        const itemImgOk = HAS_EXISTING_ITEM_IMGS || selectedFiles.length >= 1;
        const fdnOk = HAS_EXISTING_FDN_IMG || selectedFoundationFile !== null;
        let sum = 0;
        slotPriceEls.forEach((pEl, idx) => {
            const qEl = slotQtyEls[idx];
            const price = parseFloat((pEl && pEl.value) || '0');
            const qty = parseFloat((qEl && qEl.value) || '0');
            if (price > 0 && qty > 0) sum += price * qty;
        });
        const goal = parseFloat((goalAmount && goalAmount.value) || '0');
        const totalOk = sum > 0 && Math.abs(sum - goal) < 0.02;
        const map = { items: itemsOk, 'item-img': itemImgOk, 'fdn-img': fdnOk, total: totalOk };
        document.querySelectorAll('#needChecklist [data-check]').forEach((li) => {
            const key = li.getAttribute('data-check');
            const ok = map[key];
            li.classList.toggle('need-checklist__ok', ok);
            li.classList.toggle('need-checklist__no', !ok);
        });
        if (previewSummary) {
            previewSummary.innerHTML = itemsOk
                ? ('รายการ ' + countValidItems() + ' ช่อง · เป้าหมาย ' + goal.toLocaleString('th-TH', { minimumFractionDigits: 0 }) + ' บาท' + (itemImgOk && fdnOk ? ' · พร้อมส่ง' : ' · ยังขาดรูป'))
                : 'กรอกรายการสิ่งของในขั้นที่ 1';
        }
    }

    function showStep(n) {
        currentStep = Math.max(1, Math.min(maxStep, n));
        steps.forEach((el) => {
            el.classList.toggle('need-form-step--active', parseInt(el.getAttribute('data-step') || '0', 10) === currentStep);
        });
        tabs.forEach((tab) => {
            tab.classList.toggle('need-wizard-tabs__active', parseInt(tab.getAttribute('data-step-tab') || '0', 10) === currentStep);
        });
        if (btnPrev) btnPrev.style.display = currentStep > 1 ? '' : 'none';
        if (btnNext) btnNext.style.display = currentStep < maxStep ? '' : 'none';
        if (btnSubmit) btnSubmit.style.display = currentStep === maxStep ? '' : 'none';
        updateChecklist();
    }

    if (btnPrev) btnPrev.addEventListener('click', () => showStep(currentStep - 1));
    if (btnNext) btnNext.addEventListener('click', () => showStep(currentStep + 1));
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => showStep(parseInt(tab.getAttribute('data-step-tab') || '1', 10)));
    });

    function buildPreviewHtml() {
        const lines = [];
        slotItemEls.forEach((itemEl, idx) => {
            const catEl = slotCategoryEls[idx];
            const pEl = slotPriceEls[idx];
            const qEl = slotQtyEls[idx];
            const customEl = slotCustomEls[idx];
            if (!catEl || !catEl.value || !itemEl || !itemEl.value) return;
            let name = itemEl.value === '__other__' && customEl ? customEl.value : itemEl.value;
            const price = parseFloat((pEl && pEl.value) || '0');
            const qty = parseFloat((qEl && qEl.value) || '0');
            if (price > 0 && qty > 0) {
                lines.push('<li>' + name + ' — ' + qty + ' ชิ้น × ' + price.toLocaleString('th-TH') + ' บาท</li>');
            }
        });
        return '<p><strong>รายการที่ผู้บริจาคจะเลือกได้:</strong></p><ul>' + (lines.length ? lines.join('') : '<li>ยังไม่มีรายการครบ</li>') + '</ul>';
    }

    if (btnPreview) {
        btnPreview.addEventListener('click', () => {
            if (previewBody) previewBody.innerHTML = buildPreviewHtml();
            if (previewModal) previewModal.classList.add('need-preview-modal--open');
        });
    }
    if (btnPreviewClose && previewModal) {
        btnPreviewClose.addEventListener('click', () => previewModal.classList.remove('need-preview-modal--open'));
        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) previewModal.classList.remove('need-preview-modal--open');
        });
    }

    slotPriceEls.forEach((el) => el.addEventListener('input', updateChecklist));
    slotQtyEls.forEach((el) => el.addEventListener('input', updateChecklist));
    slotCategoryEls.forEach((el) => el.addEventListener('change', updateChecklist));
    slotItemEls.forEach((el) => el.addEventListener('change', updateChecklist));
    if (foundationNeedImageInput) foundationNeedImageInput.addEventListener('change', updateChecklist);

    if (needForm) {
        needForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const itemsOk = countValidItems() >= 1;
            const itemImgOk = HAS_EXISTING_ITEM_IMGS || selectedFiles.length >= 1;
            const fdnOk = HAS_EXISTING_FDN_IMG || selectedFoundationFile !== null;
            if (!itemsOk || !itemImgOk || !fdnOk) {
                const missing = [];
                if (!itemsOk) missing.push('รายการสิ่งของ');
                if (!itemImgOk) missing.push('รูปสิ่งของ');
                if (!fdnOk) missing.push('รูปมูลนิธิ');
                drawdreamAlert('ยังส่งไม่ได้ — กรุณาเพิ่ม: ' + missing.join(', '));
                if (!itemsOk) showStep(1);
                else if (!itemImgOk || !fdnOk) showStep(2);
                return;
            }

            if (needImagePickBusy) {
                drawdreamAlert('กำลังบีบอัดรูปอยู่ — รอสักครู่แล้วกดส่งอีกครั้ง');
                return;
            }

            if (allowAnyBrandCheckbox && desiredBrandInput) {
                if (!allowAnyBrandCheckbox.checked && desiredBrandInput.value.trim() === '') {
                    drawdreamAlert('กรุณากรอกแบรนด์ที่ต้องการ หรือเลือกว่ายอมรับแบรนด์ไหนก็ได้');
                    showStep(2);
                    desiredBrandInput.focus();
                    return;
                }
            }

            const submitBtn = btnSubmit;
            const prevLabel = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'กำลังส่ง…';
            }

            try {
                const needsCompress = selectedFiles.some((f) => f.size > MAX_NEED_IMAGE_BYTES)
                    || (selectedFoundationFile && selectedFoundationFile.size > MAX_NEED_IMAGE_BYTES);
                if (needsCompress) {
                    if (submitBtn) submitBtn.textContent = 'กำลังบีบอัดรูปก่อนส่ง…';
                    await ensureAllNeedImagesWithinLimit();
                }

                await submitNeedFormViaFetch();
            } catch (err) {
                drawdreamAlert('ส่งข้อมูลไม่สำเร็จ — ลองใหม่อีกครั้ง (รูปของคุณไม่ได้ใหญ่เกิน ขนาด ' + Math.round((selectedFoundationFile ? selectedFoundationFile.size : 0) / 1024) + ' KB)');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = prevLabel || 'ส่งรายการ';
                }
            }
        });
    }

    window.updateNeedChecklist = updateChecklist;
    showStep(NEED_WIZARD_INITIAL_STEP);
    if (NEED_WIZARD_INITIAL_STEP === 2 && desiredBrandInput) {
        window.setTimeout(function () {
            desiredBrandInput.focus();
        }, 120);
    }
    const errBanner = document.getElementById('needFormError');
    if (errBanner && errBanner.scrollIntoView) {
        errBanner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
})();
</script>
<?php require_once __DIR__ . '/includes/vendor_assets.php'; ?>
<script src="js/drawdream-swal.js?v=1"></script>
<?php echo drawdream_sweetalert2_js_tag('', true); ?>
</body>
</html>