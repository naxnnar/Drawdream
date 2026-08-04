<?php
declare(strict_types=1);
/**
 * รัน schema migration ทั้งหมด (CLI เท่านั้น — ไม่รันตอนเปิดหน้าเว็บ)
 *
 * Usage:
 *   php tools/run_migrations.php
 *   php tools/run_migrations.php --dry-run
 *   php tools/run_migrations.php --force
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('DRAWDREAM_RUNNING_MIGRATIONS', true);
define('DRAWDREAM_DB_LIGHT', true);

$dryRun = in_array('--dry-run', $argv ?? [], true);
$force = in_array('--force', $argv ?? [], true);

require dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/drawdream_migrations.php';

$result = drawdream_run_all_migrations($conn, $dryRun, $force);

foreach ($result['steps'] as $step) {
    echo $step . PHP_EOL;
}

$ok = $dryRun
    || drawdream_migration_cached_version() >= drawdream_migration_version();
echo 'migration_version=' . (int)$result['version'] . ' ran=' . ($result['ran'] ? 'yes' : 'no') . PHP_EOL;
exit($ok ? 0 : 1);
