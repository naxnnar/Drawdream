<?php
declare(strict_types=1);

// สรุปสั้น: ไฟล์นี้ใช้ย้ายหรือปรับโครงสร้างข้อมูลส่วน project album
require_once __DIR__ . '/db.php';

$uploadsRoot = __DIR__ . '/uploads';
$projectDir = $uploadsRoot . '/project';
if (!is_dir($projectDir) && !@mkdir($projectDir, 0755, true) && !is_dir($projectDir)) {
    fwrite(STDERR, "Cannot create directory: {$projectDir}\n");
    exit(1);
}

function ddp_base(string $x): string { return basename(trim($x)); }
function ddp_ext(string $x): string {
    $ext = strtolower((string)pathinfo($x, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : 'jpg';
}
function ddp_hex(int $bytes = 4): string {
    try { return bin2hex(random_bytes($bytes)); } catch (Throwable $e) { return dechex(mt_rand()) . dechex(mt_rand()); }
}
function ddp_move(string $src, string $dst): bool {
    if ($src === $dst) return true;
    if (!is_file($src)) return false;
    if (@rename($src, $dst)) return true;
    if (@copy($src, $dst)) { @unlink($src); return true; }
    return false;
}

$stats = [
    'rows_scanned' => 0,
    'rows_updated' => 0,
    'files_moved' => 0,
    'missing_files' => 0,
];

$rs = $conn->query("SELECT project_id, project_image FROM foundation_project WHERE project_image IS NOT NULL AND TRIM(project_image) <> ''");
if (!($rs instanceof mysqli_result)) {
    fwrite(STDERR, "Query failed.\n");
    exit(1);
}

echo "Migrating project album...\n";

while ($row = $rs->fetch_assoc()) {
    $stats['rows_scanned']++;
    $projectId = (int)($row['project_id'] ?? 0);
    $raw = trim((string)($row['project_image'] ?? ''));
    if ($raw === '') continue;

    $rawNorm = str_replace('\\', '/', $raw);
    $base = ddp_base($rawNorm);
    if ($base === '') continue;

    $oldPath1 = $uploadsRoot . '/' . ltrim($rawNorm, '/');
    $oldPath2 = $uploadsRoot . '/' . $base;
    $src = is_file($oldPath1) ? $oldPath1 : (is_file($oldPath2) ? $oldPath2 : null);
    if ($src === null) {
        $stats['missing_files']++;
        continue;
    }

    $alreadyInProject = str_starts_with(ltrim($rawNorm, '/'), 'project/');
    if ($alreadyInProject && is_file($projectDir . '/' . $base)) {
        continue;
    }

    $newBase = preg_match('/^project_\d+_/i', $base)
        ? $base
        : ('project_' . $projectId . '_' . time() . '_' . ddp_hex(4) . '.' . ddp_ext($base));
    $dst = $projectDir . '/' . $newBase;
    if (!is_file($dst)) {
        if (!ddp_move($src, $dst)) {
            $stats['missing_files']++;
            continue;
        }
        $stats['files_moved']++;
    }

    $newDbValue = 'project/' . $newBase;
    if ($newDbValue !== $raw) {
        $up = $conn->prepare("UPDATE foundation_project SET project_image = ? WHERE project_id = ? LIMIT 1");
        if ($up) {
            $up->bind_param('si', $newDbValue, $projectId);
            $up->execute();
            $stats['rows_updated']++;
            $up->close();
        }
    }
}

$rs->free();
echo "Done.\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 14, ' ') . ": {$v}\n";
}

