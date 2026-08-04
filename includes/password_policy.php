<?php
declare(strict_types=1);

require_once __DIR__ . '/utf8_helpers.php';

const DRAWDREAM_PASSWORD_MIN_LENGTH = 6;
const DRAWDREAM_PASSWORD_MAX_LENGTH = 10;

function drawdream_password_meets_policy(string $password): bool
{
    $len = drawdream_utf8_strlen($password);
    return $len >= DRAWDREAM_PASSWORD_MIN_LENGTH && $len <= DRAWDREAM_PASSWORD_MAX_LENGTH;
}

function drawdream_password_policy_message(): string
{
    return 'รหัสผ่านต้องมี ' . DRAWDREAM_PASSWORD_MIN_LENGTH . '–' . DRAWDREAM_PASSWORD_MAX_LENGTH . ' ตัวอักษร';
}

function drawdream_password_input_minlength(): int
{
    return DRAWDREAM_PASSWORD_MIN_LENGTH;
}

function drawdream_password_input_maxlength(): int
{
    return DRAWDREAM_PASSWORD_MAX_LENGTH;
}

function drawdream_password_input_attrs(): string
{
    return 'minlength="' . DRAWDREAM_PASSWORD_MIN_LENGTH . '" maxlength="' . DRAWDREAM_PASSWORD_MAX_LENGTH . '"';
}

function drawdream_password_placeholder(string $label = 'รหัสผ่าน'): string
{
    return $label . ' (' . DRAWDREAM_PASSWORD_MIN_LENGTH . '–' . DRAWDREAM_PASSWORD_MAX_LENGTH . ' ตัวอักษร)';
}
