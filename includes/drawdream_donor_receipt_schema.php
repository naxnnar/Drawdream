<?php
// includes/drawdream_donor_receipt_schema.php — คอลัมน์ใบเสร็จนิติบุคคลบน donor
declare(strict_types=1);

require_once __DIR__ . '/drawdream_schema_once.php';

function drawdream_ensure_donor_receipt_columns(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    $cols = [
        'receipt_type' => "VARCHAR(20) NOT NULL DEFAULT 'individual'",
        'receipt_email' => 'VARCHAR(191) NULL DEFAULT NULL',
        'receipt_mobile' => 'VARCHAR(20) NULL DEFAULT NULL',
        'receipt_company_name' => 'VARCHAR(255) NULL DEFAULT NULL',
        'receipt_company_tax_id' => 'VARCHAR(32) NULL DEFAULT NULL',
        'receipt_company_address' => 'TEXT NULL',
        'receipt_company_email' => 'VARCHAR(191) NULL DEFAULT NULL',
        'receipt_company_phone' => 'VARCHAR(20) NULL DEFAULT NULL',
    ];
    foreach ($cols as $name => $def) {
        $chk = @$conn->query("SHOW COLUMNS FROM donor LIKE '" . $conn->real_escape_string($name) . "'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE donor ADD COLUMN `{$name}` {$def}");
        }
    }
}

function drawdream_donor_receipt_sql_selects(): array
{
    return [
        'tax' => 'dn.receipt_company_tax_id',
        'name' => 'dn.receipt_company_name',
    ];
}

/** @return array{receipt_type: bool, receipt_company_name: bool, receipt_company_tax_id: bool, receipt_company_address: bool, phone: bool} */
function drawdream_donor_receipt_column_flags(mysqli $conn): array
{
    static $flags = null;
    if (is_array($flags)) {
        return $flags;
    }
    $cols = ['receipt_type', 'receipt_company_name', 'receipt_company_tax_id', 'receipt_company_address', 'phone'];
    $flags = [];
    foreach ($cols as $col) {
        $flags[$col] = false;
        $chk = @$conn->query("SHOW COLUMNS FROM donor LIKE '" . $conn->real_escape_string($col) . "'");
        if ($chk && $chk->num_rows > 0) {
            $flags[$col] = true;
        }
    }
    return $flags;
}
