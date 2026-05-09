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
 * โครงสร้าง foundation_needlist: รูปสิ่งของได้หลายไฟล์ (คอลัมน์ 3 + item_image เป็น TEXT)
 */
function drawdream_ensure_needlist_schema(mysqli $conn): void
{
    $t = @$conn->query("SHOW TABLES LIKE 'foundation_needlist'");
    if (!$t || $t->num_rows === 0) {
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
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'desired_brand'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN desired_brand VARCHAR(200) NULL DEFAULT NULL');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'submitted_total_price'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN submitted_total_price DECIMAL(12,2) NULL DEFAULT NULL AFTER total_price');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'approved_total_price'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN approved_total_price DECIMAL(12,2) NULL DEFAULT NULL AFTER submitted_total_price');
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_needlist LIKE 'price_reviewed_at'")) && $c->num_rows === 0) {
        @$conn->query('ALTER TABLE foundation_needlist ADD COLUMN price_reviewed_at DATETIME NULL DEFAULT NULL AFTER approved_total_price');
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
         SET approved_total_price = total_price
         WHERE approved_total_price IS NULL
           AND approve_item IN ('approved','purchasing','done')
           AND total_price IS NOT NULL"
    );
    @$conn->query(
        "UPDATE foundation_needlist
         SET price_reviewed_at = created_at
         WHERE price_reviewed_at IS NULL
           AND created_at IS NOT NULL
           AND approve_item IN ('approved','purchasing','done')"
    );

    // แปลง need_items_json รูปแบบเก่า -> คีย์ไทย 3 ฟิลด์ และแยกราคาไป need_items_pricing_json
    $rsItems = @$conn->query("SELECT item_id, need_items_json, total_price, qty_needed FROM foundation_needlist WHERE need_items_json IS NOT NULL AND TRIM(need_items_json) <> ''");
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
            $out = [];
            $pricingOut = [];
            $qtySum = 0.0;
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
                $out[] = [
                    'หมวดหมู่สิ่งของ' => $cat,
                    'ชื่อสิ่งของ' => $name,
                    'จำนวนสิ่งของ' => $qty,
                ];
                $qtySum += max(0.0, $qty);
                $pricingOut[] = [
                    'ลำดับ' => ((int)$idx + 1),
                    'ราคาต่อชิ้น' => drawdream_needlist_round_money($price),
                    'ราคารวม' => drawdream_needlist_round_money($sum),
                ];
            }
            $totalFromRow = (float)($row['total_price'] ?? 0);
            $fallbackUnit = ($qtySum > 0 && $totalFromRow > 0)
                ? drawdream_needlist_round_money($totalFromRow / $qtySum)
                : 0.0;
            if ($fallbackUnit > 0) {
                foreach ($pricingOut as $k => $pr) {
                    $p = drawdream_needlist_round_money((float)($pr['ราคาต่อชิ้น'] ?? 0));
                    $s = drawdream_needlist_round_money((float)($pr['ราคารวม'] ?? 0));
                    $lineQty = (float)($out[$k]['จำนวนสิ่งของ'] ?? 0);
                    if ($p <= 0) {
                        $p = $fallbackUnit;
                    }
                    if ($s <= 0 && $lineQty > 0) {
                        $s = drawdream_needlist_round_money($lineQty * $p);
                    }
                    $pricingOut[$k]['ราคาต่อชิ้น'] = $p;
                    $pricingOut[$k]['ราคารวม'] = $s;
                }
            }
            $encoded = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pricingEncoded = json_encode($pricingOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || !is_string($pricingEncoded)) {
                continue;
            }
            $up = $conn->prepare("UPDATE foundation_needlist SET need_items_json = ?, need_items_pricing_json = ? WHERE item_id = ?");
            if ($up) {
                $up->bind_param('ssi', $encoded, $pricingEncoded, $itemId);
                @$up->execute();
            }
        }
    }

    // คอลัมน์ที่ยกเลิกใช้งาน
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
