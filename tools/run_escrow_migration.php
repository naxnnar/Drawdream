<?php
// tools/run_escrow_migration.php — รัน migration escrow_funds (drop legacy columns)
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/escrow_funds_schema.php';

drawdream_escrow_funds_run_migrations($conn);

$chk = @$conn->query("SHOW COLUMNS FROM escrow_funds LIKE 'omise_charge_id'");
$has = ($chk && $chk->num_rows > 0) ? 'yes' : 'no';
echo "omise_charge_id column present: {$has}\n";
