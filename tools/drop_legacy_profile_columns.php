<?php
declare(strict_types=1);
/**
 * ลบคอลัมน์ legacy ใน foundation_profile ที่โค้ดไม่อ้างอิงแล้ว
 * Usage: php tools/drop_legacy_profile_columns.php [--apply]
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
drawdream_load_env_file($root . '/.env');

$dbLocalFile = $root . '/config/db.local.php';
if (is_file($dbLocalFile)) {
    /** @var array{host:string,port?:int,user:string,password:string,database:string} $dbConfig */
    $dbConfig = require $dbLocalFile;
} else {
    $envPass = getenv('DB_PASSWORD') ?: getenv('AIVEN_PASSWORD');
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: (getenv('AIVEN_HOST') ?: 'localhost'),
        'port' => (int)(getenv('DB_PORT') ?: (getenv('AIVEN_PORT') ?: 3306)),
        'user' => getenv('DB_USER') ?: (getenv('AIVEN_USER') ?: 'root'),
        'password' => $envPass !== false && $envPass !== '' ? (string)$envPass : '',
        'database' => getenv('DB_NAME') ?: (getenv('AIVEN_DB') ?: 'defaultdb'),
    ];
}

$host = (string)($dbConfig['host'] ?? 'localhost');
$port = (int)($dbConfig['port'] ?? 3306);
$user = (string)($dbConfig['user'] ?? 'root');
$password = (string)($dbConfig['password'] ?? '');
$database = (string)($dbConfig['database'] ?? 'drawdream_db');

if (!function_exists('mysqli_init')) {
    fwrite(STDERR, "mysqli extension required\n");
    exit(1);
}

$connInit = mysqli_init();
if (!$connInit) {
    fwrite(STDERR, "Cannot init MySQL\n");
    exit(1);
}

$sslCa = trim((string)(getenv('DB_SSL_CA') !== false ? getenv('DB_SSL_CA') : ''));
$sslMode = strtolower(trim((string)(getenv('DB_SSL_MODE') !== false ? getenv('DB_SSL_MODE') : 'require')));
$useSsl = ($sslMode !== 'disable') || str_contains($host, 'aivencloud.com');
$sslFlags = 0;
if ($useSsl) {
    if ($sslCa !== '' && is_file($sslCa)) {
        mysqli_ssl_set($connInit, null, null, $sslCa, null, null);
    } elseif (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
        $sslFlags = MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }
}

if (!@mysqli_real_connect($connInit, $host, $user, $password, $database, $port, null, $sslFlags)) {
    fwrite(STDERR, 'DB connect failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
$conn = $connInit;
mysqli_set_charset($conn, 'utf8mb4');

/** @var list<string> */
$columns = [
    'phone_secondary',
    'needlist_result_text',
    'reviewed_at',
    'verified_by',
];

echo "=== Drop legacy foundation_profile columns ===\n";
echo "DB: {$database} @ {$host}\n\n";

$dropped = 0;
foreach ($columns as $col) {
    $chk = @$conn->query(
        "SHOW COLUMNS FROM foundation_profile LIKE '" . $conn->real_escape_string($col) . "'"
    );
    $exists = $chk && $chk->num_rows > 0;
    if (!$exists) {
        echo "  skip {$col} (not present)\n";
        continue;
    }
    $sql = "ALTER TABLE foundation_profile DROP COLUMN `{$col}`";
    echo "  drop {$col}\n";
    echo "    SQL: {$sql};\n";
    if ($apply) {
        if (@$conn->query($sql)) {
            $dropped++;
        } else {
            fwrite(STDERR, "    FAILED: " . $conn->error . "\n");
        }
    }
}

echo "\n";
if (!$apply) {
    echo "DRY RUN — add --apply to execute\n";
} else {
    echo "Dropped {$dropped} column(s).\n";
}

exit(0);
