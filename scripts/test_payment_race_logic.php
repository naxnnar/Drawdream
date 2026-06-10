<?php
// scripts/test_payment_race_logic.php — ทดสอบ logic race ครบเป้า (โครงการ + needlist)
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/payment/config.php';
require_once $root . '/includes/drawdream_project_payment_finalize.php';
require_once $root . '/includes/drawdream_needlist_payment_finalize.php';

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label\n";
        $failed++;
    }
}

echo "=== DrawDream payment race logic tests ===\n\n";

assert_true(
    drawdream_project_finalize_is_goal_race(DRAWDREAM_PROJECT_FINALIZE_GOAL_MET),
    'project goal_met is race'
);
assert_true(
    drawdream_project_finalize_is_goal_race(DRAWDREAM_PROJECT_FINALIZE_GOAL_EXCEED),
    'project goal_exceed is race'
);
assert_true(
    !drawdream_project_finalize_is_goal_race(DRAWDREAM_PROJECT_FINALIZE_OK),
    'project ok is not race'
);
assert_true(
    drawdream_needlist_finalize_is_goal_race(DRAWDREAM_PROJECT_FINALIZE_GOAL_MET),
    'needlist goal_met is race'
);

$mockRefund = drawdream_omise_refund_charge('chrg_mock_test123', 50000);
assert_true(($mockRefund['object'] ?? '') === 'refund', 'mock charge refund returns refund object');
assert_true(in_array((string)($mockRefund['status'] ?? ''), ['closed', 'pending'], true), 'mock refund status ok');

$dbFile = $root . '/config/db.local.php';
if (is_file($root . '/db.php') && extension_loaded('mysqli')) {
    try {
        require_once $root . '/db.php';
    } catch (Throwable $e) {
        echo "[SKIP] DB connect failed: " . $e->getMessage() . "\n";
    }
}
if (isset($conn) && $conn instanceof mysqli) {
    echo "\n--- DB integration (read-only checks) ---\n";

    $needOpen = drawdream_needlist_sql_open_for_donation();
    $q = $conn->query(
        "SELECT fp.foundation_id,
                COALESCE(SUM(n.total_price), 0) AS goal,
                COALESCE(SUM(n.current_donate), 0) AS current
         FROM foundation_profile fp
         JOIN foundation_needlist n ON n.foundation_id = fp.foundation_id AND $needOpen
         GROUP BY fp.foundation_id
         HAVING goal > current
         LIMIT 1"
    );
    $row = $q ? $q->fetch_assoc() : null;
    if ($row) {
        $fid = (int)$row['foundation_id'];
        $remaining = drawdream_needlist_remaining_goal_baht($conn, $fid);
        assert_true($remaining > 0, "needlist fid=$fid has remaining $remaining");
        assert_true(
            drawdream_needlist_can_accept_donation_amount($conn, $fid, min(100.0, $remaining)),
            'needlist accepts amount within remaining'
        );
        assert_true(
            !drawdream_needlist_can_accept_donation_amount($conn, $fid, $remaining + 1000),
            'needlist rejects amount over remaining'
        );
    } else {
        echo "[SKIP] no open needlist with remaining goal in DB\n";
    }

    $qp = $conn->query(
        "SELECT project_id, goal_amount, current_donate
         FROM foundation_project
         WHERE goal_amount > current_donate AND project_status = 'approved'
         LIMIT 1"
    );
    $prow = $qp ? $qp->fetch_assoc() : null;
    if ($prow) {
        $pid = (int)$prow['project_id'];
        $rem = drawdream_project_remaining_goal_baht($conn, $pid);
        assert_true($rem > 0, "project pid=$pid remaining=$rem");
        assert_true(
            !drawdream_project_can_accept_donation_amount($conn, $pid, $rem + 500),
            'project rejects over remaining'
        );
    } else {
        echo "[SKIP] no open project with remaining goal in DB\n";
    }
} else {
    echo "\n[SKIP] DB not available — logic-only tests ran\n";
}

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
