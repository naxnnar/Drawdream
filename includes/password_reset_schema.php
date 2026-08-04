<?php
declare(strict_types=1);

require_once __DIR__ . '/drawdream_schema_once.php';

function drawdream_password_reset_ensure_schema(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    @$conn->query("
        CREATE TABLE IF NOT EXISTS password_reset_token (
            token_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (token_id),
            KEY idx_password_reset_token_hash (token_hash),
            KEY idx_password_reset_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
