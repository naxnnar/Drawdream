<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array_merge(
    glob($root . '/foundation*.php') ?: [],
    [
        $root . '/includes/foundation_dashboard_donations_load.php',
        $root . '/includes/foundation_dashboard_ops.php',
    ]
);

$issues = [];
foreach ($files as $path) {
    if (!is_file($path)) {
        continue;
    }
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $b = file_get_contents($path);
    if ($b === false) {
        continue;
    }
    if (str_starts_with($b, "\xEF\xBB\xBF")) {
        $issues[] = "$rel: UTF-8 BOM";
        $b = substr($b, 3);
        file_put_contents($path, $b);
    }
    $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if ($lint !== null && !str_contains($lint, 'No syntax errors')) {
        $issues[] = "$rel: " . trim($lint);
    }
}

if ($issues === []) {
    echo "OK: " . count($files) . " foundation files checked\n";
    exit(0);
}

foreach ($issues as $line) {
    echo $line . "\n";
}
exit(1);
