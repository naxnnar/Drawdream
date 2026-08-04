<?php
// includes/drawdream_needlist_catalog_funded.php — qty ที่ระดมแล้วจาก donation completed (ไม่จองตอน QR)
declare(strict_types=1);

require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/donate_type.php';
require_once __DIR__ . '/drawdream_schema_once.php';

function drawdream_needlist_foundation_current_donate(mysqli $conn, int $foundationId): float
{
    if ($foundationId <= 0) {
        return 0.0;
    }
    require_once __DIR__ . '/needlist_donate_window.php';
    $needOpen = drawdream_needlist_sql_open_for_donation();
    $st = $conn->prepare(
        "SELECT COALESCE(SUM(current_donate), 0) AS current FROM foundation_needlist WHERE foundation_id = ? AND $needOpen"
    );
    if (!$st) {
        return 0.0;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    return (float)($row['current'] ?? 0);
}

function drawdream_donation_has_need_item_picks_column(mysqli $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $chk = @$conn->query("SHOW COLUMNS FROM donation LIKE 'need_item_picks_json'");
    $cached = ($chk && $chk->num_rows > 0);

    return $cached;
}

/**
 * รวม picks จาก donation ที่ชำระสำเร็จแล้วเท่านั้น (ไม่รวม pending)
 *
 * @return array<string, float> catalog_key => qty
 */
function drawdream_need_catalog_funded_from_completed_donations(
    mysqli $conn,
    int $foundationId,
    int $excludeDonateId = 0
): array {
    if ($foundationId <= 0 || !drawdream_donation_has_need_item_picks_column($conn)) {
        return [];
    }

    $categoryId = drawdream_get_or_create_needitem_donate_category_id($conn);
    if ($categoryId <= 0) {
        return [];
    }

    $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
    $completed = 'completed';
    $sql = 'SELECT donate_id, need_item_picks_json FROM donation
            WHERE category_id = ? AND target_id = ? AND payment_status = ?
              AND COALESCE(donate_type, \'\') = ?
              AND need_item_picks_json IS NOT NULL
              AND TRIM(need_item_picks_json) <> \'\'
              AND TRIM(need_item_picks_json) <> \'{}\'';
    if ($excludeDonateId > 0) {
        $sql .= ' AND donate_id <> ?';
    }

    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    if ($excludeDonateId > 0) {
        $st->bind_param('iissi', $categoryId, $foundationId, $completed, $dtNeed, $excludeDonateId);
    } else {
        $st->bind_param('iiss', $categoryId, $foundationId, $completed, $dtNeed);
    }
    $st->execute();
    $res = $st->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $raw = trim((string)($row['need_item_picks_json'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $key => $qty) {
            $key = (string)$key;
            $qty = (int)max(0, (int)$qty);
            if ($key === '' || $qty <= 0) {
                continue;
            }
            $out[$key] = ($out[$key] ?? 0.0) + (float)$qty;
        }
    }

    return $out;
}

/**
 * Migration v10: picks บน completed donation, ย้ายข้อมูลจาก foundation_need_catalog_funded แล้วลบตาราง
 */
function drawdream_needlist_picks_on_completed_migration(mysqli $conn): void
{
    if (!function_exists('drawdream_schema_migrations_allowed')) {
        require_once __DIR__ . '/drawdream_schema_once.php';
    }
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }

    $pickCol = @$conn->query("SHOW COLUMNS FROM donation LIKE 'need_item_picks_json'");
    if (!$pickCol || $pickCol->num_rows === 0) {
        @$conn->query('ALTER TABLE donation ADD COLUMN need_item_picks_json LONGTEXT NULL DEFAULT NULL');
    }

    $tbl = @$conn->query("SHOW TABLES LIKE 'foundation_need_catalog_funded'");
    if ($tbl && $tbl->num_rows > 0) {
        $categoryId = drawdream_get_or_create_needitem_donate_category_id($conn);
        $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
        $completed = 'completed';

        $byFoundation = [];
        $rows = $conn->query('SELECT foundation_id, catalog_key, qty_funded FROM foundation_need_catalog_funded WHERE qty_funded > 0');
        if ($rows) {
            while ($r = $rows->fetch_assoc()) {
                $fid = (int)($r['foundation_id'] ?? 0);
                $key = (string)($r['catalog_key'] ?? '');
                $qty = (float)($r['qty_funded'] ?? 0);
                if ($fid <= 0 || $key === '' || $qty <= 0) {
                    continue;
                }
                $byFoundation[$fid][$key] = ($byFoundation[$fid][$key] ?? 0.0) + $qty;
            }
        }

        foreach ($byFoundation as $fid => $picks) {
            if ($picks === [] || $categoryId <= 0) {
                continue;
            }
            $hasPicks = $conn->prepare(
                'SELECT 1 FROM donation WHERE category_id = ? AND target_id = ? AND payment_status = ?
                   AND COALESCE(donate_type, \'\') = ?
                   AND need_item_picks_json IS NOT NULL AND TRIM(need_item_picks_json) <> \'{}\'
                 LIMIT 1'
            );
            if (!$hasPicks) {
                continue;
            }
            $hasPicks->bind_param('iiss', $categoryId, $fid, $completed, $dtNeed);
            $hasPicks->execute();
            if ($hasPicks->get_result()->fetch_row()) {
                continue;
            }

            $targetDonateId = 0;
            $find = $conn->prepare(
                'SELECT donate_id FROM donation
                 WHERE category_id = ? AND target_id = ? AND payment_status = ?
                   AND COALESCE(donate_type, \'\') = ?
                 ORDER BY donate_id ASC LIMIT 1'
            );
            if ($find) {
                $find->bind_param('iiss', $categoryId, $fid, $completed, $dtNeed);
                $find->execute();
                $targetDonateId = (int)($find->get_result()->fetch_assoc()['donate_id'] ?? 0);
            }

            $json = json_encode($picks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                $json = '{}';
            }
            if ($targetDonateId > 0) {
                $upd = $conn->prepare(
                    'UPDATE donation SET need_item_picks_json = ? WHERE donate_id = ? AND (need_item_picks_json IS NULL OR TRIM(need_item_picks_json) = \'{}\')'
                );
                if ($upd) {
                    $upd->bind_param('si', $json, $targetDonateId);
                    $upd->execute();
                }
            }
        }

        @$conn->query('DROP TABLE IF EXISTS foundation_need_catalog_funded');
    }
}
