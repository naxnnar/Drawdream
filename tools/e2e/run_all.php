<?php
declare(strict_types=1);
/**
 * E2E entry — default: static + audit (ปลอดภัยบน production)
 *
 * Usage:
 *   php tools/e2e/run_all.php --static
 *   php tools/e2e/run_all.php --audit
 *   php tools/e2e/run_all.php --http     (non-prod + DRAWDREAM_E2E_ALLOW_HTTP=1)
 *   php tools/e2e/run_all.php --all      (static + audit + http if allowed)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

require __DIR__ . '/bootstrap.php';

$argv = $argv ?? [];
$runStatic = in_array('--static', $argv, true) || in_array('--all', $argv, true) || $argv === ['tools/e2e/run_all.php'] || count($argv) <= 1;
$runAudit = in_array('--audit', $argv, true) || in_array('--all', $argv, true) || count($argv) <= 1;
$runHttp = in_array('--http', $argv, true) || in_array('--all', $argv, true);

if (!$runStatic && !$runAudit && !$runHttp) {
    $runStatic = true;
    $runAudit = true;
}

$failed = 0;

if ($runStatic) {
    passthru('php ' . escapeshellarg(__DIR__ . '/static_suite.php'), $code);
    if ($code !== 0) {
        $failed++;
    }
}

if ($runAudit) {
    passthru('php ' . escapeshellarg(dirname(__DIR__) . '/audit_schema.php'), $code);
    if ($code !== 0) {
        $failed++;
    }
}

if ($runHttp) {
    if (e2e_http_allowed()) {
        passthru('php ' . escapeshellarg(__DIR__ . '/register_suite.php'), $code);
        if ($code !== 0) {
            $failed++;
        }
    } else {
        e2e_skip('HTTP suites disabled on production (use staging + DRAWDREAM_E2E_ALLOW_HTTP=1)');
    }
}

exit($failed > 0 ? 1 : 0);
