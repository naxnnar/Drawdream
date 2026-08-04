<?php
/**
 * CLI: ลบ donation ที่ไม่ใช่การบริจาคสำเร็จ (pending/cancelled ค้าง ไม่มี transfer_datetime)
 *
 * Usage:
 *   php tools/cleanup_stale_donation_pending.php           # แสดงจำนวนอย่างเดียว
 *   php tools/cleanup_stale_donation_pending.php --execute   # ลบจริง
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('DRAWDREAM_DB_LIGHT', true);

$root = dirname(__DIR__);
require_once $root . '/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$execute = in_array('--execute', $_SERVER['argv'] ?? [], true);

$where = "
    LOWER(TRIM(COALESCE(payment_status, ''))) IN ('pending', 'cancelled')
    AND transfer_datetime IS NULL
";

$countSql = "SELECT COUNT(*) AS c FROM donation WHERE $where";
$countRow = $conn->query($countSql)?->fetch_assoc();
$total = (int)($countRow['c'] ?? 0);

echo "Stale donation rows (pending/cancelled, no transfer_datetime): $total\n";

if ($total > 0) {
    $sample = $conn->query(
        "SELECT donate_id, donor_id, amount, payment_status, omise_charge_id, donate_type
         FROM donation WHERE $where ORDER BY donate_id DESC LIMIT 8"
    );
    if ($sample) {
        echo "Sample (latest up to 8):\n";
        while ($r = $sample->fetch_assoc()) {
            echo sprintf(
                "  #%d donor=%s amount=%s status=%s charge=%s type=%s\n",
                (int)$r['donate_id'],
                (string)($r['donor_id'] ?? ''),
                (string)($r['amount'] ?? ''),
                (string)($r['payment_status'] ?? ''),
                (string)($r['omise_charge_id'] ?? ''),
                (string)($r['donate_type'] ?? '')
            );
        }
    }
}

if (!$execute) {
    echo "\nDry run only. To delete: php tools/cleanup_stale_donation_pending.php --execute\n";
    exit(0);
}

if ($total === 0) {
    echo "Nothing to delete.\n";
    exit(0);
}

$del = $conn->query("DELETE FROM donation WHERE $where");
if (!$del) {
    fwrite(STDERR, 'DELETE failed: ' . $conn->error . "\n");
    exit(1);
}

$deleted = (int)$conn->affected_rows;
echo "\nDeleted $deleted row(s).\n";

$remain = (int)($conn->query($countSql)?->fetch_assoc()['c'] ?? 0);
echo "Remaining stale rows: $remain\n";

exit(0);
