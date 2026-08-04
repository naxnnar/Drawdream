<?php
declare(strict_types=1);

/** @deprecated ใช้ tools/run_migrations.php */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}
passthru('php ' . escapeshellarg(dirname(__DIR__) . '/tools/run_migrations.php'), $code);
exit((int)$code);
