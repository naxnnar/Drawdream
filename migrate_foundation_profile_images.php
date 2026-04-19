<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$profilesDir = __DIR__ . '/uploads/profiles';
if (!is_dir($profilesDir)) {
    fwrite(STDERR, "profiles directory not found\n");
    exit(1);
}

function mfp_base(string $x): string { return basename(trim($x)); }
function mfp_ext(string $x): string {
    $ext = strtolower((string)pathinfo($x, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : 'jpg';
}
function mfp_hex(int $bytes = 4): string {
    try { return bin2hex(random_bytes($bytes)); } catch (Throwable $e) { return dechex(mt_rand()) . dechex(mt_rand()); }
}
function mfp_move(string $src, string $dst): bool {
    if (!is_file($src)) return false;
    if ($src === $dst) return true;
    if (@rename($src, $dst)) return true;
    if (@copy($src, $dst)) { @unlink($src); return true; }
    return false;
}

$stats = ['rows_scanned' => 0, 'rows_updated' => 0, 'files_renamed' => 0, 'missing_files' => 0];

$hasProfileImage = false;
$chk = $conn->query("SHOW COLUMNS FROM foundation_profile LIKE 'profile_image'");
if ($chk && $chk->num_rows > 0) {
    $hasProfileImage = true;
}

$selectSql = $hasProfileImage
    ? "SELECT foundation_id, foundation_image, profile_image FROM foundation_profile"
    : "SELECT foundation_id, foundation_image FROM foundation_profile";
$rs = $conn->query($selectSql);
if (!($rs instanceof mysqli_result)) {
    fwrite(STDERR, "query failed\n");
    exit(1);
}

echo "Migrating foundation profile filenames...\n";
while ($row = $rs->fetch_assoc()) {
    $stats['rows_scanned']++;
    $fid = (int)($row['foundation_id'] ?? 0);
    $updates = [];

    $cols = ['foundation_image'];
    if ($hasProfileImage) {
        $cols[] = 'profile_image';
    }
    foreach ($cols as $col) {
        $raw = trim((string)($row[$col] ?? ''));
        if ($raw === '') {
            continue;
        }
        $base = mfp_base($raw);
        if ($base === '') {
            continue;
        }
        if (preg_match('/^foundation_/i', $base) === 1) {
            continue;
        }

        $src = $profilesDir . '/' . $base;
        if (!is_file($src)) {
            $stats['missing_files']++;
            continue;
        }

        $newBase = 'foundation_' . $fid . '_' . time() . '_' . mfp_hex(4) . '.' . mfp_ext($base);
        $dst = $profilesDir . '/' . $newBase;
        if (!is_file($dst)) {
            if (!mfp_move($src, $dst)) {
                $stats['missing_files']++;
                continue;
            }
            $stats['files_renamed']++;
        }
        $updates[$col] = $newBase;
    }

    if ($updates !== []) {
        if ($hasProfileImage) {
            $fi = $updates['foundation_image'] ?? (string)($row['foundation_image'] ?? '');
            $pi = $updates['profile_image'] ?? (string)($row['profile_image'] ?? '');
            $up = $conn->prepare("UPDATE foundation_profile SET foundation_image = ?, profile_image = ? WHERE foundation_id = ? LIMIT 1");
            if ($up) {
                $up->bind_param('ssi', $fi, $pi, $fid);
                $up->execute();
                $up->close();
                $stats['rows_updated']++;
            }
        } else {
            $fi = $updates['foundation_image'] ?? (string)($row['foundation_image'] ?? '');
            $up = $conn->prepare("UPDATE foundation_profile SET foundation_image = ? WHERE foundation_id = ? LIMIT 1");
            if ($up) {
                $up->bind_param('si', $fi, $fid);
                $up->execute();
                $up->close();
                $stats['rows_updated']++;
            }
        }
    }
}
$rs->free();

echo "Done.\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 14, ' ') . ": {$v}\n";
}

