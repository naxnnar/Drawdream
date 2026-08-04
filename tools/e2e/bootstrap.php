<?php
declare(strict_types=1);

function e2e_is_production_env(): bool
{
    $env = strtolower(trim((string)(getenv('APP_ENV') ?: '')));

    return in_array($env, ['production', 'prod'], true);
}

function e2e_http_allowed(): bool
{
    if (e2e_is_production_env()) {
        return false;
    }

    return getenv('DRAWDREAM_E2E_ALLOW_HTTP') === '1';
}

function e2e_omise_allowed(): bool
{
    if (e2e_is_production_env()) {
        return false;
    }
    $secret = (string)(getenv('OMISE_SECRET_KEY') ?: '');

    return getenv('DRAWDREAM_E2E_ALLOW_OMISE') === '1'
        && str_starts_with($secret, 'skey_test_');
}

function e2e_ok(bool $cond, string $label): bool
{
    global $e2e_fail, $e2e_pass;
    if ($cond) {
        echo "[PASS] {$label}\n";
        $e2e_pass++;
        return true;
    }
    echo "[FAIL] {$label}\n";
    $e2e_fail++;
    return false;
}

function e2e_skip(string $label): void
{
    echo "[SKIP] {$label}\n";
}
