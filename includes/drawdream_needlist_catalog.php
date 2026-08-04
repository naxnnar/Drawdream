<?php
// includes/drawdream_needlist_catalog.php — คataloq สิ่งของ needlist + ติดตามชิ้นที่บริจาคแล้ว
declare(strict_types=1);

require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/donate_type.php';
require_once __DIR__ . '/drawdream_needlist_schema.php';

function drawdream_need_catalog_key(string $name, float $price): string
{
    return mb_strtolower(trim($name), 'UTF-8') . '|' . number_format($price, 2, '.', '');
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array{catalog_key:string,name:string,qty_needed:float,price:float,_order:int}>
 */
function drawdream_need_catalog_skeleton_from_needlist_rows(array $items): array
{
    $catalog = [];
    $order = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $lines = foundation_needlist_donor_picker_lines_from_row($item);
        foreach ($lines as $line) {
            $name = trim((string)($line['item_name'] ?? ''));
            $qty = (float)($line['qty'] ?? 0);
            $price = (float)($line['price'] ?? 0);
            if ($name === '' || $qty <= 0 || $price <= 0) {
                continue;
            }
            $key = drawdream_need_catalog_key($name, $price);
            if (!isset($catalog[$key])) {
                $catalog[$key] = [
                    'catalog_key' => $key,
                    'name' => $name,
                    'qty_needed' => 0.0,
                    'price' => $price,
                    '_order' => $order++,
                ];
            }
            $catalog[$key]['qty_needed'] += $qty;
        }
    }

    $rows = array_values($catalog);
    usort($rows, static function (array $a, array $b): int {
        return ((int)($a['_order'] ?? 0)) <=> ((int)($b['_order'] ?? 0));
    });

    return $rows;
}

/**
 * @return array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}>
 */
function drawdream_need_open_catalog_for_foundation(mysqli $conn, int $foundationId, float $goalRemainingBaht = 0.0): array
{
    if ($foundationId <= 0) {
        return [];
    }
    require_once __DIR__ . '/needlist_donate_window.php';
    $needOpen = drawdream_needlist_sql_open_for_donation();
    $st = $conn->prepare("SELECT * FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
    if (!$st) {
        return [];
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    return drawdream_need_catalog_with_remaining(
        $conn,
        $foundationId,
        drawdream_need_catalog_skeleton_from_needlist_rows($items),
        $goalRemainingBaht
    );
}

/** @return array<string, int> catalog_key => qty */
function drawdream_need_decode_picks_json(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $key => $qty) {
        $key = (string)$key;
        $qty = (int)max(0, (int)$qty);
        if ($key === '' || $qty <= 0) {
            continue;
        }
        $out[$key] = ($out[$key] ?? 0) + $qty;
    }

    return $out;
}

/** @param array<string, int> $picks */
function drawdream_need_encode_picks_json(array $picks): string
{
    $clean = [];
    foreach ($picks as $key => $qty) {
        $key = (string)$key;
        $qty = (int)max(0, (int)$qty);
        if ($key === '' || $qty <= 0) {
            continue;
        }
        $clean[$key] = $qty;
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
}

/**
 * จำกัด qty_remaining ให้รวมมูลค่าไม่เกิน targetBaht (ยอดเป้าหมายที่เหลือจริง)
 *
 * @param array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}> $catalog
 * @return array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}>
 */
function drawdream_need_catalog_cap_to_baht(array $catalog, float $targetBaht): array
{
    if ($catalog === [] || $targetBaht <= 0) {
        return $catalog;
    }

    $total = 0.0;
    foreach ($catalog as $row) {
        $total += (float)($row['qty_remaining'] ?? 0) * (float)($row['price'] ?? 0);
    }
    if ($total <= $targetBaht + 0.009) {
        return $catalog;
    }

    $guard = 0;
    while ($total > $targetBaht + 0.009 && $guard < 100000) {
        $guard++;
        $bestIdx = -1;
        foreach ($catalog as $i => $row) {
            $qty = (float)($row['qty_remaining'] ?? 0);
            $price = (float)($row['price'] ?? 0);
            if ($qty <= 0 || $price <= 0) {
                continue;
            }
            if ($total - $price >= $targetBaht - 0.009) {
                $bestIdx = $i;
                break;
            }
        }
        if ($bestIdx < 0) {
            foreach ($catalog as $i => $row) {
                if ((float)($row['qty_remaining'] ?? 0) > 0) {
                    $bestIdx = $i;
                    break;
                }
            }
        }
        if ($bestIdx < 0) {
            break;
        }
        $catalog[$bestIdx]['qty_remaining'] = (float)($catalog[$bestIdx]['qty_remaining'] ?? 0) - 1.0;
        $total -= (float)($catalog[$bestIdx]['price'] ?? 0);
    }

    return array_values(array_filter($catalog, static function (array $row): bool {
        return (float)($row['qty_remaining'] ?? 0) > 0 && (float)($row['price'] ?? 0) > 0;
    }));
}

/**
 * @param array<int,array{catalog_key:string,name:string,qty_needed:float,price:float}> $skeleton
 * @param array<string,int> $fundedQty
 * @return array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}>
 */
function drawdream_need_catalog_finalize_rows(array $skeleton, array $fundedQty): array
{
    $rows = [];
    foreach ($skeleton as $row) {
        $key = (string)($row['catalog_key'] ?? '');
        $qtyNeeded = (float)($row['qty_needed'] ?? 0);
        $funded = (float)($fundedQty[$key] ?? 0);
        $rows[] = [
            'catalog_key' => $key,
            'name' => (string)($row['name'] ?? ''),
            'qty_needed' => $qtyNeeded,
            'qty_remaining' => max(0.0, $qtyNeeded - $funded),
            'price' => (float)($row['price'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * @param array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}> $rows
 * @return array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}>
 */
function drawdream_need_catalog_rows_to_public(array $rows): array
{
    return array_values(array_map(static function (array $row): array {
        return [
            'catalog_key' => (string)($row['catalog_key'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'qty_needed' => (float)($row['qty_needed'] ?? 0),
            'qty_remaining' => max(0.0, (float)($row['qty_remaining'] ?? 0)),
            'price' => (float)($row['price'] ?? 0),
        ];
    }, $rows));
}

/**
 * @return array{picked: array<string,float>, legacy_baht: float}
 */
function drawdream_need_funded_state(mysqli $conn, int $foundationId): array
{
    require_once __DIR__ . '/drawdream_needlist_catalog_funded.php';

    return [
        'picked' => drawdream_need_catalog_funded_from_completed_donations($conn, $foundationId),
        'legacy_baht' => 0.0,
    ];
}

/**
 * @param array<int,array{catalog_key:string,name:string,qty_needed:float,price:float}> $skeleton
 * @return array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}>
 */
function drawdream_need_catalog_with_remaining(mysqli $conn, int $foundationId, array $skeleton, float $goalRemainingBaht = 0.0): array
{
    if ($skeleton === []) {
        return [];
    }

    require_once __DIR__ . '/drawdream_needlist_catalog_funded.php';

    $current = drawdream_needlist_foundation_current_donate($conn, $foundationId);
    $funded = drawdream_need_catalog_funded_from_completed_donations($conn, $foundationId);

    $pickedBaht = drawdream_need_pick_total_baht_from_skeleton($skeleton, $funded);
    if ($current > $pickedBaht + 0.01) {
        $legacyPicks = drawdream_need_catalog_picks_for_target_baht($skeleton, $current - $pickedBaht);
        foreach ($legacyPicks as $key => $qty) {
            $funded[$key] = ($funded[$key] ?? 0.0) + (float)$qty;
        }
    }

    $catalog = drawdream_need_catalog_finalize_rows($skeleton, $funded);
    if ($goalRemainingBaht > 0) {
        $catalog = drawdream_need_catalog_cap_to_baht($catalog, $goalRemainingBaht);
    }

    $catalog = array_values(array_filter($catalog, static function (array $row): bool {
        return (float)($row['qty_remaining'] ?? 0) > 0 && (float)($row['price'] ?? 0) > 0;
    }));

    if ($catalog === [] && $goalRemainingBaht >= 20) {
        $catalog = drawdream_need_catalog_remainder_fallback_row($skeleton, $goalRemainingBaht);
    }

    return $catalog;
}

/**
 * เมื่อยอดคงเหลือไม่ลงตัวกับจำนวนชิ้น — ให้เลือกสมทบยอดที่เหลือได้อย่างน้อย 1 รายการ
 *
 * @param array<int,array{catalog_key:string,name:string,qty_needed:float,price:float}> $skeleton
 * @return array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}>
 */
function drawdream_need_catalog_remainder_fallback_row(array $skeleton, float $goalRemainingBaht): array
{
    $remain = (int)max(0, (int)floor($goalRemainingBaht + 1e-9));
    if ($remain < 20) {
        return [];
    }

    foreach ($skeleton as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $price = (float)($row['price'] ?? 0);
        if ($name === '' || $price <= 0) {
            continue;
        }
        $maxQty = (int)max(0, (int)floor($remain / $price));
        if ($maxQty <= 0) {
            continue;
        }
        $key = drawdream_need_catalog_key($name, $price);

        return [[
            'catalog_key' => $key,
            'name' => $name,
            'qty_needed' => (float)$maxQty,
            'qty_remaining' => (float)$maxQty,
            'price' => $price,
        ]];
    }

    $key = 'remainder|' . number_format((float)$remain, 2, '.', '');
    return [[
        'catalog_key' => $key,
        'name' => 'ยอดสมทบคงเหลือ',
        'qty_needed' => 1.0,
        'qty_remaining' => 1.0,
        'price' => (float)$remain,
    ]];
}

/** @param array<int,array{catalog_key:string,name:string,qty_needed:float,price:float}> $skeleton */
function drawdream_need_pick_total_baht_from_skeleton(array $skeleton, array $fundedQty): float
{
    $byKey = [];
    foreach ($skeleton as $row) {
        $byKey[(string)($row['catalog_key'] ?? '')] = (float)($row['price'] ?? 0);
    }
    $total = 0.0;
    foreach ($fundedQty as $key => $qty) {
        $price = (float)($byKey[(string)$key] ?? 0);
        $qty = (float)$qty;
        if ($price > 0 && $qty > 0) {
            $total += round($price * $qty, 2);
        }
    }

    return round($total, 2);
}

/**
 * จัดสรร picks จากยอดเงิน (backfill บริจาคเก่าที่ไม่มีรายชิ้นใน donation)
 *
 * @param array<int,array{catalog_key:string,name:string,qty_needed:float,price:float}> $skeleton
 * @return array<string,int>
 */
function drawdream_need_catalog_picks_for_target_baht(array $skeleton, float $targetBaht): array
{
    if ($skeleton === [] || $targetBaht <= 0.009) {
        return [];
    }

    $remaining = $targetBaht;
    $funded = [];
    foreach ($skeleton as $row) {
        $key = (string)($row['catalog_key'] ?? '');
        $price = (float)($row['price'] ?? 0);
        $qtyNeeded = (float)($row['qty_needed'] ?? 0);
        if ($key === '' || $price <= 0 || $qtyNeeded <= 0) {
            continue;
        }
        $already = (float)($funded[$key] ?? 0);
        $room = max(0.0, $qtyNeeded - $already);
        if ($room <= 0 || $remaining <= 0.009) {
            continue;
        }
        $maxAfford = (int)floor($remaining / $price);
        $take = (int)min($room, $maxAfford);
        if ($take <= 0) {
            continue;
        }
        $funded[$key] = $already + $take;
        $remaining -= round($take * $price, 2);
    }

    return array_map(static fn ($q) => (int)$q, array_filter($funded, static fn ($q) => (float)$q > 0));
}

/**
 * @param array<int,array{catalog_key:string,name:string,qty_needed:float,qty_remaining:float,price:float}> $catalog
 * @param array<string,int> $picks
 */
function drawdream_need_validate_picks_against_catalog(array $catalog, array $picks): ?string
{
    if ($picks === []) {
        return 'กรุณาเลือกสิ่งของอย่างน้อย 1 รายการ';
    }
    $byKey = [];
    foreach ($catalog as $row) {
        $byKey[(string)($row['catalog_key'] ?? '')] = $row;
    }
    $total = 0.0;
    foreach ($picks as $key => $qty) {
        $key = (string)$key;
        $qty = (int)max(0, (int)$qty);
        if ($qty <= 0) {
            continue;
        }
        if (!isset($byKey[$key])) {
            return 'รายการสิ่งของที่เลือกไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่';
        }
        $maxQty = (int)max(0, (int)floor((float)($byKey[$key]['qty_remaining'] ?? 0)));
        if ($qty > $maxQty) {
            $name = (string)($byKey[$key]['name'] ?? 'รายการ');
            return 'จำนวน "' . $name . '" เกินที่เหลือ (' . number_format($maxQty, 0) . ' ชิ้น)';
        }
        $price = (float)($byKey[$key]['price'] ?? 0);
        $total += round($price * $qty, 2);
    }
    if ($total < 20) {
        return 'ยอดรวมจากสิ่งของที่เลือกต้องไม่ต่ำกว่า 20 บาท';
    }

    return null;
}

/** @param array<string,int> $picks */
function drawdream_need_pick_total_baht_from_catalog(array $catalog, array $picks): float
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
        $qty = (int)max(0, (int)$qty);
        if ($price <= 0 || $qty <= 0) {
            continue;
        }
        $total += round($price * $qty, 2);
    }

    return round($total, 2);
}
