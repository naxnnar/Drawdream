<?php
// payment/foundation_donate.php — บริจาคมูลนิธิ (need list) + Omise
// สรุปสั้น: หน้าเริ่มบริจาคมูลนิธิ สร้าง charge และเตรียมรายการ pending ก่อนแสดง QR
include '../db.php';
include 'config.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/needlist_donate_window.php';
require_once __DIR__ . '/../includes/donate_category_resolve.php';
require_once __DIR__ . '/../includes/drawdream_needlist_payment_finalize.php';

if (!isset($_SESSION['user_id'])) {
    $msg = rawurlencode('กรุณาเข้าสู่ระบบก่อนจึงจะบริจาคได้');
    header("Location: ../login.php?page=login&error={$msg}");
    exit();
}
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'])) { header("Location: ../foundation.php"); exit(); }

$fid = (int)($_GET['fid'] ?? 0);
if ($fid <= 0) { header("Location: ../foundation.php"); exit(); }

$stmt = $conn->prepare("SELECT * FROM foundation_profile WHERE foundation_id = ? LIMIT 1");
$stmt->bind_param("i", $fid);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();
if (!$foundation) { header("Location: ../foundation.php"); exit(); }

$needOpen = drawdream_needlist_sql_open_for_donation();
$stmt2 = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS goal FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
$stmt2->bind_param("i", $fid);
$stmt2->execute();
$goal = (float)($stmt2->get_result()->fetch_assoc()['goal'] ?? 0);

$stmt3 = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS current FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
$stmt3->bind_param("i", $fid);
$stmt3->execute();
$current = (float)($stmt3->get_result()->fetch_assoc()['current'] ?? 0);

$percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
$percentRounded = (int)round($percent);
$remainingNeed = ($goal > 0) ? max(0.0, $goal - $current) : 0.0;
$maxDonatePerChargeBaht = ($goal > 0) ? (int)max(0, (int)floor($remainingNeed + 1e-9)) : 0;
$error = "";
$qr_image = "";
$charge_id = "";

if ($goal > 0 && $current >= $goal) {
    header('Location: ../needlist_result.php?fid=' . $fid);
    exit();
}
if ($goal > 0 && $remainingNeed > 0 && $remainingNeed < 20) {
    $error = "ยอดที่เหลือจะครบเป้าหมายไม่ถึงขั้นต่ำการบริจาค 20 บาท — ไม่สามารถบริจาคเพิ่มได้";
}

$items_stmt = $conn->prepare("SELECT * FROM foundation_needlist WHERE foundation_id = ? AND $needOpen ORDER BY urgent DESC, item_id DESC");
$items_stmt->bind_param("i", $fid);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$fdCoverNeedImage = '';
foreach ($items as $itCov) {
    $nfCov = foundation_needlist_normalize_filename((string)($itCov['need_foundation_image'] ?? ''));
    if ($nfCov !== '') {
        $fdCoverNeedImage = $nfCov;
        break;
    }
}

$donateDisabled = ($goal <= 0 || count($items) === 0 || ($goal > 0 && $remainingNeed > 0 && $remainingNeed < 20));

/**
 * @return array<int,array{item_name:string,qty_needed:float,price_estimate:float,line_total:float}>
 */
function drawdream_need_item_lines_from_row(array $item): array
{
    $lines = foundation_needlist_admin_line_items_from_row($item);
    if ($lines === []) {
        $lines = foundation_needlist_line_items_from_row($item, false);
    }
    if ($lines !== []) {
        $out = [];
        foreach ($lines as $li) {
            $out[] = [
                'item_name' => (string)($li['item_name'] ?? ''),
                'qty_needed' => (float)($li['qty'] ?? 0),
                'price_estimate' => (float)($li['price'] ?? 0),
                'line_total' => drawdream_needlist_round_money((float)($li['line_total'] ?? 0)),
            ];
        }
        return $out;
    }

    $raw = trim((string)($item['need_items_json'] ?? ''));
    $rawPricing = trim((string)($item['need_items_pricing_json'] ?? ''));
    $lines = [];
    $nameTokens = [];
    $rawNames = trim((string)($item['item_name'] ?? ''));
    if ($rawNames !== '') {
        $parts = preg_split('/\s*(?:,|\||\R)\s*/u', $rawNames);
        if (is_array($parts)) {
            foreach ($parts as $p) {
                $t = trim((string)$p);
                if ($t !== '') {
                    $nameTokens[] = $t;
                }
            }
        }
    }
    $pricingByOrder = [];
    if ($rawPricing !== '') {
        $pricingDecoded = json_decode($rawPricing, true);
        if (is_array($pricingDecoded)) {
            foreach ($pricingDecoded as $idxP => $prow) {
                if (!is_array($prow)) continue;
                $ord = (int)($prow['ลำดับ'] ?? ($idxP + 1));
                $pricingByOrder[$ord] = [
                    'price' => (float)($prow['ราคาต่อชิ้น'] ?? ($prow['price_estimate'] ?? ($prow['price'] ?? 0))),
                    'sum' => (float)($prow['ราคารวม'] ?? ($prow['line_total'] ?? 0)),
                ];
            }
        }
    }
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $qty = (float)($row['จำนวนสิ่งของ'] ?? ($row['qty_needed'] ?? ($row['qty'] ?? 0)));
                $slot = (int)($row['ลำดับ'] ?? ($row['slot'] ?? ($idx + 1)));
                $pricing = $pricingByOrder[$slot] ?? [];
                $price = drawdream_needlist_round_money((float)($pricing['price'] ?? ($row['ราคาต่อชิ้น'] ?? ($row['price_estimate'] ?? ($row['price'] ?? 0)))));
                $lineTotal = drawdream_needlist_round_money((float)($pricing['sum'] ?? ($row['ราคารวม'] ?? ($row['line_total'] ?? 0))));
                if ($lineTotal <= 0 && $qty > 0 && $price > 0) {
                    $lineTotal = drawdream_needlist_round_money($qty * $price);
                }
                if ($qty <= 0 || $price <= 0) {
                    continue;
                }
                $name = trim((string)($row['ชื่อสิ่งของ'] ?? ($row['item_name'] ?? '')));
                if ($name === '') {
                    $name = $nameTokens[$idx] ?? ('รายการที่ ' . ((int)$idx + 1));
                }
                $lines[] = [
                    'item_name' => $name,
                    'qty_needed' => $qty,
                    'price_estimate' => $price,
                    'line_total' => $lineTotal,
                ];
            }
        }
    }
    if ($lines !== []) {
        $hasPrice = false;
        $qtySum = 0.0;
        foreach ($lines as $r) {
            if ((float)($r['price_estimate'] ?? 0) > 0) {
                $hasPrice = true;
            }
            $qtySum += max(0.0, (float)($r['qty_needed'] ?? 0));
        }
        if (!$hasPrice) {
            $fallbackTotal = (float)($item['total_price'] ?? 0);
            $fallbackUnit = ($qtySum > 0 && $fallbackTotal > 0)
                ? drawdream_needlist_round_money($fallbackTotal / $qtySum)
                : 0.0;
            if ($fallbackUnit > 0) {
                foreach ($lines as $i => $r) {
                    $q = (float)($r['qty_needed'] ?? 0);
                    $lines[$i]['price_estimate'] = $fallbackUnit;
                    $lines[$i]['line_total'] = drawdream_needlist_round_money($q * $fallbackUnit);
                }
            }
        }
        return $lines;
    }
    $fallbackQty = (float)($item['qty_needed'] ?? 0);
    $fallbackTotal = (float)($item['total_price'] ?? 0);
    $fallbackPrice = $fallbackQty > 0 ? drawdream_needlist_round_money($fallbackTotal / $fallbackQty) : 0.0;
    if ($fallbackQty > 0 && $fallbackPrice > 0) {
        return [[
            'item_name' => trim((string)($item['item_name'] ?? '')) !== '' ? (string)$item['item_name'] : 'รายการสิ่งของ',
            'qty_needed' => $fallbackQty,
            'price_estimate' => $fallbackPrice,
            'line_total' => $fallbackTotal,
        ]];
    }
    return [];
}

/**
 * สร้างรายการสิ่งของรวมสำหรับคำนวณตัวอย่างการจัดสรรเงินบริจาค
 * qty_remaining = จำนวนชิ้นที่ยังขาด (อิงยอด current_donate แบ่งตามสัดส่วนราคารายการ)
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}>
 */
function drawdream_build_need_catalog(array $items): array
{
    $catalog = [];
    $order = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemCurrent = max(0.0, (float)($item['current_donate'] ?? 0));
        $itemTotal = max(0.0, (float)($item['total_price'] ?? 0));
        $lines = drawdream_need_item_lines_from_row($item);

        $linesSum = 0.0;
        foreach ($lines as $line) {
            $lineTotal = (float)($line['line_total'] ?? 0);
            if ($lineTotal <= 0) {
                $q = (float)($line['qty_needed'] ?? 0);
                $p = (float)($line['price_estimate'] ?? 0);
                $lineTotal = function_exists('drawdream_needlist_round_money')
                    ? drawdream_needlist_round_money($q * $p)
                    : round($q * $p, 2);
            }
            $linesSum += $lineTotal;
        }
        if ($linesSum <= 0 && $itemTotal > 0) {
            $linesSum = $itemTotal;
        }

        foreach ($lines as $line) {
            $name = trim((string)($line['item_name'] ?? ''));
            $qty = (float)($line['qty_needed'] ?? 0);
            $price = (float)($line['price_estimate'] ?? 0);
            if ($name === '' || $qty <= 0 || $price <= 0) {
                continue;
            }
            $lineTotal = (float)($line['line_total'] ?? 0);
            if ($lineTotal <= 0) {
                $lineTotal = function_exists('drawdream_needlist_round_money')
                    ? drawdream_needlist_round_money($qty * $price)
                    : round($qty * $price, 2);
            }
            $lineRaised = 0.0;
            if ($itemCurrent > 0 && $linesSum > 0) {
                $lineRaised = $itemCurrent * ($lineTotal / $linesSum);
            }

            $key = mb_strtolower($name, 'UTF-8') . '|' . number_format($price, 2, '.', '');
            if (!isset($catalog[$key])) {
                $catalog[$key] = [
                    'catalog_key' => $key,
                    'name' => $name,
                    'qty_needed' => 0.0,
                    'goal_baht' => 0.0,
                    'raised_baht' => 0.0,
                    'price' => $price,
                    '_order' => $order++,
                ];
            }
            $catalog[$key]['qty_needed'] += $qty;
            $catalog[$key]['goal_baht'] += $lineTotal;
            $catalog[$key]['raised_baht'] += $lineRaised;
        }
    }

    usort($catalog, static function (array $a, array $b): int {
        $ordA = (int)($a['_order'] ?? 0);
        $ordB = (int)($b['_order'] ?? 0);
        return $ordA <=> $ordB;
    });

    return array_map(static function (array $row): array {
        $price = (float)$row['price'];
        $goalBaht = (float)$row['goal_baht'];
        $raisedBaht = min($goalBaht, max(0.0, (float)$row['raised_baht']));
        $remainingBaht = max(0.0, $goalBaht - $raisedBaht);
        $qtyOrig = (float)$row['qty_needed'];
        $qtyRemaining = 0.0;
        if ($price > 0) {
            $qtyRemaining = min($qtyOrig, floor($remainingBaht / $price + 1e-9));
        }

        return [
            'catalog_key' => (string)$row['catalog_key'],
            'name' => (string)$row['name'],
            'qty_needed' => $qtyOrig,
            'qty_remaining' => max(0.0, $qtyRemaining),
            'price' => $price,
        ];
    }, $catalog);
}

/** @return array<string, int> catalog_key => qty */
function drawdream_parse_need_item_picks_from_post(array $catalog): array
{
    $raw = $_POST['pick_qty'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $byKey = [];
    foreach ($catalog as $row) {
        $byKey[(string)($row['catalog_key'] ?? '')] = $row;
    }
    $picks = [];
    foreach ($raw as $key => $qtyRaw) {
        $key = (string)$key;
        if ($key === '' || !isset($byKey[$key])) {
            continue;
        }
        $maxQty = (int)max(0, (int)floor((float)($byKey[$key]['qty_remaining'] ?? ($byKey[$key]['qty_needed'] ?? 0))));
        $qty = (int)max(0, (int)$qtyRaw);
        if ($maxQty > 0) {
            $qty = min($qty, $maxQty);
        }
        if ($qty > 0) {
            $picks[$key] = $qty;
        }
    }

    return $picks;
}

/** @param array<string, int> $picks */
function drawdream_need_pick_total_baht(array $catalog, array $picks): float
{
    $byKey = [];
    foreach ($catalog as $row) {
        $byKey[(string)($row['catalog_key'] ?? '')] = $row;
    }
    $total = 0.0;
    foreach ($picks as $key => $qty) {
        if (!isset($byKey[$key])) {
            continue;
        }
        $price = (float)($byKey[$key]['price'] ?? 0);
        if ($price <= 0 || $qty <= 0) {
            continue;
        }
        $total += round($price * $qty, 2);
    }

    return round($total, 2);
}

$needCatalog = drawdream_build_need_catalog($items);
$needCatalogJson = json_encode($needCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($needCatalogJson)) {
    $needCatalogJson = '[]';
}

$donateAllCatalogBaht = 0;
$donateAllHasItems = false;
foreach ($needCatalog as $catRow) {
    $qRem = (int)max(0, (int)floor((float)($catRow['qty_remaining'] ?? 0)));
    $pRem = (float)($catRow['price'] ?? 0);
    if ($qRem > 0 && $pRem > 0) {
        $donateAllHasItems = true;
        $donateAllCatalogBaht += (int)round($qRem * $pRem);
    }
}
$donateAllBaht = $donateAllCatalogBaht;
if ($maxDonatePerChargeBaht > 0) {
    $donateAllBaht = min($maxDonatePerChargeBaht, $donateAllCatalogBaht);
}
$donateAllEnabled = $donateAllHasItems && $donateAllBaht >= 20 && !$donateDisabled;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    drawdream_csrf_require_valid('../foundation.php');
    $picks = drawdream_parse_need_item_picks_from_post($needCatalog);
    $amount = (int)round(drawdream_need_pick_total_baht($needCatalog, $picks));
    if ($goal <= 0 || count($items) === 0) {
        $error = "ขณะนี้ไม่มีรายการสิ่งของที่เปิดรับบริจาค (ครบระยะเวลาหรือปิดรับแล้ว)";
    } elseif ($picks === []) {
        $error = 'กรุณาเลือกสิ่งของอย่างน้อย 1 รายการ';
    } elseif ($amount < 20) {
        $error = 'ยอดรวมจากสิ่งของที่เลือกต้องไม่ต่ำกว่า 20 บาท';
    } else {
        $stFreshGoal = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS goal FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
        $stFreshCur = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS current FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
        $gFresh = 0.0;
        $rFresh = 0.0;
        if ($stFreshGoal && $stFreshCur) {
            $stFreshGoal->bind_param("i", $fid);
            $stFreshGoal->execute();
            $gFresh = (float)($stFreshGoal->get_result()->fetch_assoc()['goal'] ?? 0);
            $stFreshCur->bind_param("i", $fid);
            $stFreshCur->execute();
            $rFresh = (float)($stFreshCur->get_result()->fetch_assoc()['current'] ?? 0);
        }
        $remFresh = ($gFresh > 0) ? max(0.0, $gFresh - $rFresh) : 0.0;
        if ($gFresh > 0) {
            if ($remFresh <= 0) {
                $error = 'รายการสิ่งของนี้ระดมครบตามเป้าหมายแล้ว ไม่สามารถบริจาคเพิ่มได้';
            } elseif ($amount > $remFresh + 1e-6) {
                $error = 'จำนวนบริจาคต้องไม่เกินยอดที่เหลือจะครบเป้าหมาย (' . number_format($remFresh, 0, '.', ',') . ' บาท)';
            } elseif ($remFresh < 20) {
                $error = 'ยอดที่เหลือจะครบเป้าหมายไม่ถึงขั้นต่ำการบริจาค 20 บาท — ไม่สามารถบริจาคเพิ่มได้';
            }
        }
    }

    if ($error === '') {
        drawdream_clear_pending_payment_session();
        $amount_satang = $amount * 100;
        $source_response = omise_request('POST', '/sources', ['type' => 'promptpay', 'amount' => $amount_satang, 'currency' => 'THB']);
        if (isset($source_response['error'])) {
            $error = "เกิดข้อผิดพลาด: " . $source_response['message'];
        } elseif (isset($source_response['object']) && $source_response['object'] === 'source') {
            $charge_response = omise_request('POST', '/charges', [
                'amount' => $amount_satang, 'currency' => 'THB',
                'source' => $source_response['id'],
                'description' => 'บริจาครายการสิ่งของ: ' . $foundation['foundation_name'],
                'metadata' => ['foundation_id' => $fid, 'donor_id' => $_SESSION['user_id'], 'type' => 'needlist'],
            ]);
            if (isset($charge_response['error'])) {
                $error = "เกิดข้อผิดพลาดในการสร้าง QR Code: " . $charge_response['message'];
            } elseif (isset($charge_response['id'])) {
                $charge_id = $charge_response['id'];
                $qr_image  = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';
                $pendingDonateId = drawdream_insert_pending_needlist_donation(
                    $conn,
                    $fid,
                    (int)$_SESSION['user_id'],
                    (float)$amount,
                    $charge_id
                );
                if ($pendingDonateId <= 0) {
                    $error = 'ไม่สามารถบันทึกรายการบริจาคชั่วคราวได้ กรุณาลองใหม่';
                } else {
                    $_SESSION['pending_charge_id']    = $charge_id;
                    $_SESSION['pending_amount']        = $amount;
                    $_SESSION['pending_foundation']    = $foundation['foundation_name'];
                    $_SESSION['pending_foundation_id'] = $fid;
                    $_SESSION['pending_donate_id']     = $pendingDonateId;
                    $_SESSION['qr_image']              = $qr_image;
                    header('Location: scan_qr.php?type=foundation&charge_id=' . rawurlencode($charge_id) . '&fid=' . $fid);
                    exit();
                }
            } else { $error = "เกิดข้อผิดพลาดที่ไม่คาดคิด"; }
        } else { $error = "ไม่สามารถสร้าง PromptPay Source ได้: " . ($source_response['message'] ?? 'unknown error'); }
    }
}

function omise_request($method, $path, $data = []) {
    $ch = curl_init(OMISE_API_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
    $response = curl_exec($ch); $curl_error = curl_error($ch);
    if ($response === false || $response === '') {
        if (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0) return _omise_local_mock($path, $data);
        return ['error' => 'curl_error', 'message' => $curl_error];
    }
    $decoded = json_decode($response, true);
    return $decoded ?? ['error' => 'json_error', 'message' => 'Invalid JSON'];
}

function _omise_local_mock(string $path, array $data): array {
    if (strpos($path, '/sources') !== false) return ['object' => 'source', 'id' => 'src_mock_' . bin2hex(random_bytes(6)), 'type' => 'promptpay'];
    if (strpos($path, '/charges') !== false) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="420" viewBox="0 0 320 420">'
            . '<rect width="320" height="420" fill="#ffffff"/>'
            . '<rect x="40" y="22" width="240" height="60" rx="6" fill="#1f4f7c"/>'
            . '<text x="160" y="56" font-size="20" text-anchor="middle" font-family="Prompt,Arial,sans-serif" fill="#fff" font-weight="700">THAI QR</text>'
            . '<text x="160" y="76" font-size="14" text-anchor="middle" font-family="Prompt,Arial,sans-serif" fill="#d8e8f7">PROMPTPAY</text>'
            . '<rect x="36" y="96" width="248" height="248" rx="8" fill="#fff" stroke="#d7dce4"/>'
            . '<rect x="52" y="112" width="56" height="56" fill="#000"/><rect x="59" y="119" width="42" height="42" fill="#fff"/><rect x="66" y="126" width="28" height="28" fill="#000"/>'
            . '<rect x="212" y="112" width="56" height="56" fill="#000"/><rect x="219" y="119" width="42" height="42" fill="#fff"/><rect x="226" y="126" width="28" height="28" fill="#000"/>'
            . '<rect x="52" y="272" width="56" height="56" fill="#000"/><rect x="59" y="279" width="42" height="42" fill="#fff"/><rect x="66" y="286" width="28" height="28" fill="#000"/>'
            . '<g fill="#000">'
            . '<rect x="132" y="118" width="8" height="8"/><rect x="148" y="118" width="8" height="8"/><rect x="164" y="118" width="8" height="8"/><rect x="180" y="118" width="8" height="8"/>'
            . '<rect x="124" y="134" width="8" height="8"/><rect x="140" y="134" width="8" height="8"/><rect x="156" y="134" width="8" height="8"/><rect x="172" y="134" width="8" height="8"/><rect x="188" y="134" width="8" height="8"/>'
            . '<rect x="124" y="150" width="8" height="8"/><rect x="140" y="150" width="8" height="8"/><rect x="164" y="150" width="8" height="8"/><rect x="188" y="150" width="8" height="8"/>'
            . '<rect x="116" y="166" width="8" height="8"/><rect x="132" y="166" width="8" height="8"/><rect x="148" y="166" width="8" height="8"/><rect x="164" y="166" width="8" height="8"/><rect x="180" y="166" width="8" height="8"/><rect x="196" y="166" width="8" height="8"/>'
            . '<rect x="116" y="182" width="8" height="8"/><rect x="132" y="182" width="8" height="8"/><rect x="156" y="182" width="8" height="8"/><rect x="172" y="182" width="8" height="8"/><rect x="196" y="182" width="8" height="8"/>'
            . '<rect x="116" y="198" width="8" height="8"/><rect x="140" y="198" width="8" height="8"/><rect x="156" y="198" width="8" height="8"/><rect x="180" y="198" width="8" height="8"/><rect x="196" y="198" width="8" height="8"/>'
            . '<rect x="116" y="214" width="8" height="8"/><rect x="132" y="214" width="8" height="8"/><rect x="148" y="214" width="8" height="8"/><rect x="164" y="214" width="8" height="8"/><rect x="180" y="214" width="8" height="8"/><rect x="196" y="214" width="8" height="8"/>'
            . '<rect x="124" y="230" width="8" height="8"/><rect x="140" y="230" width="8" height="8"/><rect x="156" y="230" width="8" height="8"/><rect x="172" y="230" width="8" height="8"/><rect x="188" y="230" width="8" height="8"/>'
            . '<rect x="124" y="246" width="8" height="8"/><rect x="140" y="246" width="8" height="8"/><rect x="164" y="246" width="8" height="8"/><rect x="180" y="246" width="8" height="8"/>'
            . '<rect x="132" y="262" width="8" height="8"/><rect x="148" y="262" width="8" height="8"/><rect x="164" y="262" width="8" height="8"/><rect x="180" y="262" width="8" height="8"/>'
            . '<rect x="124" y="278" width="8" height="8"/><rect x="140" y="278" width="8" height="8"/><rect x="156" y="278" width="8" height="8"/><rect x="172" y="278" width="8" height="8"/><rect x="188" y="278" width="8" height="8"/>'
            . '<rect x="124" y="294" width="8" height="8"/><rect x="148" y="294" width="8" height="8"/><rect x="172" y="294" width="8" height="8"/><rect x="188" y="294" width="8" height="8"/>'
            . '<rect x="124" y="310" width="8" height="8"/><rect x="140" y="310" width="8" height="8"/><rect x="156" y="310" width="8" height="8"/><rect x="172" y="310" width="8" height="8"/><rect x="188" y="310" width="8" height="8"/>'
            . '</g>'
            . '</svg>';
        return ['object'=>'charge','id'=>'chrg_mock_'.bin2hex(random_bytes(8)),'status'=>'pending','paid'=>false,'amount'=>$data['amount']??0,'currency'=>'THB','source'=>['type'=>'promptpay','scannable_code'=>['image'=>['download_uri'=>'data:image/svg+xml;base64,'.base64_encode($svg)]]]];
    }
    return ['error' => 'mock_unknown', 'message' => 'Mock: unknown API path'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>บริจาคเงินเพื่อสมทบทุนจัดซื้อสิ่งของ | DrawDream</title>
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
    <link rel="stylesheet" href="../css/foundation.css?v=50">
</head>
<body class="foundation-donate-page">

<?php include '../navbar.php'; ?>

<div class="fd-wrapper">
    <div class="fd-layout">

        <!-- ==================== ฝั่งซ้าย ==================== -->
        <div class="fd-left">
            <?php if ($fdCoverNeedImage !== ''): ?>
                <img src="../uploads/needs/<?= htmlspecialchars($fdCoverNeedImage) ?>"
                     class="fd-cover" alt="ภาพประกอบรายการสิ่งของ">
            <?php elseif (!empty($foundation['foundation_image'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($foundation['foundation_image']) ?>"
                     class="fd-cover" alt="">
            <?php endif; ?>

            <div class="fd-left-main">
            <h2 class="fd-name">
                <a class="fd-name-link" href="../foundation_public_profile.php?id=<?= (int)$fid ?>">
                    <?= htmlspecialchars($foundation['foundation_name']) ?>
                </a>
            </h2>
            <?php if ($goal <= 0 || empty($items)): ?>
                <div class="fd-alert fd-alert-error" role="status">ขณะนี้ไม่มีรายการสิ่งของที่เปิดรับบริจาค (ครบระยะเวลาหรือยังไม่มีรายการที่อนุมัติ)</div>
            <?php endif; ?>
            <?php if (!empty($foundation['foundation_desc'])): ?>
                <p class="fd-foundation-desc"><?= nl2br(htmlspecialchars($foundation['foundation_desc'])) ?></p>
            <?php endif; ?>

            <div class="fd-progress">
                <div class="fd-progress-remaining">
                    เหลืออีก <strong><?= number_format($remainingNeed, 0) ?> บาท</strong> จะครบเป้าหมาย
                </div>
                <div class="fd-bar">
                    <div style="width:<?= (int)$percent ?>%;min-width:<?= $percent > 0 ? '6px' : '0' ?>;"></div>
                </div>
                <div class="fd-progress-text">
                    ยอดบริจาค <strong><?= number_format($current, 0) ?></strong> / <?= number_format($goal, 0) ?> บาท
                </div>
            </div>

            </div><!-- /.fd-left-main -->
        </div><!-- /.fd-left -->

        <!-- ==================== ฝั่งขวา ==================== -->
        <div class="fd-right">
            <?php if ($error): ?>
                <div class="fd-alert fd-alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

                <h3 class="fd-donate-section-title">เลือกสิ่งของที่ต้องการสมทบทุนจัดซื้อ</h3>
                <p class="fd-form-sub">เลือกได้หลายรายการ — ระบบคำนวณยอดจากราคาต่อชิ้น × จำนวนที่เลือก</p>
                <?php if ($goal > 0 && $maxDonatePerChargeBaht > 0): ?>
                <p class="project-donate-cap-hint fd-cap-hint">
                    บริจาคได้สูงสุดครั้งละไม่เกิน <?= number_format($maxDonatePerChargeBaht, 0, '.', ',') ?> บาท (ยอดที่เหลือจะครบเป้าหมาย)
                </p>
                <input type="hidden" id="maxDonateBaht" value="<?= (int)$maxDonatePerChargeBaht ?>">
                <?php endif; ?>

                <form method="POST" id="foundationDonateForm"<?= $donateDisabled ? ' class="fd-form-disabled"' : '' ?>>
                    <?= drawdream_csrf_field() ?>
                    <?php if ($needCatalog === []): ?>
                        <p class="fd-picker-empty">ยังไม่มีรายการสิ่งของให้เลือก</p>
                    <?php else: ?>
                    <?php if ($donateAllEnabled): ?>
                    <div class="fd-donate-all-wrap">
                        <?php
                        $donateAllLabelOff = 'บริจาคครบตามที่เหลือ (' . number_format($donateAllBaht, 0) . ' บาท)';
                        $donateAllLabelOn = 'ยกเลิกการเลือกครบ';
                        ?>
                        <button
                            type="button"
                            id="fdDonateAllBtn"
                            class="fd-donate-all-btn"
                            data-target-baht="<?= (int)$donateAllBaht ?>"
                            data-label-off="<?= htmlspecialchars($donateAllLabelOff, ENT_QUOTES, 'UTF-8') ?>"
                            data-label-on="<?= htmlspecialchars($donateAllLabelOn, ENT_QUOTES, 'UTF-8') ?>"
                            aria-pressed="false"
                        >
                            <?= htmlspecialchars($donateAllLabelOff, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <p class="fd-donate-all-hint">
                            เลือกจำนวนชิ้นที่เหลือทุกรายการให้อัตโนมัติ
                            <?php if ($maxDonatePerChargeBaht > 0 && $donateAllCatalogBaht > $maxDonatePerChargeBaht): ?>
                                — ปรับให้ไม่เกินยอดที่เหลือจะครบเป้าหมาย (<?= number_format($maxDonatePerChargeBaht, 0) ?> บาท)
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <div class="fd-item-picker-list" id="fdItemPickerList">
                        <?php foreach ($needCatalog as $catRow):
                            $cKey = (string)($catRow['catalog_key'] ?? '');
                            $cName = (string)($catRow['name'] ?? '');
                            $cPrice = (float)($catRow['price'] ?? 0);
                            $cMaxQty = (int)max(0, (int)floor((float)($catRow['qty_remaining'] ?? ($catRow['qty_needed'] ?? 0))));
                            if ($cKey === '' || $cName === '' || $cPrice <= 0 || $cMaxQty <= 0) {
                                continue;
                            }
                        ?>
                        <div class="fd-picker-row" data-price="<?= htmlspecialchars(number_format($cPrice, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" data-max-qty="<?= $cMaxQty ?>">
                            <div class="fd-picker-info">
                                <span class="fd-picker-name"><?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="fd-picker-meta"><?= number_format($cPrice, 0) ?> บาท/ชิ้น · เหลืออีก <?= number_format($cMaxQty, 0) ?> ชิ้น</span>
                            </div>
                            <div class="fd-picker-qty" role="group" aria-label="จำนวน <?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button" class="fd-qty-btn fd-qty-minus" aria-label="ลดจำนวน">−</button>
                                <input
                                    type="number"
                                    class="fd-qty-input"
                                    name="pick_qty[<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>]"
                                    min="0"
                                    max="<?= $cMaxQty ?>"
                                    step="1"
                                    value="0"
                                    inputmode="numeric"
                                    aria-label="จำนวนชิ้น"
                                >
                                <button type="button" class="fd-qty-btn fd-qty-plus" aria-label="เพิ่มจำนวน">+</button>
                            </div>
                            <div class="fd-picker-line-total" aria-live="polite">0 บาท</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="fd-picker-summary" id="fdPickerSummary" aria-live="polite">
                        <div class="fd-picker-summary-head">
                            <span class="fd-picker-summary-label">ยอดรวม</span>
                            <strong class="fd-picker-summary-amt" id="fdPickTotal">0</strong>
                            <span class="fd-picker-summary-unit">บาท</span>
                        </div>
                        <ul class="fd-picker-summary-list" id="fdPickSummaryList"></ul>
                        <p class="fd-picker-summary-hint" id="fdPickSummaryHint">เลือกสิ่งของอย่างน้อย 1 รายการ (ขั้นต่ำรวม 20 บาท)</p>
                    </div>
                    <input type="hidden" name="amount" id="amountInput" value="0">

                    <div class="payment-method">
                        <div class="method-card active">
                            <img src="../img/qr-code.png" alt="PromptPay" class="method-icon">
                            <span>PromptPay QR</span>
                        </div>
                    </div>
                    <button type="submit" name="pay" class="btn-pay" id="fdDonateSubmitBtn"<?= $donateDisabled ? ' disabled data-force-disabled="1"' : '' ?>>บริจาค</button>
                </form>
        </div><!-- /.fd-right -->

    </div><!-- /.fd-layout -->
</div><!-- /.fd-wrapper -->

<script>
function fdGetMaxDonateBaht() {
    var el = document.getElementById('maxDonateBaht');
    if (!el || el.value === '') return null;
    var m = parseInt(el.value, 10);
    return (isNaN(m) || m <= 0) ? null : m;
}
/** จัดสรรจำนวนชิ้นให้รวมยอดใกล้ targetBaht ที่สุด (ไม่เกิน max ต่อรายการ) */
function fdAllocateDonateAllQuantities(rows, targetBaht) {
    if (!rows.length || targetBaht <= 0) {
        return;
    }
    var lineMaxBaht = rows.map(function (r) {
        return r.maxQty * r.price;
    });
    var sumMax = lineMaxBaht.reduce(function (a, b) {
        return a + b;
    }, 0);
    if (sumMax <= 0) {
        return;
    }
    var target = Math.min(targetBaht, sumMax);
    if (target >= sumMax) {
        rows.forEach(function (r) {
            r.qty = r.maxQty;
        });
        return;
    }
    rows.forEach(function (r, i) {
        var share = lineMaxBaht[i] / sumMax;
        var lineBaht = Math.floor(target * share);
        r.qty = Math.min(r.maxQty, Math.floor(lineBaht / r.price));
    });
    var allocated = rows.reduce(function (s, r) {
        return s + r.qty * r.price;
    }, 0);
    var guard = 0;
    while (allocated < target && guard < 5000) {
        guard += 1;
        var progressed = false;
        rows.forEach(function (r) {
            if (r.qty < r.maxQty && allocated + r.price <= target) {
                r.qty += 1;
                allocated += r.price;
                progressed = true;
            }
        });
        if (!progressed) {
            break;
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var pickerList = document.getElementById('fdItemPickerList');
    var totalEl = document.getElementById('fdPickTotal');
    var summaryList = document.getElementById('fdPickSummaryList');
    var summaryHint = document.getElementById('fdPickSummaryHint');
    var amtInput = document.getElementById('amountInput');
    var donateBtn = document.getElementById('fdDonateSubmitBtn');
    var donateAllBtn = document.getElementById('fdDonateAllBtn');
    var donateAllActive = false;

    function fdFormatBaht(n) {
        return Number(n || 0).toLocaleString('th-TH');
    }

    function fdClampQtyInput(inp) {
        var max = parseInt(inp.getAttribute('max') || '0', 10);
        var min = parseInt(inp.getAttribute('min') || '0', 10);
        var v = parseInt(inp.value || '0', 10);
        if (isNaN(v)) v = min;
        if (v < min) v = min;
        if (max > 0 && v > max) v = max;
        inp.value = String(v);
        return v;
    }

    function fdSyncPickerTotals() {
        if (!pickerList) return 0;
        var total = 0;
        var rows = [];
        pickerList.querySelectorAll('.fd-picker-row').forEach(function (row) {
            var price = parseFloat(row.getAttribute('data-price') || '0');
            var inp = row.querySelector('.fd-qty-input');
            var lineEl = row.querySelector('.fd-picker-line-total');
            if (!inp || price <= 0) return;
            var qty = fdClampQtyInput(inp);
            var line = Math.round(qty * price);
            if (lineEl) lineEl.textContent = fdFormatBaht(line) + ' บาท';
            row.classList.toggle('fd-picker-row--active', qty > 0);
            if (qty > 0) {
                total += line;
                var nameEl = row.querySelector('.fd-picker-name');
                rows.push({
                    name: nameEl ? nameEl.textContent.trim() : 'รายการ',
                    qty: qty,
                    line: line
                });
            }
        });
        if (totalEl) totalEl.textContent = fdFormatBaht(total);
        if (amtInput) amtInput.value = String(total);
        if (summaryList) {
            if (rows.length === 0) {
                summaryList.innerHTML = '';
            } else {
                summaryList.innerHTML = rows.map(function (r) {
                    return '<li><span>' + r.name + ' × ' + r.qty + '</span><strong>' + fdFormatBaht(r.line) + ' บาท</strong></li>';
                }).join('');
            }
        }
        if (summaryHint) {
            var maxB = fdGetMaxDonateBaht();
            if (rows.length === 0) {
                summaryHint.textContent = 'เลือกสิ่งของอย่างน้อย 1 รายการ (ขั้นต่ำรวม 20 บาท)';
                summaryHint.classList.remove('fd-picker-summary-hint--warn');
            } else if (total < 20) {
                summaryHint.textContent = 'ยอดรวมต้องไม่ต่ำกว่า 20 บาท';
                summaryHint.classList.add('fd-picker-summary-hint--warn');
            } else if (maxB !== null && total > maxB) {
                summaryHint.textContent = 'ยอดรวมเกินที่เหลือจะครบเป้าหมาย (' + fdFormatBaht(maxB) + ' บาท) — ลดจำนวนชิ้นลง';
                summaryHint.classList.add('fd-picker-summary-hint--warn');
            } else {
                summaryHint.textContent = 'พร้อมชำระด้วย PromptPay QR';
                summaryHint.classList.remove('fd-picker-summary-hint--warn');
            }
        }
        if (donateBtn && !donateBtn.hasAttribute('data-force-disabled')) {
            donateBtn.disabled = rows.length === 0 || total < 20 || (fdGetMaxDonateBaht() !== null && total > fdGetMaxDonateBaht());
        }
        return total;
    }

    function fdSetDonateAllActive(active) {
        donateAllActive = !!active;
        if (!donateAllBtn) {
            return;
        }
        donateAllBtn.classList.toggle('is-active', donateAllActive);
        donateAllBtn.setAttribute('aria-pressed', donateAllActive ? 'true' : 'false');
        var labelOn = donateAllBtn.getAttribute('data-label-on') || '';
        var labelOff = donateAllBtn.getAttribute('data-label-off') || '';
        if (labelOn && labelOff) {
            donateAllBtn.textContent = donateAllActive ? labelOn : labelOff;
        }
    }

    function fdClearDonateAllSelections() {
        if (!pickerList) {
            return;
        }
        pickerList.querySelectorAll('.fd-qty-input').forEach(function (inp) {
            inp.value = '0';
        });
        fdSyncPickerTotals();
    }

    function fdApplyDonateAll() {
        if (!pickerList) {
            return;
        }
        var targetBaht = donateAllBtn
            ? parseInt(donateAllBtn.getAttribute('data-target-baht') || '0', 10)
            : 0;
        if (isNaN(targetBaht) || targetBaht <= 0) {
            var maxB = fdGetMaxDonateBaht();
            var sumMax = 0;
            pickerList.querySelectorAll('.fd-picker-row').forEach(function (row) {
                var price = parseFloat(row.getAttribute('data-price') || '0');
                var maxQty = parseInt(row.getAttribute('data-max-qty') || '0', 10);
                if (price > 0 && maxQty > 0) {
                    sumMax += Math.round(price * maxQty);
                }
            });
            targetBaht = maxB !== null ? Math.min(maxB, sumMax) : sumMax;
        }
        var rows = [];
        pickerList.querySelectorAll('.fd-picker-row').forEach(function (row) {
            var inp = row.querySelector('.fd-qty-input');
            var price = parseFloat(row.getAttribute('data-price') || '0');
            var maxQty = parseInt(row.getAttribute('data-max-qty') || inp.getAttribute('max') || '0', 10);
            if (!inp || price <= 0 || maxQty <= 0) {
                return;
            }
            rows.push({ inp: inp, price: price, maxQty: maxQty, qty: 0 });
        });
        fdAllocateDonateAllQuantities(rows, targetBaht);
        rows.forEach(function (r) {
            r.inp.value = String(r.qty);
        });
        fdSyncPickerTotals();
    }

    if (donateAllBtn) {
        donateAllBtn.addEventListener('click', function () {
            if (donateAllActive) {
                fdClearDonateAllSelections();
                fdSetDonateAllActive(false);
                return;
            }
            fdApplyDonateAll();
            fdSetDonateAllActive(true);
        });
    }

    function fdOnManualQtyChange() {
        if (donateAllActive) {
            fdSetDonateAllActive(false);
        }
        fdSyncPickerTotals();
    }

    if (pickerList) {
        pickerList.addEventListener('click', function (e) {
            var btn = e.target.closest('.fd-qty-btn');
            if (!btn) return;
            var wrap = btn.closest('.fd-picker-qty');
            var inp = wrap ? wrap.querySelector('.fd-qty-input') : null;
            if (!inp) return;
            var delta = btn.classList.contains('fd-qty-plus') ? 1 : -1;
            inp.value = String(fdClampQtyInput(inp) + delta);
            fdOnManualQtyChange();
        });
        pickerList.addEventListener('input', function (e) {
            if (e.target && e.target.classList.contains('fd-qty-input')) {
                fdOnManualQtyChange();
            }
        });
        pickerList.addEventListener('change', function (e) {
            if (e.target && e.target.classList.contains('fd-qty-input')) {
                fdOnManualQtyChange();
            }
        });
        fdSyncPickerTotals();
    }
});
document.getElementById('foundationDonateForm').addEventListener('submit', function (e) {
    var total = 0;
    var pickerListEl = document.getElementById('fdItemPickerList');
    var hasPick = false;
    if (pickerListEl) {
        pickerListEl.querySelectorAll('.fd-qty-input').forEach(function (inp) {
            var qty = parseInt(inp.value || '0', 10);
            var row = inp.closest('.fd-picker-row');
            var price = row ? parseFloat(row.getAttribute('data-price') || '0') : 0;
            if (!isNaN(qty) && qty > 0 && price > 0) {
                hasPick = true;
                total += Math.round(qty * price);
            }
        });
    }
    var maxB = fdGetMaxDonateBaht();
    var amtInput = document.getElementById('amountInput');
    if (!hasPick) {
        e.preventDefault();
        alert('กรุณาเลือกสิ่งของอย่างน้อย 1 รายการ');
        return;
    }
    if (total < 20) {
        e.preventDefault();
        alert('ยอดรวมจากสิ่งของที่เลือกต้องไม่ต่ำกว่า 20 บาท');
        return;
    }
    if (maxB !== null && total > maxB) {
        e.preventDefault();
        alert('ยอดรวมเกินยอดที่เหลือจะครบเป้าหมาย (' + maxB.toLocaleString('th-TH') + ' บาท) — ลดจำนวนชิ้นลง');
        return;
    }
    if (amtInput) amtInput.value = String(total);
});
</script>
</body>
</html>