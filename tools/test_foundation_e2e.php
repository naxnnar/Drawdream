<?php
declare(strict_types=1);
/**
 * Foundation UX smoke + pagination + flow guards (CLI).
 * Usage: php tools/test_foundation_e2e.php
 * Optional HTTP: DRAWREAM_E2E_BASE_URL=https://drawdream.org php tools/test_foundation_e2e.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

$root = dirname(__DIR__);

$fail = 0;
$pass = 0;

function e2e_ok(bool $cond, string $label): void
{
    global $fail, $pass;
    if ($cond) {
        echo "[PASS] {$label}\n";
        $pass++;
        return;
    }
    echo "[FAIL] {$label}\n";
    $fail++;
}

// --- Static: pagination wiring (no DB) ---
$dataPhp = (string)@file_get_contents($root . '/foundation_dashboard_data.php');
e2e_ok(str_contains($dataPhp, "mode === 'table'"), 'foundation_dashboard_data.php supports mode=table');
e2e_ok(str_contains($dataPhp, 'foundation_dashboard_load_donation_table_page'), 'data endpoint calls table page loader');

$loadPhp = (string)@file_get_contents($root . '/includes/foundation_dashboard_donations_load.php');
e2e_ok(str_contains($loadPhp, 'foundation_dashboard_load_donation_table_page'), 'donations_load has table page function');
e2e_ok(str_contains($loadPhp, 'LIMIT ? OFFSET ?'), 'donations_load uses LIMIT OFFSET');

$dashJs = (string)@file_get_contents($root . '/js/foundation_dashboard_charts.js');
e2e_ok(str_contains($dashJs, 'drawdreamLoadDashboardTablePage'), 'dashboard JS exposes table page loader');
e2e_ok(str_contains($dashJs, 'mode=charts'), 'dashboard JS requests charts mode separately');
e2e_ok(str_contains($dashJs, 'fdTablePagination'), 'dashboard JS has pagination UI hooks');

$dashPhp = (string)@file_get_contents($root . '/foundation_dashboard.php');
e2e_ok(str_contains($dashPhp, 'fdTablePagination'), 'dashboard page has pagination markup');
e2e_ok(str_contains($dashPhp, "'table_pagination' => true"), 'dashboard static config enables table pagination');

// --- Flow guards (regression anchors) ---
$returnSrc = (string)@file_get_contents($root . '/includes/return_to.php');
foreach ([
    'foundation_dashboard.php',
    'foundation_add_need.php',
    'foundation_need_wizard.php',
] as $path) {
    e2e_ok(str_contains($returnSrc, $path), "return_to allows {$path}");
}

$needAdd = (string)@file_get_contents($root . '/foundation_add_need.php');
e2e_ok(str_contains($needAdd, 'need-checklist'), 'foundation_add_need has wizard checklist');
e2e_ok(is_file($root . '/foundation_need_wizard.php'), 'foundation_need_wizard.php exists');

$verifiedSrc = (string)@file_get_contents($root . '/includes/foundation_account_verified.php');
e2e_ok(str_contains($verifiedSrc, 'drawdream_foundation_require_account_verified'), 'account verified guard exists');

$skipDb = in_array('--static', $argv ?? [], true) || getenv('DRAWDREAM_E2E_SKIP_DB') === '1';
if ($skipDb) {
    echo "[SKIP] DB checks (--static or DRAWDREAM_E2E_SKIP_DB=1)\n";
} else {
require $root . '/db.php';
require_once $root . '/includes/foundation_dashboard_donations_load.php';
require_once $root . '/includes/foundation_account_verified.php';
require_once $root . '/includes/return_to.php';

// --- DB: verified foundation + pagination math ---
$foundationRow = null;
$st = $conn->prepare(
    "SELECT fp.foundation_id, fp.foundation_name, fp.user_id, u.email
     FROM foundation_profile fp
     INNER JOIN `user` u ON u.user_id = fp.user_id
     WHERE fp.account_verified = 1 AND u.role = 'foundation'
     ORDER BY fp.foundation_id ASC
     LIMIT 1"
);
if ($st) {
    $st->execute();
    $foundationRow = $st->get_result()->fetch_assoc() ?: null;
}
e2e_ok($foundationRow !== null, 'at least one verified foundation account in DB');

if ($foundationRow !== null) {
    $foundationId = (int)($foundationRow['foundation_id'] ?? 0);
    $foundationName = trim((string)($foundationRow['foundation_name'] ?? ''));
    $childCat = drawdream_get_or_create_child_donate_category_id($conn);
    $projCat = drawdream_get_or_create_project_donate_category_id($conn);
    $needCat = drawdream_get_or_create_needitem_donate_category_id($conn);
    e2e_ok($childCat > 0 && $projCat > 0 && $needCat > 0, 'donate categories resolvable');

    $childMap = [];
    $stC = $conn->prepare('SELECT child_id, child_name FROM foundation_children WHERE foundation_id = ? LIMIT 5');
    if ($stC) {
        $stC->bind_param('i', $foundationId);
        $stC->execute();
        $rc = $stC->get_result();
        while ($x = $rc->fetch_assoc()) {
            $childMap[(int)$x['child_id']] = (string)($x['child_name'] ?? '');
        }
    }
    $projectMap = [];
    $stP = $conn->prepare(
        "SELECT project_id, project_name FROM foundation_project
         WHERE (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))
         LIMIT 5"
    );
    if ($stP) {
        $stP->bind_param('is', $foundationId, $foundationName);
        $stP->execute();
        $rp = $stP->get_result();
        while ($x = $rp->fetch_assoc()) {
            $projectMap[(int)$x['project_id']] = (string)($x['project_name'] ?? '');
        }
    }

    $page1 = foundation_dashboard_load_donation_table_page(
        $conn,
        $foundationId,
        $foundationName,
        $childCat,
        $projCat,
        $needCat,
        $childMap,
        $projectMap,
        1,
        10,
        []
    );
    $pg1 = $page1['pagination'] ?? [];
    e2e_ok(isset($page1['donations']) && is_array($page1['donations']), 'table page 1 returns donations array');
    e2e_ok((int)($pg1['page'] ?? 0) === 1, 'table page 1 reports page=1');
    e2e_ok((int)($pg1['per_page'] ?? 0) === 10, 'table page 1 reports per_page=10');
    e2e_ok((int)($pg1['total_rows'] ?? -1) >= 0, 'table page 1 reports total_rows');
    e2e_ok((int)($pg1['total_pages'] ?? 0) >= 1, 'table page 1 reports total_pages >= 1');
    e2e_ok(count($page1['donations']) <= 10, 'table page 1 returns at most 10 rows');

    $totalRows = (int)($pg1['total_rows'] ?? 0);
    if ($totalRows > 10) {
        $page2 = foundation_dashboard_load_donation_table_page(
            $conn,
            $foundationId,
            $foundationName,
            $childCat,
            $projCat,
            $needCat,
            $childMap,
            $projectMap,
            2,
            10,
            []
        );
        $pg2 = $page2['pagination'] ?? [];
        e2e_ok((int)($pg2['page'] ?? 0) === 2, 'table page 2 reports page=2');
        e2e_ok(count($page2['donations']) <= 10, 'table page 2 returns at most 10 rows');
        $ids1 = array_column($page1['donations'], 'receipt_ref');
        $ids2 = array_column($page2['donations'], 'receipt_ref');
        $overlap = array_intersect($ids1, $ids2);
        e2e_ok($overlap === [], 'page 1 and page 2 have no overlapping receipt_ref');
    } else {
        echo "[SKIP] pagination overlap check (total_rows <= 10)\n";
    }

    $charts = foundation_dashboard_load_donation_bundle(
        $conn,
        $foundationId,
        $foundationName,
        $childCat,
        $projCat,
        $needCat,
        $childMap,
        $projectMap,
        500
    );
    e2e_ok(isset($charts['week_meta']['keys']) && is_array($charts['week_meta']['keys']), 'charts bundle returns week_meta');
    e2e_ok(count($charts['donations'] ?? []) <= 500, 'charts bundle caps at 500 rows');
}

} // end DB block

// --- Optional HTTP smoke (session login not automated here) ---
$baseUrl = rtrim(trim((string)(getenv('DRAWREAM_E2E_BASE_URL') ?: '')), '/');
if ($baseUrl !== '') {
    $fetch = static function (string $url): array {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => "Accept: text/html,application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers();
            if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string)$headers[0], $m)) {
                $status = (int)$m[1];
            }
        }

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $login = $fetch($baseUrl . '/login.php');
    e2e_ok($login['status'] === 200, 'HTTP login.php reachable');

    $dash = $fetch($baseUrl . '/foundation_dashboard_data.php');
    e2e_ok(in_array($dash['status'], [302, 403], true), 'HTTP dashboard data requires auth (302/403)');

    $foundation = $fetch($baseUrl . '/foundation.php');
    e2e_ok($foundation['status'] === 200, 'HTTP foundation.php reachable');
} else {
    echo "[SKIP] HTTP checks (set DRAWREAM_E2E_BASE_URL to enable)\n";
}

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
