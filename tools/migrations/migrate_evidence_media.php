<?php
declare(strict_types=1);

// Migrate legacy update images to uploads/evidence with clear prefixes:
// - project_updates.update_image      => project_<project_id>_...
// - foundation_children outcome imgs => children_<child_id>_...
//
// รันครั้งเดียวด้วยมือ: php tools/migrations/migrate_evidence_media.php (จาก root โปรเจกต์)

$root = dirname(__DIR__, 2);
require_once $root . '/db.php';

mysqli_report(MYSQLI_REPORT_OFF);

$dirEvidence = $root . '/uploads/evidence';
$dirUpdatesLegacy = $root . '/uploads/updates';
$dirChildrenOutcomeLegacy = $root . '/uploads/childern';

if (!is_dir($dirEvidence) && !@mkdir($dirEvidence, 0755, true) && !is_dir($dirEvidence)) {
    fwrite(STDERR, "Cannot create evidence directory: {$dirEvidence}\n");
    exit(1);
}

function dd_random_hex(int $bytes = 4): string
{
    try {
        return bin2hex(random_bytes($bytes));
    } catch (Throwable $e) {
        return dechex(mt_rand()) . dechex(mt_rand());
    }
}

function dd_safe_basename(string $x): string
{
    return basename(trim($x));
}

function dd_file_ext(string $filename): string
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : 'jpg';
}

function dd_move_file(string $src, string $dst): bool
{
    if ($src === $dst) {
        return true;
    }
    if (!is_file($src)) {
        return false;
    }
    if (@rename($src, $dst)) {
        return true;
    }
    if (@copy($src, $dst)) {
        @unlink($src);
        return true;
    }
    return false;
}

function dd_find_existing_source(string $basename, array $dirs): ?string
{
    $b = dd_safe_basename($basename);
    if ($b === '') {
        return null;
    }
    foreach ($dirs as $d) {
        $p = rtrim($d, '/\\') . DIRECTORY_SEPARATOR . $b;
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

$stats = [
    'project_rows_scanned' => 0,
    'project_rows_updated' => 0,
    'project_files_moved' => 0,
    'project_missing_files' => 0,
    'children_rows_scanned' => 0,
    'children_rows_updated' => 0,
    'children_files_moved' => 0,
    'children_missing_files' => 0,
];

echo "Starting migration...\n";

// 1) project_updates.update_image -> uploads/evidence/project_*
$q1 = $conn->query("SELECT update_id, project_id, update_image FROM project_updates");
if ($q1 instanceof mysqli_result) {
    while ($row = $q1->fetch_assoc()) {
        $stats['project_rows_scanned']++;
        $updateId = (int)($row['update_id'] ?? 0);
        $projectId = (int)($row['project_id'] ?? 0);
        $raw = (string)($row['update_image'] ?? '');
        $base = dd_safe_basename($raw);
        if ($base === '') {
            continue;
        }

        $ext = dd_file_ext($base);
        $newBase = preg_match('/^project_\d+_/i', $base) ? $base : ('project_' . $projectId . '_' . time() . '_' . dd_random_hex(4) . '.' . $ext);

        $src = dd_find_existing_source($base, [$dirEvidence, $dirUpdatesLegacy]);
        if ($src === null) {
            $stats['project_missing_files']++;
            continue;
        }

        $dst = $dirEvidence . DIRECTORY_SEPARATOR . $newBase;
        if (!is_file($dst)) {
            if (!dd_move_file($src, $dst)) {
                $stats['project_missing_files']++;
                continue;
            }
            $stats['project_files_moved']++;
        }

        if ($newBase !== $base || str_contains(str_replace('\\', '/', $raw), 'updates/')) {
            $up = $conn->prepare("UPDATE project_updates SET update_image = ? WHERE update_id = ? LIMIT 1");
            if ($up) {
                $up->bind_param('si', $newBase, $updateId);
                $up->execute();
                if ($up->affected_rows >= 0) {
                    $stats['project_rows_updated']++;
                }
                $up->close();
            }
        }
    }
    $q1->free();
}

// 2) foundation_children.update_images -> uploads/evidence/children_*
$q2 = $conn->query("SELECT child_id, update_images FROM foundation_children");
if ($q2 instanceof mysqli_result) {
    while ($row = $q2->fetch_assoc()) {
        $stats['children_rows_scanned']++;
        $childId = (int)($row['child_id'] ?? 0);
        $raw = trim((string)($row['update_images'] ?? ''));
        if ($raw === '' || $raw === '[]') {
            continue;
        }

        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            continue;
        }

        $newList = [];
        $changed = false;

        foreach ($arr as $idx => $item) {
            $base = dd_safe_basename((string)$item);
            if ($base === '') {
                $changed = true;
                continue;
            }

            $ext = dd_file_ext($base);
            $newBase = preg_match('/^children_\d+_/i', $base)
                ? $base
                : ('children_' . $childId . '_' . time() . '_' . $idx . '_' . dd_random_hex(4) . '.' . $ext);

            $src = dd_find_existing_source($base, [$dirEvidence, $dirChildrenOutcomeLegacy]);
            if ($src === null) {
                $stats['children_missing_files']++;
                continue;
            }

            $dst = $dirEvidence . DIRECTORY_SEPARATOR . $newBase;
            if (!is_file($dst)) {
                if (!dd_move_file($src, $dst)) {
                    $stats['children_missing_files']++;
                    continue;
                }
                $stats['children_files_moved']++;
            }

            if ($newBase !== $base) {
                $changed = true;
            }
            $newList[] = $newBase;
        }

        $newList = array_values(array_unique($newList));
        $newJson = json_encode($newList, JSON_UNESCAPED_UNICODE);
        if ($newJson === false) {
            continue;
        }

        if ($changed || $newJson !== $raw) {
            $up = $conn->prepare("UPDATE foundation_children SET update_images = ? WHERE child_id = ? LIMIT 1");
            if ($up) {
                $up->bind_param('si', $newJson, $childId);
                $up->execute();
                if ($up->affected_rows >= 0) {
                    $stats['children_rows_updated']++;
                }
                $up->close();
            }
        }
    }
    $q2->free();
}

echo "Migration finished.\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 24, ' ') . ": {$v}\n";
}
