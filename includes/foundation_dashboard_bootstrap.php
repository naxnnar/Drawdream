<?php
// foundation_dashboard_bootstrap.php — ข้อมูลเริ่มต้นแดชบอร์ด (async, มี cache สั้น)

require_once __DIR__ . '/foundation_dashboard_ops.php';
require_once __DIR__ . '/foundation_outcome_compliance.php';
require_once __DIR__ . '/navbar_cache.php';

function foundation_dashboard_bootstrap_cache_get(int $foundationId): ?array
{
    if ($foundationId <= 0) {
        return null;
    }

    return drawdream_navbar_cache_get('fd_boot_' . $foundationId, 120);
}

function foundation_dashboard_bootstrap_cache_set(int $foundationId, array $data): void
{
    if ($foundationId <= 0) {
        return;
    }
    drawdream_navbar_cache_set('fd_boot_' . $foundationId, $data);
}

/**
 * @param array<string, mixed> $ops
 * @return ?array{text: string, href: string, action_label: string}
 */
function foundation_dashboard_pick_next_todo_from_ops(array $ops): ?array
{
    foreach ($ops['groups'] ?? [] as $group) {
        foreach ($group['items'] ?? [] as $item) {
            $cnt = (int)($item['count'] ?? 0);
            if ($cnt <= 0 || empty($item['urgent'])) {
                continue;
            }
            $label = trim((string)($item['label'] ?? 'งานค้าง'));
            return [
                'text' => $label . ' — ' . $cnt . ' รายการที่ต้องดำเนินการ',
                'href' => (string)($item['href'] ?? '#'),
                'action_label' => 'ทำเลย',
            ];
        }
    }
    foreach ($ops['groups'] ?? [] as $group) {
        foreach ($group['items'] ?? [] as $item) {
            $cnt = (int)($item['count'] ?? 0);
            if ($cnt <= 0) {
                continue;
            }
            $label = trim((string)($item['label'] ?? 'งาน'));
            return [
                'text' => $label . ' — ' . $cnt . ' รายการ',
                'href' => (string)($item['href'] ?? '#'),
                'action_label' => 'ดูรายการ',
            ];
        }
    }

    return null;
}

/**
 * @return array{
 *   account_status: int,
 *   account_paused: bool,
 *   next_todo: ?array{text:string,href:string,action_label:string},
 *   ops: array<string,mixed>,
 *   pause: ?array{summary_text:string,bulk_href:string},
 *   counts: array{children:int,projects:int,need_items:int,active_sponsors:int}
 * }
 */
function foundation_dashboard_load_bootstrap(
    mysqli $conn,
    int $uid,
    int $foundationId,
    string $foundationName
): array {
    $cached = foundation_dashboard_bootstrap_cache_get($foundationId);
    if ($cached !== null) {
        return $cached;
    }

    $accountStatus = drawdream_foundation_sync_account_verified_from_db($conn, $uid);
    $accountPaused = ($accountStatus === DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED);

    $pause = null;
    if ($accountPaused) {
        $summary = drawdream_foundation_outcome_compliance_summarize(
            drawdream_foundation_outcome_compliance_obligations($conn, $foundationId, $foundationName)
        );
        $pause = [
            'summary_text' => drawdream_foundation_outcome_compliance_summary_text($summary['counts']),
            'bulk_href' => drawdream_foundation_outcome_compliance_bulk_link($summary['counts']),
        ];
    }

    $fdOps = foundation_dashboard_build_ops_stats($conn, $foundationId, $foundationName);
    $nextTodo = foundation_dashboard_pick_next_todo_from_ops($fdOps);

    $fn = trim($foundationName);
    $projScope = '(foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))';
    $counts = ['children' => 0, 'projects' => 0, 'need_items' => 0, 'active_sponsors' => (int)($fdOps['active_sponsors'] ?? 0)];

    $stCounts = $conn->prepare(
        "SELECT
            (SELECT COUNT(*) FROM foundation_children WHERE foundation_id = ?) AS children,
            (SELECT COUNT(*) FROM foundation_project WHERE {$projScope}) AS projects,
            (SELECT COUNT(*) FROM foundation_needlist WHERE foundation_id = ?) AS need_items"
    );
    if ($stCounts) {
        $stCounts->bind_param('iisi', $foundationId, $foundationId, $fn, $foundationId);
        $stCounts->execute();
        $row = $stCounts->get_result()->fetch_assoc() ?: [];
        $counts['children'] = (int)($row['children'] ?? 0);
        $counts['projects'] = (int)($row['projects'] ?? 0);
        $counts['need_items'] = (int)($row['need_items'] ?? 0);
    }

    $result = [
        'account_status' => $accountStatus,
        'account_paused' => $accountPaused,
        'next_todo' => $nextTodo,
        'ops' => $fdOps,
        'pause' => $pause,
        'counts' => $counts,
    ];

    foundation_dashboard_bootstrap_cache_set($foundationId, $result);

    return $result;
}
