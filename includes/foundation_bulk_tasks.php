<?php
declare(strict_types=1);

require_once __DIR__ . '/child_sponsorship.php';
require_once __DIR__ . '/drawdream_project_service_charge.php';

/**
 * โครงการที่พร้อมอัปเดตผลลัพธ์แต่ยังไม่โพสต์ในเดือนนี้
 *
 * @return list<array<string, mixed>>
 */
function foundation_bulk_projects_outcome_due(mysqli $conn, int $foundationId, string $foundationName): array
{
    if ($foundationId <= 0) {
        return [];
    }
    $fn = trim($foundationName);
    $monthStart = (new DateTimeImmutable('first day of this month midnight'))->format('Y-m-d H:i:s');
    $projScope = '(foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))';
    $st = $conn->prepare(
        "SELECT project_id, project_name, current_donate, goal_amount, project_status, update_text, update_images, update_at, service_charge_paid_at
         FROM foundation_project
         WHERE {$projScope}
           AND LOWER(TRIM(COALESCE(project_status,''))) IN ('purchasing','done')
           AND (
             update_at IS NULL
             OR update_at < ?
             OR COALESCE(TRIM(update_text), '') = ''
           )
         ORDER BY project_name ASC, project_id ASC"
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('iss', $foundationId, $fn, $monthStart);
    $st->execute();

    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * รายการสิ่งของที่จัดส่งแล้ว แต่ยังไม่โพสต์ผลในเดือนนี้
 *
 * @return list<array<string, mixed>>
 */
function foundation_bulk_needlist_outcome_due(mysqli $conn, int $foundationId): array
{
    if ($foundationId <= 0) {
        return [];
    }
    $monthStart = (new DateTimeImmutable('first day of this month midnight'))->format('Y-m-d H:i:s');
    $st = $conn->prepare(
        "SELECT item_id, item_name, current_donate, total_price, approve_item, update_text, update_images, update_at, admin_delivery_at
         FROM foundation_needlist
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_item,''))) = 'done'
           AND (
             update_at IS NULL
             OR update_at < ?
             OR COALESCE(TRIM(update_text), '') = ''
           )
         ORDER BY item_name ASC, item_id ASC"
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('is', $foundationId, $monthStart);
    $st->execute();

    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function foundation_bulk_outcome_upload_ext(string $tmpPath, int $maxBytes): ?string
{
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return null;
    }
    $sz = @filesize($tmpPath);
    if ($sz === false || $sz > $maxBytes || $sz < 32) {
        return null;
    }
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($tmpPath);

        return $map[$mime] ?? null;
    }
    $info = @getimagesize($tmpPath);
    if ($info === false || empty($info[2])) {
        return null;
    }
    $fromGd = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    return $fromGd[(int)$info[2]] ?? null;
}

/**
 * @param array{name?:array<int,string>,type?:array<int,string>,tmp_name?:array<int,string>,error?:array<int,int>,size?:array<int,int>} $fileBucket
 * @return list<string> uploaded basenames
 */
function foundation_bulk_collect_uploaded_images(
    array $fileBucket,
    string $destDir,
    string $namePrefix,
    int $maxTotal,
    int $maxBytes = 4194304
): array {
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }
    $uploaded = [];
    $names = $fileBucket['name'] ?? [];
    $tmps = $fileBucket['tmp_name'] ?? [];
    $errs = $fileBucket['error'] ?? [];
    if (!is_array($names)) {
        return [];
    }
    $n = count($names);
    for ($i = 0; $i < $n; $i++) {
        if (count($uploaded) >= $maxTotal) {
            break;
        }
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = (string)($tmps[$i] ?? '');
        $ext = foundation_bulk_outcome_upload_ext($tmp, $maxBytes);
        if ($ext === null) {
            continue;
        }
        $finalName = $namePrefix . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalName;
        if (move_uploaded_file($tmp, $dest)) {
            $uploaded[] = $finalName;
        }
    }

    return $uploaded;
}
