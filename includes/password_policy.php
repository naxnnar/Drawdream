<?php
declare(strict_types=1);

require_once __DIR__ . '/utf8_helpers.php';

const DRAWDREAM_PASSWORD_MIN_LENGTH = 8;

function drawdream_password_meets_policy(string $password): bool
{
    return drawdream_utf8_strlen($password) >= DRAWDREAM_PASSWORD_MIN_LENGTH;
}

function drawdream_password_policy_message(): string
{
    return 'รหัสผ่านต้องมีอย่างน้อย ' . DRAWDREAM_PASSWORD_MIN_LENGTH . ' ตัวอักษร';
}
