<?php

// includes/drawdream_needlist_schema.php — Schema/migration รายการสิ่งของ
// สรุปสั้น: ตรวจและปรับ schema ตาราง needlist ให้รองรับฟีเจอร์ปัจจุบัน
declare(strict_types=1);

/** ปัดจำนวนเงินเป็นบาททศนิยม 2 ตำแหน่ง (เก็บใน JSON / DB ให้ตรงที่มูลนิธิกรอก ไม่มี float ยาว) */
function drawdream_needlist_round_money(float $amount): float
{
    return round($amount, 2);
}

/**
 * อัตราค่าบริการ 5% — ใช้บันทึกลงคอลัมน์ service_charge เพื่อแสดงผล/รายงานเท่านั้น
 * ไม่หักจาก current_donate, escrow หรือยอดโอนให้มูลนิธิ
 */
function drawdream_needlist_service_charge_rate(): float
{
    return 0.05;
}

/** คำนวณค่าบริการ 5% สำหรับแสดงผล (ไม่มีผลต่อการจ่ายเงินจริง) */
function drawdream_needlist_compute_service_charge(float $donatedAmount): float
{
    return drawdream_needlist_round_money(max(0.0, $donatedAmount) * drawdream_needlist_service_charge_rate());
}

/** บันทึก service_charge ลง DB เมื่อครบเป้า — เก็บไว้ดูในตารางเท่านั้น */
function drawdream_needlist_sync_service_charge_for_item(mysqli $conn, int $itemId): void
{
    if ($itemId <= 0) {
        return;
    }
    $zero = $conn->prepare(
        'UPDATE foundation_needlist
         SET service_charge = 0
         WHERE item_id = ?
           AND (COALESCE(total_price, 0) <= 0 OR COALESCE(current_donate, 0) < COALESCE(total_price, 0))'
    );
    if ($zero) {
        $zero->bind_param('i', $itemId);
        @$zero->execute();
    }
    $rate = drawdream_needlist_service_charge_rate();
    $set = $conn->prepare(
        'UPDATE foundation_needlist
         SET service_charge = ROUND(COALESCE(current_donate, 0) * ?, 2)
         WHERE item_id = ?
           AND COALESCE(total_price, 0) > 0
           AND COALESCE(current_donate, 0) >= COALESCE(total_price, 0)'
    );
    if ($set) {
        $set->bind_param('di', $rate, $itemId);
        @$set->execute();
    }
}

/** รายการนี้ได้รับบริจาคครบเป้าหมายแล้วหรือไม่ */
function drawdream_needlist_item_goal_met(float $raised, float $goal): bool
{
    return $goal > 0 && $raised >= $goal - 1e-6;
}

/** backfill service_charge รายการที่ครบยอดแล้ว (ข้อมูลแสดงผล ไม่หักเงิน) */
function drawdream_needlist_backfill_service_charges(mysqli $conn): void
{
    $rate = drawdream_needlist_service_charge_rate();
    @$conn->query(
        'UPDATE foundation_needlist
         SET service_charge = 0
         WHERE COALESCE(total_price, 0) <= 0
            OR COALESCE(current_donate, 0) < COALESCE(total_price, 0)'
    );
    $st = $conn->prepare(
        'UPDATE foundation_needlist
         SET service_charge = ROUND(COALESCE(current_donate, 0) * ?, 2)
         WHERE COALESCE(total_price, 0) > 0
           AND COALESCE(current_donate, 0) >= COALESCE(total_price, 0)'
    );
    if ($st) {
        $st->bind_param('d', $rate);
        @$st->execute();
    }
}

/**
 * โครงสร้าง foundation_needlist: รูปสิ่งของได้หลายไฟล์ (คอลัมน์ 3 + item_image เป็น TEXT)
 */
function drawdream_ensure_needlist_schema(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $t = @$conn->query("SHOW TABLES LIKE 'foundation_needlist'");
    if (!$t || $t->num_rows === 0) {
        $ensured = true;
        return;
    }

    $chk = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'item_image'");
    if ($chk && ($col = $chk->fetch_assoc())) {
        $type = strtolower((string)($col['Type'] ?? ''));
        if (preg_match('/^varchar\(/i', $type) || preg_match('/^char\(/i', $type)) {
            @$conn->query('ALTER TABLE foundation_needlist MODIFY COLUMN `item_image` TEXT NULL DEFAULT NULL');
        }
    }

    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'item_image_2'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN item_image_2 VARCHAR(255) NULL DEFAULT NULL AFTER item_image');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'item_image_3'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN item_image_3 VARCHAR(255) NULL DEFAULT NULL AFTER item_image_2');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'need_foundation_image'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN need_foundation_image VARCHAR(255) NULL DEFAULT NULL AFTER item_image_3');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'need_items_json'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN need_items_json LONGTEXT NULL DEFAULT NULL AFTER total_price');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'need_items_pricing_json'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN need_items_pricing_json LONGTEXT NULL DEFAULT NULL AFTER need_items_json');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'submitted_need_items_pricing_json'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN submitted_need_items_pricing_json LONGTEXT NULL DEFAULT NULL AFTER submitted_total_price');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'desired_brand'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN desired_brand VARCHAR(200) NULL DEFAULT NULL');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'submitted_total_price'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN submitted_total_price DECIMAL(12,2) NULL DEFAULT NULL AFTER total_price');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'price_reviewed_at'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN price_reviewed_at DATETIME NULL DEFAULT NULL AFTER submitted_total_price');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'approved_total_price'")) && $c->num_rows > 0) {
        @$conn->query('ALTER TABLE foundation_needlist DROP COLUMN approved_total_price');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'update_text'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN update_text LONGTEXT NULL DEFAULT NULL');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'update_images'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN update_images LONGTEXT NULL DEFAULT NULL');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'update_at'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN update_at DATETIME NULL DEFAULT NULL');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'admin_delivery_text'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN admin_delivery_text LONGTEXT NULL DEFAULT NULL');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'admin_delivery_images'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN admin_delivery_images LONGTEXT NULL DEFAULT NULL');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'admin_delivery_at'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN admin_delivery_at DATETIME NULL DEFAULT NULL');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'service_charge'")) && $c->num_rows === 0) {
        @$conn->query(
            'ALTER TABLE foundation_needlist ADD COLUMN service_charge DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT \'ค่าบริการ 5% บันทึกแสดงผลเท่านั้น ไม่หักยอด\' AFTER total_price'
        );
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'service_charge_paid_at'")) && $c->num_rows === 0) {
        @$conn->query(
            'ALTER TABLE foundation_needlist ADD COLUMN service_charge_paid_at DATETIME NULL DEFAULT NULL COMMENT \'วันที่มูลนิธิชำระค่าบริการระบบ\' AFTER service_charge'
        );
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'quantity_received'")) && $c->num_rows > 0) {
        @$conn->query('ALTER TABLE foundation_needlist DROP COLUMN quantity_received');
    }
    drawdream_needlist_backfill_service_charges($conn);
    $hasItemDesc = false;
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'item_desc'")) && $c->num_rows > 0) {
        $hasItemDesc = true;
    }
    if ($hasItemDesc) {
        @$conn->query(
            "UPDATE foundation_needlist
             SET desired_brand = item_desc
             WHERE (desired_brand IS NULL OR TRIM(desired_brand) = '')
               AND (item_desc IS NOT NULL AND TRIM(item_desc) <> '')"
        );
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'price_estimate'")) && $c->num_rows > 0) {
        @$conn->query('ALTER TABLE foundation_needlist DROP COLUMN price_estimate');
    }
    if ($hasItemDesc) {
        @$conn->query('ALTER TABLE foundation_needlist DROP COLUMN item_desc');
    }

    $addedDonateWindowEnd = false;
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'donate_window_end_at'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN donate_window_end_at DATETIME NULL DEFAULT NULL');
        $addedDonateWindowEnd = true;
    }
    if ($addedDonateWindowEnd) {
        require_once __DIR__ . '/needlist_donate_window.php';
        drawdream_needlist_backfill_donate_window_ends($conn);
    }

    // Backfill ประวัติราคา: ค่าที่มูลนิธิเสนอ และค่าที่แอดมินอนุมัติ
    @$conn->query(
        "UPDATE foundation_needlist
         SET submitted_total_price = total_price
         WHERE submitted_total_price IS NULL AND total_price IS NOT NULL"
    );
    @$conn->query(
        "UPDATE foundation_needlist
         SET submitted_need_items_pricing_json = need_items_pricing_json
         WHERE (submitted_need_items_pricing_json IS NULL OR TRIM(submitted_need_items_pricing_json) = '')
           AND need_items_pricing_json IS NOT NULL
           AND TRIM(need_items_pricing_json) <> ''
           AND price_reviewed_at IS NULL"
    );
    @$conn->query(
        "UPDATE foundation_needlist
         SET price_reviewed_at = created_at
         WHERE price_reviewed_at IS NULL
           AND created_at IS NOT NULL
           AND approve_item IN ('approved','purchasing','done')"
    );

    // แปลง need_items_json รูปแบบเก่า (ฝังราคาใน items) -> แยกราคาไป need_items_pricing_json
    // ข้ามแถวที่แยกแล้ว (ไม่มีราคาใน items) — ไม่เฉลี่ยยอดรวมทับราคาแอดมิน
    $rsItems = @$conn->query(
        "SELECT item_id, need_items_json, need_items_pricing_json
         FROM foundation_needlist
         WHERE need_items_json IS NOT NULL AND TRIM(need_items_json) <> ''"
    );
    if ($rsItems) {
        while ($row = $rsItems->fetch_assoc()) {
            $itemId = (int)($row['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $rawJson = trim((string)($row['need_items_json'] ?? ''));
            try {
                $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($decoded)) {
                continue;
            }
            $hasEmbeddedPrices = false;
            foreach ($decoded as $li) {
                if (!is_array($li)) {
                    continue;
                }
                if ((float)($li['ราคาต่อชิ้น'] ?? ($li['price_estimate'] ?? ($li['price'] ?? 0))) > 0) {
                    $hasEmbeddedPrices = true;
                    break;
                }
            }
            if (!$hasEmbeddedPrices) {
                continue;
            }
            $out = [];
            $pricingOut = [];
            foreach ($decoded as $idx => $li) {
                if (!is_array($li)) {
                    continue;
                }
                $cat = trim((string)($li['หมวดหมู่สิ่งของ'] ?? ($li['category'] ?? '')));
                $name = trim((string)($li['ชื่อสิ่งของ'] ?? ($li['item_name'] ?? '')));
                $qty = (float)($li['จำนวนสิ่งของ'] ?? ($li['qty_needed'] ?? ($li['qty'] ?? 0)));
                $price = (float)($li['ราคาต่อชิ้น'] ?? ($li['price_estimate'] ?? ($li['price'] ?? 0)));
                $sum = (float)($li['ราคารวม'] ?? ($li['line_total'] ?? ($qty * $price)));
                if ($cat === '' && $name === '' && $qty <= 0 && $price <= 0 && $sum <= 0) {
                    continue;
                }
                $slot = (int)($li['ลำดับ'] ?? ($li['slot'] ?? ($idx + 1)));
                if ($slot <= 0) {
                    $slot = $idx + 1;
                }
                $out[] = [
                    'ลำดับ' => $slot,
                    'หมวดหมู่สิ่งของ' => $cat,
                    'ชื่อสิ่งของ' => $name,
                    'จำนวนสิ่งของ' => $qty,
                ];
                if ($sum <= 0 && $qty > 0 && $price > 0) {
                    $sum = drawdream_needlist_round_money($qty * $price);
                }
                $pricingOut[] = [
                    'ลำดับ' => $slot,
                    'ราคาต่อชิ้น' => drawdream_needlist_round_money($price),
                    'ราคารวม' => drawdream_needlist_round_money($sum),
                ];
            }
            if ($out === []) {
                continue;
            }
            $encoded = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                continue;
            }
            $existingPricing = trim((string)($row['need_items_pricing_json'] ?? ''));
            if ($existingPricing !== '') {
                $up = $conn->prepare('UPDATE foundation_needlist SET need_items_json = ? WHERE item_id = ?');
                if ($up) {
                    $up->bind_param('si', $encoded, $itemId);
                    @$up->execute();
                }
                continue;
            }
            $pricingEncoded = json_encode($pricingOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($pricingEncoded)) {
                continue;
            }
            $up = $conn->prepare('UPDATE foundation_needlist SET need_items_json = ?, need_items_pricing_json = ? WHERE item_id = ?');
            if ($up) {
                $up->bind_param('ssi', $encoded, $pricingEncoded, $itemId);
                @$up->execute();
            }
        }
    }

    // คอลัมน์ที่ยกเลิกใช้งาน
    drawdream_repair_truncated_need_foundation_images($conn);

    foreach ([
        'brand',
        'category',
        'previous_total_price',
        'submitted_items_json',
        'reviewed_by_user_id',
        'reviewed_at',
        'price_reviewed_by_user_id',
        'created_by_user_id',
    ] as $dropCol) {
        $c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE '" . mysqli_real_escape_string($conn, $dropCol) . "'");
        if ($c && $c->num_rows > 0) {
            @$conn->query("ALTER TABLE foundation_needlist DROP COLUMN `{$dropCol}`");
        }
    }
    $ensured = true;
}

/**
 * แยกชื่อไฟล์จากสตริงเก็บใน item_image (| หรือ ,)
 *
 * @return list<string>
 */
function foundation_parse_need_item_filenames(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    if (strpos($raw, '|') !== false) {
        $parts = preg_split('/\|+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    } elseif (strpos($raw, ',') !== false) {
        $parts = preg_split('/\s*,\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    } else {
        $parts = [$raw];
    }
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '' && $p !== '.' && $p !== '..') {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * ทำให้ค่ารูปใน DB เหลือเป็นชื่อไฟล์เดียวเสมอ
 * รองรับข้อมูลเก่าที่อาจเก็บ path เต็มมา เช่น uploads/needs/xxx.png
 */
/**
 * โฟลเดอร์อัปโหลดรูปรายการสิ่งของ (absolute path, มี trailing slash)
 */
function drawdream_needlist_upload_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'needs' . DIRECTORY_SEPARATOR;
}

/**
 * แก้แถวที่ need_foundation_image ถูก bind เป็น integer จนเหลือแค่ timestamp (เช่น 1779608116)
 */
function drawdream_repair_truncated_need_foundation_images(mysqli $conn): void
{
    $t = @$conn->query("SHOW TABLES LIKE 'foundation_needlist'");
    if (!$t || $t->num_rows === 0) {
        return;
    }
    $c = @$conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'need_foundation_image'");
    if (!$c || $c->num_rows === 0) {
        return;
    }

    $dir = drawdream_needlist_upload_dir();
    if (!is_dir($dir)) {
        return;
    }

    $rs = @$conn->query(
        "SELECT item_id, need_foundation_image
         FROM foundation_needlist
         WHERE need_foundation_image IS NOT NULL
           AND need_foundation_image REGEXP '^[0-9]+$'"
    );
    if (!$rs) {
        return;
    }

    $up = $conn->prepare(
        'UPDATE foundation_needlist SET need_foundation_image = ? WHERE item_id = ? AND need_foundation_image = ? LIMIT 1'
    );
    if (!$up) {
        return;
    }

    while ($row = $rs->fetch_assoc()) {
        $itemId = (int)($row['item_id'] ?? 0);
        $stored = trim((string)($row['need_foundation_image'] ?? ''));
        if ($itemId <= 0 || $stored === '') {
            continue;
        }
        $matches = glob($dir . $stored . '_*_fdn.*', GLOB_NOSORT) ?: [];
        if ($matches === []) {
            continue;
        }
        $basename = foundation_needlist_normalize_filename(basename((string)$matches[0]));
        if ($basename === '' || !is_file($dir . $basename)) {
            continue;
        }
        $up->bind_param('sis', $basename, $itemId, $stored);
        @$up->execute();
    }
}

/**
 * แยกรายการราคาจาก JSON (เรียงตาม index และ map ตาม ลำดับ)
 *
 * @return array{by_index: array<int,array{price:float,sum:float}>, by_order: array<int,array{price:float,sum:float}>}
 */
function foundation_needlist_pricing_maps_from_json(string $rawPricing): array
{
    $byIndex = [];
    $byOrder = [];
    $rawPricing = trim($rawPricing);
    if ($rawPricing === '') {
        return ['by_index' => $byIndex, 'by_order' => $byOrder];
    }
    try {
        $decoded = json_decode($rawPricing, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['by_index' => $byIndex, 'by_order' => $byOrder];
    }
    if (!is_array($decoded)) {
        return ['by_index' => $byIndex, 'by_order' => $byOrder];
    }
    foreach ($decoded as $idxP => $pp) {
        if (!is_array($pp)) {
            continue;
        }
        $price = drawdream_needlist_round_money((float)($pp['ราคาต่อชิ้น'] ?? ($pp['price_estimate'] ?? ($pp['price'] ?? 0))));
        $sum = drawdream_needlist_round_money((float)($pp['ราคารวม'] ?? ($pp['line_total'] ?? 0)));
        $byIndex[(int)$idxP] = ['price' => $price, 'sum' => $sum];
        $ord = (int)($pp['ลำดับ'] ?? ($idxP + 1));
        if ($ord > 0) {
            $byOrder[$ord] = ['price' => $price, 'sum' => $sum];
        }
    }
    return ['by_index' => $byIndex, 'by_order' => $byOrder];
}

/**
 * รายการ + ราคาต่อชิ้นที่มูลนิธิเสนอตอนส่ง (snapshot ไม่เปลี่ยนเมื่อแอดมินแก้)
 *
 * @param array<string,mixed> $row
 * @return list<array{slot:int,category:string,item_name:string,qty:float,price:float,line_total:float}>
 */
function foundation_needlist_submitted_line_items_from_row(array $row): array
{
    $submittedRaw = trim((string)($row['submitted_need_items_pricing_json'] ?? ''));
    if ($submittedRaw !== '') {
        $tmp = $row;
        $tmp['need_items_pricing_json'] = $submittedRaw;
        return foundation_needlist_line_items_from_row($tmp, false);
    }
    $fromItems = foundation_needlist_line_items_from_row($row, true);
    foreach ($fromItems as $li) {
        if ((float)($li['price'] ?? 0) > 0) {
            return $fromItems;
        }
    }
    if (strtolower(trim((string)($row['approve_item'] ?? ''))) === 'pending') {
        return foundation_needlist_line_items_from_row($row, false);
    }

    return [];
}

/**
 * รายการ + ราคาต่อชิ้นที่แอดมินกำหนด (จาก need_items_pricing_json เท่านั้น)
 *
 * @param array<string,mixed> $row
 * @return list<array{slot:int,category:string,item_name:string,qty:float,price:float,line_total:float}>
 */
function foundation_needlist_admin_line_items_from_row(array $row): array
{
    return foundation_needlist_line_items_from_row($row, false, true);
}

/**
 * รายการสิ่งของย่อย + ราคาต่อชิ้น (จับคู่ตาม index ใน JSON)
 *
 * @param array<string,mixed> $row
 * @param bool $preferItemsJsonPrices true = อ่านราคาจาก need_items_json ที่มูลนิธิกรอก (ไม่เฉลี่ย)
 * @param bool $adminPricingOnly true = ราคาแอดมินจาก need_items_pricing_json เท่านั้น (ไม่ fallback ราคาใน items)
 * @return list<array{slot:int,category:string,item_name:string,qty:float,price:float,line_total:float}>
 */
function foundation_needlist_line_items_from_row(array $row, bool $preferItemsJsonPrices = false, bool $adminPricingOnly = false): array
{
    $raw = trim((string)($row['need_items_json'] ?? ''));
    $rawPricing = trim((string)($row['need_items_pricing_json'] ?? ''));
    if ($raw === '' && $rawPricing === '') {
        return [];
    }
    try {
        $decoded = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
    } catch (Throwable $e) {
        $decoded = [];
    }
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $maps = foundation_needlist_pricing_maps_from_json($rawPricing);
    $namePool = array_values(array_filter(array_map('trim', explode(',', (string)($row['item_name'] ?? '')))));
    $out = [];
    foreach ($decoded as $idx => $li) {
        if (!is_array($li)) {
            continue;
        }
        $slot = (int)($li['ลำดับ'] ?? ($li['slot'] ?? ($idx + 1)));
        if ($slot <= 0) {
            $slot = $idx + 1;
        }
        $qty = (float)($li['จำนวนสิ่งของ'] ?? ($li['qty_needed'] ?? ($li['qty'] ?? 0)));
        if ($qty <= 0) {
            continue;
        }
        $price = 0.0;
        $lineTotal = 0.0;
        $priceInItems = drawdream_needlist_round_money((float)($li['ราคาต่อชิ้น'] ?? ($li['price_estimate'] ?? ($li['price'] ?? 0))));
        $sumInItems = drawdream_needlist_round_money((float)($li['ราคารวม'] ?? ($li['line_total'] ?? 0)));

        if ($preferItemsJsonPrices && $priceInItems > 0) {
            $price = $priceInItems;
            $lineTotal = $sumInItems > 0 ? $sumInItems : drawdream_needlist_round_money($qty * $price);
        } else {
            if (isset($maps['by_index'][$idx])) {
                $price = (float)$maps['by_index'][$idx]['price'];
                $lineTotal = (float)$maps['by_index'][$idx]['sum'];
            }
            if ($price <= 0 && isset($maps['by_order'][$slot])) {
                $price = (float)$maps['by_order'][$slot]['price'];
                $lineTotal = (float)$maps['by_order'][$slot]['sum'];
            }
            if (!$adminPricingOnly && $price <= 0 && $priceInItems > 0) {
                $price = $priceInItems;
                $lineTotal = $sumInItems > 0 ? $sumInItems : drawdream_needlist_round_money($qty * $price);
            }
            if ($lineTotal <= 0 && $qty > 0 && $price > 0) {
                $lineTotal = drawdream_needlist_round_money($qty * $price);
            }
        }
        $cat = trim((string)($li['หมวดหมู่สิ่งของ'] ?? ($li['category'] ?? '')));
        $itemName = trim((string)($li['ชื่อสิ่งของ'] ?? ($li['item_name'] ?? '')));
        if ($itemName === '') {
            $itemName = trim((string)($namePool[$idx] ?? ''));
        }
        $out[] = [
            'slot' => $slot,
            'category' => $cat,
            'item_name' => $itemName,
            'qty' => $qty,
            'price' => $price > 0 ? $price : 0.0,
            'line_total' => $lineTotal > 0 ? $lineTotal : ($qty * max(0.0, $price)),
        ];
    }
    if ($out === [] && $adminPricingOnly && $rawPricing !== '') {
        try {
            $pricingRows = json_decode($rawPricing, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $pricingRows = [];
        }
        if (is_array($pricingRows)) {
            foreach ($pricingRows as $idxP => $pp) {
                if (!is_array($pp)) {
                    continue;
                }
                $slot = (int)($pp['ลำดับ'] ?? ($idxP + 1));
                if ($slot <= 0) {
                    $slot = $idxP + 1;
                }
                $price = drawdream_needlist_round_money((float)($pp['ราคาต่อชิ้น'] ?? ($pp['price_estimate'] ?? ($pp['price'] ?? 0))));
                $lineTotal = drawdream_needlist_round_money((float)($pp['ราคารวม'] ?? ($pp['line_total'] ?? 0)));
                if ($price <= 0) {
                    continue;
                }
                if ($lineTotal <= 0) {
                    $lineTotal = $price;
                }
                $out[] = [
                    'slot' => $slot,
                    'category' => '',
                    'item_name' => trim((string)($namePool[$idxP] ?? '')),
                    'qty' => $lineTotal > 0 && $price > 0 ? drawdream_needlist_round_money($lineTotal / $price) : 1.0,
                    'price' => $price,
                    'line_total' => $lineTotal,
                ];
            }
        }
    }

    return $out;
}

/**
 * คงเฉพาะ ลำดับ หมวดหมู่ ชื่อ จำนวน ใน need_items_json (ราคาอยู่ใน need_items_pricing_json)
 */
function foundation_needlist_items_json_strip_prices(string $itemsJson): string
{
    $itemsJson = trim($itemsJson);
    if ($itemsJson === '') {
        return '[]';
    }
    try {
        $decoded = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return $itemsJson;
    }
    if (!is_array($decoded)) {
        return $itemsJson;
    }
    $out = [];
    foreach ($decoded as $idx => $li) {
        if (!is_array($li)) {
            continue;
        }
        $cat = trim((string)($li['หมวดหมู่สิ่งของ'] ?? ($li['category'] ?? '')));
        $name = trim((string)($li['ชื่อสิ่งของ'] ?? ($li['item_name'] ?? '')));
        $qty = (float)($li['จำนวนสิ่งของ'] ?? ($li['qty_needed'] ?? ($li['qty'] ?? 0)));
        if ($cat === '' && $name === '' && $qty <= 0) {
            continue;
        }
        $slot = (int)($li['ลำดับ'] ?? ($li['slot'] ?? ($idx + 1)));
        if ($slot <= 0) {
            $slot = $idx + 1;
        }
        $out[] = [
            'ลำดับ' => $slot,
            'หมวดหมู่สิ่งของ' => $cat,
            'ชื่อสิ่งของ' => $name,
            'จำนวนสิ่งของ' => $qty,
        ];
    }
    $encoded = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : $itemsJson;
}

/**
 * สร้าง JSON รายการ + ราคาจากแถวที่แก้แล้ว (ใช้ตอนมูลนิธิ/แอดมินบันทึก)
 *
 * @param list<array{slot:int,category:string,item_name:string,qty:float,price:float,line_total?:float}> $lineItems
 * @return array{items_json:string,pricing_json:string,total:float}
 */
function foundation_needlist_encode_line_items_json(array $lineItems): array
{
    $itemsOut = [];
    $pricingOut = [];
    $total = 0.0;
    foreach ($lineItems as $li) {
        $slot = (int)($li['slot'] ?? 0);
        $qty = (float)($li['qty'] ?? 0);
        $price = drawdream_needlist_round_money((float)($li['price'] ?? 0));
        if ($slot <= 0 || $qty <= 0 || $price <= 0) {
            continue;
        }
        $lineTotal = drawdream_needlist_round_money((float)($li['line_total'] ?? ($qty * $price)));
        $total += $lineTotal;
        $itemsOut[] = [
            'ลำดับ' => $slot,
            'หมวดหมู่สิ่งของ' => (string)($li['category'] ?? ''),
            'ชื่อสิ่งของ' => (string)($li['item_name'] ?? ''),
            'จำนวนสิ่งของ' => $qty,
        ];
        $pricingOut[] = [
            'ลำดับ' => $slot,
            'ราคาต่อชิ้น' => $price,
            'ราคารวม' => $lineTotal,
        ];
    }
    $itemsJson = json_encode($itemsOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pricingJson = json_encode($pricingOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [
        'items_json' => is_string($itemsJson) ? $itemsJson : '[]',
        'pricing_json' => is_string($pricingJson) ? $pricingJson : '[]',
        'total' => drawdream_needlist_round_money($total),
    ];
}

function foundation_needlist_normalize_filename(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '.' || $raw === '..') {
        return '';
    }
    $raw = str_replace('\\', '/', $raw);
    $qPos = strpos($raw, '?');
    if ($qPos !== false) {
        $raw = substr($raw, 0, $qPos);
    }
    return basename($raw);
}

/**
 * รวมชื่อไฟล์รูปสิ่งของจากแถว needlist (รองรับทั้งแบบ pipe ใน item_image แบบเก่า และ 3 คอลัมน์)
 *
 * @param array<string,mixed> $row
 * @return list<string>
 */
function foundation_needlist_item_filenames_from_row(array $row): array
{
    $raw1 = trim((string)($row['item_image'] ?? ''));
    if ($raw1 !== '' && (strpos($raw1, '|') !== false || strpos($raw1, ',') !== false)) {
        return array_slice(foundation_parse_need_item_filenames($raw1), 0, 3);
    }

    $out = [];
    foreach (['item_image', 'item_image_2', 'item_image_3'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v === '' || $v === '.' || $v === '..') {
            continue;
        }
        $v = foundation_needlist_normalize_filename($v);
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return array_slice($out, 0, 3);
}

/**
 * หลักฐานการจัดส่งที่แอดมินอัปโหลด (admin_delivery_*)
 *
 * @param array<string,mixed> $row
 * @return array{text:string,images:list<string>,at_fmt:string,has:bool}
 */
function foundation_needlist_admin_delivery_from_row(array $row): array
{
    $text = trim((string)($row['admin_delivery_text'] ?? ''));
    $images = [];
    $raw = trim((string)($row['admin_delivery_images'] ?? ''));
    if ($raw !== '') {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $decoded = null;
        }
        if (is_array($decoded)) {
            foreach ($decoded as $img) {
                $fn = foundation_needlist_normalize_filename((string)$img);
                if ($fn !== '') {
                    $images[] = $fn;
                }
            }
        } else {
            $fn = foundation_needlist_normalize_filename($raw);
            if ($fn !== '') {
                $images[] = $fn;
            }
        }
    }
    $atRaw = trim((string)($row['admin_delivery_at'] ?? ''));
    $atFmt = '';
    if ($atRaw !== '' && !str_starts_with($atRaw, '0000-00-00') && strtotime($atRaw) !== false) {
        $atFmt = date('d/m/Y H:i', strtotime($atRaw));
    }

    return [
        'text' => $text,
        'images' => $images,
        'at_fmt' => $atFmt,
        'has' => $text !== '' || $images !== [],
    ];
}

/**
 * คอลัมน์ผลลัพธ์การระดมสิ่งของ (รวมทั้งมูลนิธิ) ใน foundation_profile
 */
function drawdream_ensure_foundation_profile_needlist_result_columns(mysqli $conn): void
{
    $t = @$conn->query("SHOW TABLES LIKE 'foundation_profile'");
    if (!$t || $t->num_rows === 0) {
        return;
    }
    $add = [
        ['needlist_result_text', 'TEXT NULL DEFAULT NULL'],
        ['needlist_result_at', 'DATETIME NULL DEFAULT NULL'],
        ['needlist_result_images', 'TEXT NULL DEFAULT NULL'],
    ];
    foreach ($add as $pair) {
        $name = $pair[0];
        $def = $pair[1];
        $c = @$conn->query("SHOW COLUMNS FROM foundation_profile LIKE '" . mysqli_real_escape_string($conn, $name) . "'");
        if ($c && $c->num_rows === 0) {
            @$conn->query("ALTER TABLE foundation_profile ADD COLUMN `{$name}` {$def}");
        }
    }
}
