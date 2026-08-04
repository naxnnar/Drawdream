<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['id'] = (string)($argv[1] ?? 1);
$t0 = microtime(true);
ob_start();
try {
    include __DIR__ . '/../foundation_need_view.php';
    $out = ob_get_clean();
    echo 'OK bytes=' . strlen($out) . ' time=' . round(microtime(true) - $t0, 3) . "s\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo 'ERR: ' . $e->getMessage() . "\n";
    exit(1);
}
