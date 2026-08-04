<?php
// includes/escrow_funds_schema.php — ตาราง escrow_funds
// โครงการ: 1 แถวต่อ project_id | สิ่งของ: 1 แถวต่อ foundation_id (need_foundation)
declare(strict_types=1);

/** donate_id=0 = แถวสรุปยอดรวมต่อ target */
const DRAWDREAM_ESCROW_SUMMARY_DONATE_ID = 0;

/** @return list<string> */
function drawdream_escrow_target_types(): array
{
    return ['project', 'need_foundation'];
}

function drawdream_escrow_has_index(mysqli $conn, string $table, string $indexName): bool
{
    $tableEsc = mysqli_real_escape_string($conn, $table);
    $idxEsc = mysqli_real_escape_string($conn, $indexName);
    $q = @$conn->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$idxEsc}'");
    return (bool)($q && $q->num_rows > 0);
}

function drawdream_escrow_has_column(mysqli $conn, string $table, string $columnName): bool
{
    $tableEsc = mysqli_real_escape_string($conn, $table);
    $colEsc = mysqli_real_escape_string($conn, $columnName);
    $q = @$conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
    return (bool)($q && $q->num_rows > 0);
}

function drawdream_escrow_ensure_target_type_enum(mysqli $conn): void
{
    @$conn->query(
        "ALTER TABLE escrow_funds
         MODIFY COLUMN target_type ENUM('project','need_item','need_foundation') NOT NULL DEFAULT 'project'"
    );
}

function drawdream_escrow_funds_ensure_schema(mysqli $conn): void
{
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    static $ready = false;
    if ($ready) {
        return;
    }

    $r = @$conn->query("SHOW TABLES LIKE 'escrow_funds'");
    if (!$r || $r->num_rows === 0) {
        drawdream_escrow_funds_bootstrap_table($conn);
        drawdream_escrow_funds_run_migrations($conn);
    }

    $ready = true;
}

/** สร้างตาราง escrow_funds ครั้งแรก (ไม่รัน backfill บน hot path) */
function drawdream_escrow_funds_bootstrap_table(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS `escrow_funds` (
            `escrow_id` INT NOT NULL AUTO_INCREMENT,
            `target_type` ENUM('project','need_item','need_foundation') NOT NULL DEFAULT 'project',
            `target_id` INT NOT NULL DEFAULT 0,
            `donate_id` INT NOT NULL DEFAULT 0,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('holding','released','refunded') NOT NULL DEFAULT 'holding',
            `released_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`escrow_id`),
            UNIQUE KEY `uq_escrow_target` (`target_type`, `target_id`),
            KEY `idx_escrow_target_status` (`target_type`, `target_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @$conn->query($sql);
}

/** migration/backfill หนัก — เรียกจาก db.php boot เท่านั้น ไม่ใช่ทุก request */
function drawdream_escrow_funds_run_migrations(mysqli $conn): void
{
    require_once __DIR__ . '/drawdream_schema_once.php';
    drawdream_schema_once('escrow_funds_migrations', static function (mysqli $c): void {
        drawdream_escrow_funds_run_migrations_inner($c);
    }, $conn);
}

/** @internal */
function drawdream_escrow_funds_run_migrations_inner(mysqli $conn): void
{
    drawdream_escrow_ensure_target_type_enum($conn);

    if (($c = @$conn->query("SHOW COLUMNS FROM escrow_funds LIKE 'target_id'")) && $c->num_rows === 0) {
        @$conn->query("ALTER TABLE escrow_funds ADD COLUMN target_id INT NOT NULL DEFAULT 0 AFTER target_type");
    }
    @$conn->query("UPDATE escrow_funds SET target_type = 'project' WHERE target_type IS NULL OR TRIM(target_type) = ''");
    if (drawdream_escrow_has_column($conn, 'escrow_funds', 'project_id')) {
        @$conn->query("UPDATE escrow_funds SET target_id = project_id WHERE target_id = 0 AND project_id > 0");
    }

    drawdream_escrow_migrate_to_summary_model($conn);
    drawdream_escrow_migrate_needlist_to_foundation_model($conn);
    drawdream_escrow_backfill_summary_holdings($conn);

    if (!drawdream_escrow_has_index($conn, 'escrow_funds', 'idx_escrow_target_status')) {
        @$conn->query("ALTER TABLE escrow_funds ADD KEY idx_escrow_target_status (target_type, target_id, status)");
    }
    if (drawdream_escrow_has_index($conn, 'escrow_funds', 'idx_escrow_project_status')) {
        @$conn->query("ALTER TABLE escrow_funds DROP INDEX idx_escrow_project_status");
    }
    if (drawdream_escrow_has_column($conn, 'escrow_funds', 'project_id')) {
        @$conn->query("ALTER TABLE escrow_funds DROP COLUMN project_id");
    }
    if (drawdream_escrow_has_column($conn, 'escrow_funds', 'created_at')) {
        @$conn->query('ALTER TABLE escrow_funds DROP COLUMN created_at');
    }
    if (drawdream_escrow_has_column($conn, 'escrow_funds', 'omise_charge_id')) {
        @$conn->query('ALTER TABLE escrow_funds DROP COLUMN omise_charge_id');
    }
}

function drawdream_escrow_migrate_to_summary_model(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $t = @$conn->query("SHOW TABLES LIKE 'escrow_funds'");
    if (!$t || $t->num_rows === 0) {
        return;
    }

    if (drawdream_escrow_has_index($conn, 'escrow_funds', 'uq_escrow_target_donate')) {
        @$conn->query('ALTER TABLE escrow_funds DROP INDEX uq_escrow_target_donate');
    }
    if (drawdream_escrow_has_index($conn, 'escrow_funds', 'idx_escrow_donate_id')) {
        @$conn->query('ALTER TABLE escrow_funds DROP INDEX idx_escrow_donate_id');
    }

    @$conn->query('DELETE FROM escrow_funds WHERE donate_id > 0');

    if (!drawdream_escrow_has_index($conn, 'escrow_funds', 'uq_escrow_target')) {
        @$conn->query(
            'DELETE ef1 FROM escrow_funds ef1
             INNER JOIN escrow_funds ef2
               ON ef1.target_type = ef2.target_type
              AND ef1.target_id = ef2.target_id
              AND ef1.escrow_id < ef2.escrow_id'
        );
        @$conn->query('ALTER TABLE escrow_funds ADD UNIQUE KEY uq_escrow_target (target_type, target_id)');
    }
}

/** สิ่งของ: รวมเป็น 1 แถวต่อมูลนิธิ (need_foundation) */
function drawdream_escrow_migrate_needlist_to_foundation_model(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    drawdream_escrow_ensure_target_type_enum($conn);
    @$conn->query("DELETE FROM escrow_funds WHERE target_type = 'need_item'");

    $fids = @$conn->query(
        "SELECT DISTINCT foundation_id FROM foundation_needlist
         WHERE service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at, '')) <> ''"
    );
    if ($fids) {
        while ($row = $fids->fetch_assoc()) {
            $fid = (int)($row['foundation_id'] ?? 0);
            if ($fid > 0) {
                drawdream_escrow_sync_summary_holding_for_need_foundation($conn, $fid);
                drawdream_escrow_maybe_release_need_foundation($conn, $fid);
            }
        }
    }
}

function drawdream_escrow_backfill_summary_holdings(mysqli $conn): void
{
    $proj = @$conn->query(
        "SELECT project_id FROM foundation_project
         WHERE service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at, '')) <> ''
           AND project_status = 'completed'"
    );
    if ($proj) {
        while ($row = $proj->fetch_assoc()) {
            drawdream_escrow_sync_summary_holding_for_project($conn, (int)($row['project_id'] ?? 0));
        }
    }

    $fids = @$conn->query(
        "SELECT DISTINCT foundation_id FROM foundation_needlist
         WHERE service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at, '')) <> ''"
    );
    if ($fids) {
        while ($row = $fids->fetch_assoc()) {
            $fid = (int)($row['foundation_id'] ?? 0);
            if ($fid > 0) {
                drawdream_escrow_sync_summary_holding_for_need_foundation($conn, $fid);
            }
        }
    }

    $relProj = @$conn->query(
        "SELECT project_id FROM foundation_project WHERE project_status IN ('purchasing', 'done')"
    );
    if ($relProj) {
        while ($row = $relProj->fetch_assoc()) {
            drawdream_escrow_funds_release_holding_for_project($conn, (int)($row['project_id'] ?? 0));
        }
    }

    $allFids = @$conn->query('SELECT DISTINCT foundation_id FROM foundation_needlist');
    if ($allFids) {
        while ($row = $allFids->fetch_assoc()) {
            $fid = (int)($row['foundation_id'] ?? 0);
            if ($fid > 0) {
                drawdream_escrow_maybe_release_need_foundation($conn, $fid);
            }
        }
    }
}

/** ยอดสมทบทุนสิ่งของรวมทั้งมูลนิธิ (รายการที่ชำระค่าบริการแล้ว) */
function drawdream_escrow_need_foundation_holding_amount(mysqli $conn, int $foundationId): float
{
    if ($foundationId <= 0) {
        return 0.0;
    }
    $st = $conn->prepare(
        "SELECT COALESCE(SUM(current_donate), 0) AS total
         FROM foundation_needlist
         WHERE foundation_id = ?
           AND service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at, '')) <> ''
           AND approve_item IN ('approved', 'purchasing', 'done')"
    );
    if (!$st) {
        return 0.0;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    return (float)($row['total'] ?? 0);
}

function drawdream_escrow_upsert_summary_holding(
    mysqli $conn,
    string $targetType,
    int $targetId,
    float $amountBaht
): bool {
    $targetType = strtolower(trim($targetType));
    if (!in_array($targetType, drawdream_escrow_target_types(), true) || $targetId <= 0) {
        return false;
    }

    drawdream_escrow_funds_ensure_schema($conn);

    $chk = $conn->prepare(
        'SELECT status FROM escrow_funds WHERE target_type = ? AND target_id = ? LIMIT 1'
    );
    if ($chk) {
        $chk->bind_param('si', $targetType, $targetId);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        if (is_array($existing) && strtolower((string)($existing['status'] ?? '')) === 'released') {
            return true;
        }
    }

    $donateId = DRAWDREAM_ESCROW_SUMMARY_DONATE_ID;
    $ins = $conn->prepare(
        "INSERT INTO escrow_funds (target_type, target_id, donate_id, amount, status)
         VALUES (?, ?, ?, ?, 'holding')
         ON DUPLICATE KEY UPDATE
            amount = VALUES(amount),
            donate_id = VALUES(donate_id),
            status = IF(status = 'released', status, 'holding')"
    );
    if (!$ins) {
        return false;
    }
    $ins->bind_param('siid', $targetType, $targetId, $donateId, $amountBaht);

    return (bool)$ins->execute();
}

function drawdream_escrow_sync_summary_holding_for_project(mysqli $conn, int $projectId): bool
{
    if ($projectId <= 0) {
        return false;
    }

    $st = $conn->prepare(
        'SELECT current_donate, service_charge_paid_at, project_status
         FROM foundation_project WHERE project_id = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $projectId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }

    $status = strtolower(trim((string)($row['project_status'] ?? '')));
    if (in_array($status, ['purchasing', 'done'], true)) {
        drawdream_escrow_funds_release_holding_for_project($conn, $projectId);
        return true;
    }

    if (empty($row['service_charge_paid_at']) || $status !== 'completed') {
        return true;
    }

    return drawdream_escrow_upsert_summary_holding(
        $conn,
        'project',
        $projectId,
        (float)($row['current_donate'] ?? 0)
    );
}

/** สร้าง/อัปเดตแถว holding สิ่งของรวมทั้งมูลนิธิ — เรียกหลังชำระค่าบริการ */
function drawdream_escrow_sync_summary_holding_for_need_foundation(mysqli $conn, int $foundationId): bool
{
    if ($foundationId <= 0) {
        return false;
    }

    $cntSt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM foundation_needlist
         WHERE foundation_id = ?
           AND service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at, '')) <> ''"
    );
    if (!$cntSt) {
        return false;
    }
    $cntSt->bind_param('i', $foundationId);
    $cntSt->execute();
    $paidCnt = (int)($cntSt->get_result()->fetch_assoc()['cnt'] ?? 0);
    if ($paidCnt <= 0) {
        return true;
    }

    $amount = drawdream_escrow_need_foundation_holding_amount($conn, $foundationId);
    if ($amount <= 0) {
        return true;
    }

    return drawdream_escrow_upsert_summary_holding($conn, 'need_foundation', $foundationId, $amount);
}

/** @deprecated ใช้ sync ระดับมูลนิธิ — คงไว้เพื่อ caller เดิมที่ส่ง item_id */
function drawdream_escrow_sync_summary_holding_for_need_item(mysqli $conn, int $itemId): bool
{
    if ($itemId <= 0) {
        return false;
    }
    $st = $conn->prepare('SELECT foundation_id FROM foundation_needlist WHERE item_id = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $itemId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $foundationId = (int)($row['foundation_id'] ?? 0);
    if ($foundationId <= 0) {
        return false;
    }

    return drawdream_escrow_sync_summary_holding_for_need_foundation($conn, $foundationId);
}

/** ปล่อย escrow สิ่งของเมื่อรายการที่จ่ายค่าบริการแล้วทุกรายการเป็น done */
function drawdream_escrow_maybe_release_need_foundation(mysqli $conn, int $foundationId): int
{
    if ($foundationId <= 0) {
        return 0;
    }

    $st = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM foundation_needlist
         WHERE foundation_id = ?
           AND service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at, '')) <> ''
           AND approve_item <> 'done'"
    );
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $pending = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
    if ($pending > 0) {
        return 0;
    }

    $paidSt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM foundation_needlist
         WHERE foundation_id = ?
           AND service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at, '')) <> ''"
    );
    if (!$paidSt) {
        return 0;
    }
    $paidSt->bind_param('i', $foundationId);
    $paidSt->execute();
    $paidCnt = (int)($paidSt->get_result()->fetch_assoc()['cnt'] ?? 0);
    if ($paidCnt <= 0) {
        return 0;
    }

    return drawdream_escrow_funds_release_holding_for_target($conn, 'need_foundation', $foundationId);
}

function drawdream_escrow_project_holding_total_display(mysqli $conn): float
{
    drawdream_escrow_funds_ensure_schema($conn);

    $sumR = @$conn->query(
        "SELECT COALESCE(SUM(amount), 0) AS total
         FROM escrow_funds
         WHERE status = 'holding' AND target_type = 'project'"
    );
    if ($sumR && ($sr = $sumR->fetch_assoc())) {
        return (float)($sr['total'] ?? 0);
    }

    return 0.0;
}

function drawdream_escrow_need_item_holding_total_display(mysqli $conn): float
{
    drawdream_escrow_funds_ensure_schema($conn);

    $sumR = @$conn->query(
        "SELECT COALESCE(SUM(amount), 0) AS total
         FROM escrow_funds
         WHERE status = 'holding' AND target_type = 'need_foundation'"
    );
    if ($sumR && ($sr = $sumR->fetch_assoc())) {
        return (float)($sr['total'] ?? 0);
    }

    return 0.0;
}

function drawdream_escrow_funds_release_holding_for_target(mysqli $conn, string $targetType, int $targetId): int
{
    $targetType = strtolower(trim($targetType));
    if (!in_array($targetType, drawdream_escrow_target_types(), true) || $targetId <= 0) {
        return 0;
    }
    drawdream_escrow_funds_ensure_schema($conn);
    $st = $conn->prepare(
        "UPDATE escrow_funds SET status = 'released', released_at = NOW()
         WHERE target_type = ? AND target_id = ? AND status = 'holding'"
    );
    if (!$st) {
        return 0;
    }
    $st->bind_param('si', $targetType, $targetId);
    $st->execute();

    return (int)$st->affected_rows;
}

function drawdream_escrow_funds_release_holding_for_project(mysqli $conn, int $project_id): int
{
    return drawdream_escrow_funds_release_holding_for_target($conn, 'project', $project_id);
}

/** @deprecated ใช้ maybe_release ระดับมูลนิธิ */
function drawdream_escrow_funds_release_holding_for_need_item(mysqli $conn, int $item_id): int
{
    if ($item_id <= 0) {
        return 0;
    }
    $st = $conn->prepare('SELECT foundation_id FROM foundation_needlist WHERE item_id = ? LIMIT 1');
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $item_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $foundationId = (int)($row['foundation_id'] ?? 0);
    if ($foundationId <= 0) {
        return 0;
    }

    return drawdream_escrow_maybe_release_need_foundation($conn, $foundationId);
}
