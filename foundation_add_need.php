<?php
// foundation_add_need.php — มูลนิธิเสนอรายการสิ่งของ

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน add need

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';
require_once __DIR__ . '/includes/needlist_donate_window.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'foundation') {
    header("Location: homepage.php");
    exit();
}

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) die("ไม่พบ user_id ใน session");

// ดึง foundation_id + ชื่อมูลนิธิ (ใช้แจ้งเตือน)
$stmt = $conn->prepare("SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id=? LIMIT 1");
if (!$stmt) die("Prepare failed: " . $conn->error);
$stmt->bind_param("i", $uid);
$stmt->execute();
$fp = $stmt->get_result()->fetch_assoc();
if (!$fp) die("ยังไม่มีโปรไฟล์มูลนิธิ กรุณาสร้างก่อน");
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

$error   = "";
$success = "";

$itemCategories = [
    'อุปโภคบริโภค (ของกิน-ของใช้ประจำวัน)',
    'สุขภาพและเวชภัณฑ์พื้นฐาน',
    'เสื้อผ้าและเครื่องนุ่งห่ม',
    'อุปกรณ์ไฟฟ้าและไอที',
    'อื่นๆ ที่จำเป็นเฉพาะทาง'
];

$categoryItems = [
    'อุปโภคบริโภค (ของกิน-ของใช้ประจำวัน)' => [
        'ข้าวสาร', 'บะหมี่กึ่งสำเร็จรูป', 'ปลากระป๋อง', 'นมกล่อง UHT', 'น้ำมันพืช', 'เครื่องปรุงรส',
        'สบู่', 'ยาสีฟัน', 'แปรงสีฟัน', 'แชมพู', 'ผ้าอนามัย', 'แพมเพิส', 'กระดาษทิชชู่', 'น้ำดื่มบรรจุขวด'
    ],
    'สุขภาพและเวชภัณฑ์พื้นฐาน' => [
        'ยาพาราเซตามอล', 'ยาแก้ไอ', 'ผงเกลือแร่ ORS', 'ยาใส่แผล',
        'สำลี', 'แอลกอฮอล์ล้างแผล', 'พลาสเตอร์ปิดแผล', 'หน้ากากอนามัย',
        'รถเข็นผู้ป่วย', 'ไม้เท้า', 'แผ่นรองซับ'
    ],
    'เสื้อผ้าและเครื่องนุ่งห่ม' => [
        'เสื้อยืด', 'กางเกงขาสั้น', 'กางเกงขายาว', 'กางเกงในใหม่', 'เสื้อซับใหม่',
        'ผ้าห่ม', 'เสื้อกันหนาว', 'หมวกไหมพรม'
    ],
    'อุปกรณ์ไฟฟ้าและไอที' => [
        'คอมพิวเตอร์หรือโน้ตบุ๊ก', 'แท็บเล็ตเพื่อการเรียน', 'พัดลม', 'หม้อหุงข้าว', 'กระติกน้ำร้อน'
    ],
    /* หมวดนี้ไม่มีรายการตายตัว — ใช้ช่อง «ระบุเอง (อื่นๆ)» ด้านล่าง (สูงสุด 5 ช่อง รวมกับตามหมวดไม่เกิน 5 รายการ) */
    'อื่นๆ ที่จำเป็นเฉพาะทาง' => [],
];

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
        $cat = trim((string)($row['category'] ?? ''));
        $qty = (float)($row['qty_needed'] ?? ($row['qty'] ?? 0));
        $price = (float)($row['price_estimate'] ?? ($row['price'] ?? 0));
        if ($cat === '' || $qty <= 0 || $price <= 0) {
            continue;
        }
        $out[] = [
            'category' => $cat,
            'qty' => $qty,
            'price' => $price,
        ];
    }
    return $out;
}

if ($editRow && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $cats = drawdream_need_tokens_from_db($editRow['brand'] ?? '');
    $opts = drawdream_need_tokens_from_db($editRow['item_name'] ?? '');
    $itemRowsFromJson = drawdream_need_items_from_json($editRow['need_items_json'] ?? '');
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
        $rowPrice = (float)($rowData['price'] ?? 0);
        if ($rowQty <= 0) {
            $rowQty = $qtyFromDb;
        }
        if ($rowPrice <= 0) {
            $rowPrice = $priceFromDb;
        }
        $matchedCategory = '';
        foreach ($categoryItems as $catName => $optList) {
            if (in_array($itemName, $optList, true)) {
                $matchedCategory = $catName;
                break;
            }
        }
        if ($matchedCategory === '') {
            if ($rowCat !== '') {
                $matchedCategory = $rowCat;
            } else {
                $matchedCategory = $cats[0] ?? 'อื่นๆ ที่จำเป็นเฉพาะทาง';
            }
        }
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
            }
        }
    }

    if ($error !== '') {
        // ข้าม validation เมื่อตรวจสิทธิ์แก้ไขไม่ผ่าน
    } else {

    $lineItems = [];
    $selectedCategories = [];
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
        $selectedCategories[] = $cat;
        $itemNames[] = $itemName;
    }

    if ($error === '' && count($lineItems) < 1) {
        $error = "กรุณากรอกอย่างน้อย 1 รายการสิ่งของ";
    }
    if ($error === '' && count($lineItems) > 5) {
        $error = "กรอกรายการได้สูงสุด 5 ช่อง";
    }

    $desiredBrand = trim((string)($_POST['desired_brand'] ?? ''));
    $brand       = implode(' | ', array_values(array_unique($selectedCategories)));
    $allow_other = isset($_POST['allow_any_brand']) ? 1 : 0;
    $urgent      = isset($_POST['urgent']) ? 1 : 0;
    $note        = trim($_POST['note'] ?? '');
    $qty         = 0.0;
    $price       = 0.0;
    $item_name = implode(', ', $itemNames);
    $needItemsJson = '';
    foreach ($lineItems as $li) {
        $qty += (float)$li['qty'];
    }
    if ($qty > 0) {
        $price = $goal / $qty;
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
        $lineItemsForJson = [];
        foreach ($lineItems as $li) {
            $lineItemsForJson[] = [
                'slot' => (int)($li['slot'] ?? 0),
                'category' => (string)($li['category'] ?? ''),
                'qty_needed' => (float)($li['qty'] ?? 0),
                'price_estimate' => (float)($li['price'] ?? 0),
                'line_total' => (float)($li['line_total'] ?? 0),
            ];
        }
        $needItemsJson = json_encode($lineItemsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($needItemsJson) || $needItemsJson === '') {
            $needItemsJson = '[]';
        }
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

    // อัปโหลดรูป (ไม่บังคับ, สูงสุด 3 รูป)
    $uploadedImages = [];
    if ($error === "" && isset($_FILES['item_image']) && is_array($_FILES['item_image']['name'])) {
        $uploadDir = "uploads/needs/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
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
                    $error = "ข้อผิดพลาดการอัปโหลด (Error code: " . $errCode . ")";
                    break;
                }

                $fileSize = (int)$sizes[$idx];
                if ($fileSize > 5 * 1024 * 1024) {
                    $error = "แต่ละไฟล์ต้องไม่เกิน 5MB";
                    break;
                }

                $ext = strtolower(pathinfo((string)$imageName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $error = "อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp";
                    break;
                }

                $safeName = time() . "_" . uniqid() . "_" . $idx . "." . $ext;
                $targetPath = $uploadDir . $safeName;
                if (!move_uploaded_file((string)$tmpNames[$idx], $targetPath)) {
                    $error = "อัปโหลดรูปไม่สำเร็จ";
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
            $uploadDir = "uploads/needs/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $imgName = (string)($ff['name'] ?? '');
            $tmpF = (string)($ff['tmp_name'] ?? '');
            $sizeF = (int)($ff['size'] ?? 0);
            if ($sizeF > 5 * 1024 * 1024) {
                $error = "รูปมูลนิธิต้องไม่เกิน 5MB";
            } else {
                $ext = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $error = "รูปมูลนิธิ อนุญาตเฉพาะ jpg/jpeg/png/gif/webp";
                } elseif ($tmpF === '' || !is_uploaded_file($tmpF)) {
                    $error = "อัปโหลดรูปมูลนิธิไม่สำเร็จ";
                } else {
                    $safeF = time() . "_" . uniqid('', true) . "_fdn." . $ext;
                    if (!move_uploaded_file($tmpF, $uploadDir . $safeF)) {
                        $error = "บันทึกไฟล์รูปมูลนิธิไม่สำเร็จ";
                    } else {
                        $needFoundationImageDb = $safeF;
                    }
                }
            }
        } elseif ($errF !== UPLOAD_ERR_NO_FILE) {
            $error = "ข้อผิดพลาดอัปโหลดรูปมูลนิธิ (รหัส " . $errF . ")";
        }
    }

    } // end skip validation when $error set early

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

            $nfFinal = trim((string)($existingNeedRow['need_foundation_image'] ?? ''));
            if ($needFoundationImageDb !== '') {
                $nfFinal = $needFoundationImageDb;
            }

            $oldTotalPrice = (float)($existingNeedRow['total_price'] ?? 0);

            $sqlU = "UPDATE foundation_needlist SET
                item_name = ?, desired_brand = ?, brand = ?, allow_other_brand = ?,
                qty_needed = ?, urgent = ?,
                item_image = ?, item_image_2 = ?, item_image_3 = ?, need_foundation_image = ?,
                note = ?, total_price = ?, need_items_json = ?,
                previous_total_price = IF(? != total_price, total_price, previous_total_price)
                WHERE item_id = ? AND foundation_id = ?";
            $stmt = $conn->prepare($sqlU);

            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                // types: sss(idi)(sssss)(ds)(d)(ii) — extra d for IF(? != total_price, ...)
                $updTypes = 'sss' . 'idi' . str_repeat('s', 5) . 'ds' . 'd' . 'ii';
                $stmt->bind_param(
                    $updTypes,
                    $item_name, $desiredBrand, $brand,
                    $allow_other, $qty, $urgent,
                    $im0, $im1, $im2, $nfFinal,
                    $note, $total_price, $needItemsJson,
                    $total_price,
                    $itemIdEdit, $foundation_id
                );

                if ($stmt->execute()) {
                    $prevApproveItem = strtolower(trim((string)($existingNeedRow['approve_item'] ?? '')));
                    if ($prevApproveItem === 'rejected') {
                        $stPend = $conn->prepare(
                            "UPDATE foundation_needlist
                             SET approve_item='pending', review_note=NULL, reviewed_at=NULL, reviewed_by_user_id=NULL
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
                    if (($existingNeedRow['approve_item'] ?? '') === 'approved') {
                        require_once __DIR__ . '/includes/needlist_donate_window.php';
                        $rv = trim((string)($existingNeedRow['reviewed_at'] ?? ''));
                        try {
                            $from = ($rv !== '' && !str_starts_with($rv, '0000-00-00'))
                                ? new DateTimeImmutable($rv)
                                : new DateTimeImmutable('now');
                        } catch (Throwable $e) {
                            $from = new DateTimeImmutable('now');
                        }
                        $end = drawdream_needlist_compute_donate_window_end('', $from);
                        $eid = (int)$itemIdEdit;
                        if ($end !== null) {
                            $stE = $conn->prepare('UPDATE foundation_needlist SET donate_window_end_at = ? WHERE item_id = ? AND foundation_id = ?');
                            if ($stE) {
                                $stE->bind_param('sii', $end, $eid, $foundation_id);
                                $stE->execute();
                            }
                        }
                    }
                    header('Location: foundation.php?need_updated=1#my-needlist-section');
                    exit;
                }
                $error = "บันทึกไม่สำเร็จ: " . $stmt->error;
            }
        } else {
            $im0 = $slot0;
            $im1 = $slot1;
            $im2 = $slot2;

            $sql  = "INSERT INTO foundation_needlist 
                 (foundation_id, item_name, desired_brand, brand, allow_other_brand,
                 qty_needed, urgent, item_image, item_image_2, item_image_3, need_foundation_image, created_by_user_id, note, total_price, approve_item)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                // รองรับทั้ง schema ใหม่ (มี need_items_json) และ schema เก่าที่ยังไม่ migration
                $hasNeedItemsJson = false;
                $chkNeedJson = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'need_items_json'");
                if ($chkNeedJson && $chkNeedJson->num_rows > 0) {
                    $hasNeedItemsJson = true;
                }
                if ($hasNeedItemsJson) {
                    $sql = "INSERT INTO foundation_needlist
                        (foundation_id, item_name, desired_brand, brand, allow_other_brand,
                         qty_needed, urgent, item_image, item_image_2, item_image_3, need_foundation_image, created_by_user_id, note, total_price, need_items_json, approve_item)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        $error = "Prepare failed: " . $conn->error;
                    }
                }
            }

            if ($error === '' && $stmt) {
                if ($hasNeedItemsJson) {
                    $stmt->bind_param(
                        "isssidissssisds",
                        $foundation_id, $item_name, $desiredBrand, $brand,
                        $allow_other, $qty, $urgent, $im0, $im1, $im2, $needFoundationImageDb, $uid, $note, $total_price, $needItemsJson
                    );
                } else {
                    $stmt->bind_param(
                        "isssidissssisd",
                        $foundation_id, $item_name, $desiredBrand, $brand,
                        $allow_other, $qty, $urgent, $im0, $im1, $im2, $needFoundationImageDb, $uid, $note, $total_price
                    );
                }

                if ($stmt->execute()) {
                    $newItemId = (int)$conn->insert_id;
                    if ($newItemId > 0) {
                        require_once __DIR__ . '/includes/notification_audit.php';
                        drawdream_ensure_notifications_table($conn);
                        drawdream_record_foundation_submitted_need($conn, $uid, $newItemId, $item_name, $total_price, $foundation_display_name, $urgent === 1);
                        drawdream_notify_admins_need_submitted($conn, $newItemId, $item_name, $foundation_display_name, $total_price, $urgent === 1);
                    }
                    header('Location: foundation.php?need_created=1#my-needlist-section');
                    exit;
                } else {
                    $error = "บันทึกไม่สำเร็จ: " . $stmt->error;
                }
            }
        }
    }
}

$hiddenItemId = (int)($_POST['item_id'] ?? $editItemPg);
$isEditForm = $hiddenItemId > 0;
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css?v=29">
</head>
<body class="foundation-add-need-page">

<?php include 'navbar.php'; ?>

<div class="add-need-container">
    <p class="add-need-back"><a href="foundation.php" class="add-need-back-link">← กลับหน้ามูลนิธิ</a></p>
    <h2><?= htmlspecialchars($pageTitle) ?></h2>
    <?php if (!$isEditForm): ?>
        <div class="alert alert-success" style="background:#eef6ff;border:1px solid #cfe1ff;color:#23417c;">
            รอบรับบริจาครายการสิ่งของจะปิดอัตโนมัติเมื่อครบ 1 เดือนนับจากวันที่แอดมินอนุมัติรายการ
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?php if ($hiddenItemId > 0): ?>
        <input type="hidden" name="item_id" value="<?= (int)$hiddenItemId ?>">
        <?php endif; ?>
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
                <?php if ($thumbRow && trim((string)($thumbRow['need_foundation_image'] ?? '')) !== ''): ?>
                <div class="form-group need-current-files">
                    <label>รูปมูลนิธิปัจจุบัน</label>
                    <div class="need-current-thumbs">
                        <img src="uploads/needs/<?= htmlspecialchars($thumbRow['need_foundation_image']) ?>" alt="" class="need-current-thumb">
                    </div>
                </div>
                <?php endif; ?>

                <div class="foundation-need-images form-group foundation-need-images--prominent">
                    <div class="need-images-duo">
                        <div class="need-images-duo__col">
                            <label class="need-images-duo__label">รูปสิ่งของ <span class="need-img-optional">(ไม่บังคับ · สูงสุด 3 รูป)</span></label>
                            <p class="need-img-lead need-img-lead--compact">สินค้า / แพ็กที่ต้องการให้ผู้บริจาคเห็น</p>
                            <div class="image-upload-box">
                                <input type="file" name="item_image[]" id="fileInput" class="need-image-file-input" accept="image/*" multiple>
                                <div class="upload-label" id="uploadLabel">
                                    <div class="upload-icon">📷</div>
                                    <div>เลือกรูปได้สูงสุด 3 รูป</div>
                                    <div class="upload-hint">JPG, PNG, GIF, WEBP — ไฟล์ละไม่เกิน 5MB</div>
                                </div>
                                <div class="image-upload-toolbar">
                                    <button type="button" class="btn-need-pick-img" id="btnNeedPickImg">เลือก / เพิ่มรูป</button>
                                </div>
                            </div>
                            <div id="imagePreviewList" class="upload-preview-list"></div>
                        </div>
                        <div class="need-images-duo__col need-images-duo__col--fdn">
                            <label class="need-images-duo__label">รูปมูลนิธิ <span class="need-img-optional">(ไม่บังคับ · 1 รูป)</span></label>
                            <p class="need-img-lead need-img-lead--compact">แยกจากรูปสิ่งของ — โลโก้ ทีมงาน หรือภาพกิจกรรม</p>
                            <div class="image-upload-box image-upload-box--compact">
                                <input type="file" name="foundation_need_image" id="foundationNeedImageInput" class="need-image-file-input" accept="image/*">
                                <div class="upload-label upload-label--compact" id="foundationUploadLabel">
                                    <div class="upload-icon upload-icon--sm">🏛️</div>
                                    <div class="upload-hint">ไฟล์ละไม่เกิน 5MB</div>
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

                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="note" rows="3" placeholder="เช่น: รายละเอียดเพิ่มเติมเกี่ยวกับสิ่งของหรือการจัดส่ง"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" name="urgent" id="urgent" <?= !empty($_POST['urgent']) ? 'checked' : '' ?>>
                    <label for="urgent">ต้องการด่วน</label>
                </div>

            </div>
        </div>

        <button type="submit" name="submit" class="btn-submit"><?= $isEditForm ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูล' ?></button>
    </form>
</div>

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
const MAX_NEED_IMAGES = 3;
const MAX_NEED_IMAGE_BYTES = 5 * 1024 * 1024;
const slotCategoryEls = Array.from(document.querySelectorAll('.need-slot-category'));
const slotItemEls = Array.from(document.querySelectorAll('.need-slot-item'));
const slotPriceEls = Array.from(document.querySelectorAll('.need-slot-price'));
const slotQtyEls = Array.from(document.querySelectorAll('.need-slot-qty'));
const slotCustomWrapEls = Array.from(document.querySelectorAll('.need-slot-custom-wrap'));
const slotCustomEls = Array.from(document.querySelectorAll('.need-slot-custom'));
const categoryItemsMap = <?= json_encode($categoryItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
/** @type {File[]} */
let selectedFiles = [];

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
            <div class="upload-hint">รองรับ JPG, PNG, GIF, WEBP (ไฟล์ละไม่เกิน 5MB) · กด «เพิ่มรูป» เพื่อเลือกต่อโดยไม่ล้างรูปเดิม</div>`;
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
    if (!arr.length) return;

    const room = MAX_NEED_IMAGES - selectedFiles.length;
    if (room <= 0) {
        alert('อัปโหลดได้สูงสุด 3 รูป\nกด «นำออก» บนรูปเพื่อลบแล้วเลือกใหม่');
        fileInput.value = '';
        return;
    }

    const existing = new Set(selectedFiles.map(needImageSignature));
    const toPush = [];
    let skipped = 0;

    for (const f of arr) {
        if (toPush.length >= room) {
            break;
        }
        if (!f.type.startsWith('image/')) {
            alert('ข้ามไฟล์ที่ไม่ใช่รูป: ' + f.name);
            skipped++;
            continue;
        }
        if (f.size > MAX_NEED_IMAGE_BYTES) {
            alert('ไฟล์เกิน 5MB: ' + f.name);
            skipped++;
            continue;
        }
        const sig = needImageSignature(f);
        if (existing.has(sig)) {
            skipped++;
            continue;
        }
        existing.add(sig);
        toPush.push(f);
    }

    if (arr.length > room) {
        alert('เลือกครั้งนี้มีมากกว่าที่เหลือ — เพิ่มได้อีกสูงสุด ' + room + ' รูป (รวมไม่เกิน 3 รูป)');
    } else if (skipped > 0 && toPush.length === 0) {
        alert('ไม่มีไฟล์ที่เพิ่มได้ (ซ้ำ ชนิดไฟล์ หรือเกินขนาด)');
    }

    selectedFiles = selectedFiles.concat(toPush);
    syncNeedFileInput();
    updateNeedUploadChrome();
    renderPreviews();
    /* ห้าม fileInput.value = '' — จะล้างรายการไฟล์ก่อน submit ทำให้ item_image[] ไม่ถูกส่งขึ้นเซิร์ฟเวอร์ */
}

function removeNeedFileAt(index) {
    if (index < 0 || index >= selectedFiles.length) return;
    selectedFiles.splice(index, 1);
    syncNeedFileInput();
    updateNeedUploadChrome();
    renderPreviews();
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

function clearFoundationNeedPreview() {
    if (foundationNeedPreview) {
        foundationNeedPreview.innerHTML = '';
    }
}

if (btnFoundationNeedImg && foundationNeedImageInput) {
    btnFoundationNeedImg.addEventListener('click', () => foundationNeedImageInput.click());
}

if (foundationNeedImageInput) {
    foundationNeedImageInput.addEventListener('change', function() {
        clearFoundationNeedPreview();
        const f = this.files && this.files[0];
        if (!f) {
            return;
        }
        if (!f.type.startsWith('image/')) {
            alert('กรุณาเลือกไฟล์รูปเท่านั้น');
            this.value = '';
            return;
        }
        if (f.size > MAX_NEED_IMAGE_BYTES) {
            alert('รูปมูลนิธิต้องไม่เกิน 5MB');
            this.value = '';
            return;
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
            foundationNeedImageInput.value = '';
            clearFoundationNeedPreview();
        });
        const reader = new FileReader();
        reader.onload = (evt) => { img.src = evt.target.result; };
        reader.readAsDataURL(f);
        wrap.appendChild(img);
        wrap.appendChild(rm);
        foundationNeedPreview.appendChild(wrap);
    });
}

urgentCheckbox.addEventListener('change', renderPreviews);

const needForm = document.querySelector('form[method="post"][enctype="multipart/form-data"]');
if (needForm && fileInput) {
    needForm.addEventListener('submit', function() {
        if (selectedFiles.length) syncNeedFileInput();
    });
}

updateNeedUploadChrome();
updateTotal();
syncDesiredBrandState();
</script>

</body>
</html>