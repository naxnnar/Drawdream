<?php

// includes/drawdream_soft_delete.php — ลบถาวรโปรไฟล์เด็ก/โครงการ (ไม่ใช้ soft delete)
declare(strict_types=1);

require_once __DIR__ . '/drawdream_schema_once.php';

/**
 * ย้ายจาก soft delete: ลบแถวที่เคยซ่อนไว้ แล้ว DROP คอลัมน์ deleted_at / project_delete_reason
 * เรียกจาก tools/run_migrations.php
 */
function drawdream_migrate_remove_soft_delete_columns(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    if (drawdream_table_has_deleted_at_column($conn, 'foundation_children')) {
        $rs = $conn->query('SELECT child_id, foundation_id, photo_child, update_images FROM foundation_children WHERE deleted_at IS NOT NULL');
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $cid = (int)($row['child_id'] ?? 0);
                $fid = (int)($row['foundation_id'] ?? 0);
                if ($cid > 0 && $fid > 0) {
                    drawdream_hard_delete_child($conn, $fid, $cid, $row, false);
                }
            }
        }
        @$conn->query('ALTER TABLE foundation_children DROP COLUMN deleted_at');
    }

    if (drawdream_table_has_deleted_at_column($conn, 'foundation_project')) {
        $rs = $conn->query(
            "SELECT project_id, foundation_name, project_image, update_images
             FROM foundation_project
             WHERE deleted_at IS NOT NULL"
        );
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $pid = (int)($row['project_id'] ?? 0);
                $fname = trim((string)($row['foundation_name'] ?? ''));
                if ($pid > 0 && $fname !== '') {
                    drawdream_hard_delete_project($conn, $pid, $fname, false);
                }
            }
        }
        @$conn->query('ALTER TABLE foundation_project DROP COLUMN project_delete_reason');
        @$conn->query('ALTER TABLE foundation_project DROP COLUMN deleted_at');
    }
}

function drawdream_table_has_deleted_at_column(mysqli $conn, string $table): bool
{
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safe === '') {
        return false;
    }
    $chk = $conn->query("SHOW COLUMNS FROM `{$safe}` LIKE 'deleted_at'");

    return $chk !== false && $chk->num_rows > 0;
}

/**
 * ลบโปรไฟล์เด็กถาวร (ต้องผ่านเงื่อนไขด้านนอก: ยอด 0, ไม่อุปการะ, ไม่มี subscription)
 *
 * @param array<string,mixed> $childRow แถว foundation_children (หรือเฉพาะฟิลด์ที่จำเป็น)
 */
function drawdream_hard_delete_child(mysqli $conn, int $foundationId, int $childId, array $childRow, bool $purgeRelated = true): bool
{
    if ($foundationId <= 0 || $childId <= 0) {
        return false;
    }

    if (!function_exists('drawdream_purge_child_related_data')) {
        require_once __DIR__ . '/child_sponsorship.php';
    }

    if ($purgeRelated) {
        drawdream_purge_child_related_data($conn, $childId);

        $sh = $conn->prepare('DELETE FROM child_subscription_history WHERE child_id = ?');
        if ($sh) {
            $sh->bind_param('i', $childId);
            $sh->execute();
        }
    }

    $outcomeImages = [];
    if (function_exists('drawdream_child_outcome_images_parse')) {
        $outcomeImages = drawdream_child_outcome_images_parse((string)($childRow['update_images'] ?? ''));
    } elseif (is_string($childRow['update_images'] ?? null) && trim((string)$childRow['update_images']) !== '') {
        $decoded = json_decode((string)$childRow['update_images'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $img) {
                $b = basename((string)$img);
                if ($b !== '') {
                    $outcomeImages[] = $b;
                }
            }
        }
    }

    foreach ($outcomeImages as $basename) {
        foreach (['uploads/evidence/', 'uploads/childern/'] as $relDir) {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir) . $basename;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    if (function_exists('drawdream_delete_child_upload_files')) {
        drawdream_delete_child_upload_files(
            isset($childRow['photo_child']) ? (string)$childRow['photo_child'] : null,
            null
        );
    }

    $del = $conn->prepare('DELETE FROM foundation_children WHERE foundation_id = ? AND child_id = ? LIMIT 1');
    if (!$del) {
        return false;
    }
    $del->bind_param('ii', $foundationId, $childId);

    return $del->execute() && $del->affected_rows >= 1;
}

/**
 * ลบโครงการถาวร (เฉพาะ pending / rejected ตรวจจากด้านนอก)
 */
function drawdream_hard_delete_project(mysqli $conn, int $projectId, string $foundationName, bool $purgeRelated = true): bool
{
    if ($projectId <= 0 || trim($foundationName) === '') {
        return false;
    }

    $st = $conn->prepare(
        "SELECT project_id, project_status, project_image, update_images
         FROM foundation_project
         WHERE project_id = ? AND foundation_name = ?
         LIMIT 1"
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('is', $projectId, $foundationName);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }

    $status = strtolower(trim((string)($row['project_status'] ?? '')));
    if (!in_array($status, ['pending', 'rejected'], true)) {
        return false;
    }

    if ($purgeRelated) {
        drawdream_purge_project_related_data($conn, $projectId);
    }

    drawdream_delete_project_upload_files(
        (string)($row['project_image'] ?? ''),
        (string)($row['update_images'] ?? '')
    );

    $del = $conn->prepare('DELETE FROM foundation_project WHERE project_id = ? AND foundation_name = ? LIMIT 1');
    if (!$del) {
        return false;
    }
    $del->bind_param('is', $projectId, $foundationName);

    return $del->execute() && $del->affected_rows >= 1;
}

function drawdream_purge_project_related_data(mysqli $conn, int $projectId): void
{
    if ($projectId <= 0) {
        return;
    }

    $dq = $conn->prepare(
        'SELECT d.donate_id FROM donation d
         INNER JOIN donate_category dc ON dc.category_id = d.category_id
         WHERE d.target_id = ? AND TRIM(COALESCE(dc.project_donate, \'\')) NOT IN (\'\', \'-\')'
    );
    if ($dq) {
        $dq->bind_param('i', $projectId);
        $dq->execute();
        $dres = $dq->get_result();
        while ($dr = $dres->fetch_assoc()) {
            $did = (int)($dr['donate_id'] ?? 0);
            if ($did <= 0) {
                continue;
            }
            $dd = $conn->prepare('DELETE FROM donation WHERE donate_id = ?');
            if ($dd) {
                $dd->bind_param('i', $did);
                $dd->execute();
            }
        }
    }

    $adm = $conn->prepare(
        'DELETE FROM `admin`
         WHERE target_id = ?
           AND LOWER(TRIM(COALESCE(target_entity, \'\'))) IN (\'project\', \'foundation_project\')'
    );
    if ($adm) {
        $adm->bind_param('i', $projectId);
        $adm->execute();
    }

    $chk = @$conn->query("SHOW TABLES LIKE 'project_updates'");
    if ($chk && $chk->num_rows > 0) {
        $pu = $conn->prepare('DELETE FROM project_updates WHERE project_id = ?');
        if ($pu) {
            $pu->bind_param('i', $projectId);
            $pu->execute();
        }
    }

    $idStr = (string)(int)$projectId;
    $notifExact = [
        'project.php?id=' . $idStr,
        'payment/payment_project.php?project_id=' . $idStr,
        'payment.php?project_id=' . $idStr,
        'foundation_add_project.php?edit=' . $idStr,
    ];
    foreach ($notifExact as $link) {
        $nf = $conn->prepare('DELETE FROM notifications WHERE link = ?');
        if ($nf) {
            $nf->bind_param('s', $link);
            $nf->execute();
        }
    }

    $notifLike = [
        'project.php?id=' . $idStr . '&%',
        '%/project.php?id=' . $idStr . '&%',
        '%/project.php?id=' . $idStr,
        'payment/payment_project.php?project_id=' . $idStr . '&%',
        '%/payment/payment_project.php?project_id=' . $idStr . '&%',
        '%/payment/payment_project.php?project_id=' . $idStr,
        '%check_project_payment.php%&project_id=' . $idStr . '&%',
        '%check_project_payment.php%&project_id=' . $idStr,
        'payment.php?project_id=' . $idStr . '&%',
        '%/payment.php?project_id=' . $idStr . '&%',
        '%/payment.php?project_id=' . $idStr,
    ];
    foreach ($notifLike as $pat) {
        $nf = $conn->prepare('DELETE FROM notifications WHERE link LIKE ?');
        if ($nf) {
            $nf->bind_param('s', $pat);
            $nf->execute();
        }
    }
}

function drawdream_delete_project_upload_files(?string $projectImage, ?string $updateImagesJson): void
{
    $root = dirname(__DIR__);
    $rawImage = trim((string)$projectImage);
    if ($rawImage !== '') {
        if (function_exists('drawdream_project_image_storage_path')) {
            $rel = drawdream_project_image_storage_path($rawImage);
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($path)) {
                @unlink($path);
            }
        } else {
            $basename = basename($rawImage);
            foreach (['uploads/project/', 'uploads/'] as $relDir) {
                $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir) . $basename;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    $images = [];
    if (function_exists('drawdream_child_outcome_images_parse')) {
        $images = drawdream_child_outcome_images_parse($updateImagesJson);
    } else {
        $decoded = json_decode(trim((string)$updateImagesJson), true);
        if (is_array($decoded)) {
            foreach ($decoded as $img) {
                $b = basename((string)$img);
                if ($b !== '') {
                    $images[] = $b;
                }
            }
        }
    }

    foreach ($images as $basename) {
        $path = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'evidence' . DIRECTORY_SEPARATOR . $basename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/** @deprecated ใช้ drawdream_migrate_remove_soft_delete_columns แทน */
function drawdream_ensure_soft_delete_columns(mysqli $conn): void
{
    drawdream_migrate_remove_soft_delete_columns($conn);
}
