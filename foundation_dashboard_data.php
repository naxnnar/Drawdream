<?php

declare(strict_types=1);



define('DRAWDREAM_DB_LIGHT', true);

header('Content-Type: application/json; charset=utf-8');



include 'db.php';

require_once __DIR__ . '/includes/donate_category_resolve.php';

require_once __DIR__ . '/includes/foundation_donor_preview.php';

require_once __DIR__ . '/includes/foundation_account_verified.php';

require_once __DIR__ . '/includes/foundation_dashboard_donations_load.php';
require_once __DIR__ . '/includes/foundation_dashboard_ops.php';
require_once __DIR__ . '/includes/foundation_analytics.php';



drawdream_foundation_require_management_access();

drawdream_foundation_require_account_verified($conn);



$uid = (int)($_SESSION['user_id'] ?? 0);

$stFp = $conn->prepare(

    'SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1'

);

if (!$stFp) {

    http_response_code(500);

    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);

    exit;

}

$stFp->bind_param('i', $uid);

$stFp->execute();

$fp = $stFp->get_result()->fetch_assoc();

$foundationId = (int)($fp['foundation_id'] ?? 0);

$foundationName = trim((string)($fp['foundation_name'] ?? ''));

if ($foundationId <= 0) {

    http_response_code(403);

    echo json_encode(['ok' => false, 'error' => 'foundation'], JSON_UNESCAPED_UNICODE);

    exit;

}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'charts')));

if ($mode === 'bootstrap') {
    require_once __DIR__ . '/includes/foundation_dashboard_bootstrap.php';
    $boot = foundation_dashboard_load_bootstrap($conn, $uid, $foundationId, $foundationName);
    echo json_encode(
        ['ok' => true, 'mode' => 'bootstrap'] + $boot,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    );
    exit;
}

$childCat = drawdream_get_or_create_child_donate_category_id($conn);

$projCat = drawdream_get_or_create_project_donate_category_id($conn);

$needCat = drawdream_get_or_create_needitem_donate_category_id($conn);

if ($childCat <= 0 || $projCat <= 0 || $needCat <= 0) {

    http_response_code(503);

    echo json_encode(['ok' => false, 'error' => 'categories'], JSON_UNESCAPED_UNICODE);

    exit;

}



$childMap = [];

$stC = $conn->prepare('SELECT child_id, child_name FROM foundation_children WHERE foundation_id = ?');

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

     WHERE (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))"

);

if ($stP) {

    $stP->bind_param('is', $foundationId, $foundationName);

    $stP->execute();

    $rp = $stP->get_result();

    while ($x = $rp->fetch_assoc()) {

        $projectMap[(int)$x['project_id']] = (string)($x['project_name'] ?? '');

    }

}



$mode = strtolower(trim((string)($_GET['mode'] ?? 'charts')));

if ($mode === 'table') {

    $page = max(1, (int)($_GET['page'] ?? 1));

    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));

    $filters = [

        'cat' => strtolower(trim((string)($_GET['cat'] ?? 'all'))),

        'date_mode' => strtolower(trim((string)($_GET['date_mode'] ?? 'all'))),

        'date_from' => trim((string)($_GET['date_from'] ?? '')),

        'date_to' => trim((string)($_GET['date_to'] ?? '')),

        'date_month' => trim((string)($_GET['date_month'] ?? '')),

        'date_year' => trim((string)($_GET['date_year'] ?? '')),

        'week' => trim((string)($_GET['week'] ?? '')),

        'q' => trim((string)($_GET['q'] ?? '')),

    ];



    $table = foundation_dashboard_load_donation_table_page(

        $conn,

        $foundationId,

        $foundationName,

        $childCat,

        $projCat,

        $needCat,

        $childMap,

        $projectMap,

        $page,

        $perPage,

        $filters

    );



    echo json_encode(

        ['ok' => true, 'mode' => 'table'] + $table,

        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP

    );

    exit;

}



$bundle = foundation_dashboard_load_donation_bundle(
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

$periodMeta = foundation_dashboard_donation_period_meta(
    $conn,
    $foundationId,
    $foundationName,
    $childCat,
    $projCat,
    $needCat
);
$fdSponsorship = drawdream_foundation_analytics_sponsorship($conn, $foundationId, $childCat, true);

echo json_encode(
    [
        'ok' => true,
        'mode' => 'charts',
        'period_meta' => $periodMeta,
        'sponsorship' => [
            'active' => (int)($fdSponsorship['monthly']['active'] ?? 0),
            'cancel_pct' => $fdSponsorship['monthly']['cancel_pct'] ?? null,
            'denom' => (int)($fdSponsorship['monthly']['denom'] ?? 0),
        ],
    ] + $bundle,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);

