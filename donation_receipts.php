<?php
// donation_receipts.php — รวมใบเสร็จอิเล็กทรอนิกส์ทั้งหมดของผู้บริจาค
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'donor' && ($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: login.php');
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);

$sql = "
    SELECT d.donate_id, d.amount, d.transfer_datetime, d.omise_charge_id,
           dc.project_donate, dc.needitem_donate, dc.child_donate,
           fc.child_name AS child_name_by_target,
           p.project_name AS project_name_by_target,
           fp.foundation_name AS foundation_name_by_target
    FROM donation d
    INNER JOIN donate_category dc ON d.category_id = dc.category_id
    LEFT JOIN foundation_children fc ON fc.child_id = d.target_id AND fc.deleted_at IS NULL
    LEFT JOIN foundation_project p ON p.project_id = d.target_id AND p.deleted_at IS NULL
    LEFT JOIN foundation_profile fp ON fp.foundation_id = d.target_id
    WHERE d.donor_id = ? AND LOWER(TRIM(d.payment_status)) = 'completed'
    ORDER BY d.transfer_datetime DESC
    LIMIT 500
";
$st = $conn->prepare($sql);
$rows = [];
if ($st) {
    $st->bind_param('i', $uid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/includes/donate_category_resolve.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบเสร็จอิเล็กทรอนิกส์ทั้งหมด | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        body { background:#f7ecde; margin:0; font-family:'Prompt','Sarabun',sans-serif; }
        .receipts-wrap { max-width: 980px; margin: 24px auto; padding: 0 16px 48px; }
        .receipts-head { display:flex; justify-content:flex-start; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
        .receipts-head h1 { margin:0; font-size:1.5rem; color:#1f2937; }
        .receipts-card { background:#fff; border-radius:14px; box-shadow:0 6px 18px rgba(15,23,42,.08); overflow:hidden; }
        .receipts-row { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:14px 16px; border-bottom:1px solid #eef0f4; }
        .receipts-row:last-child { border-bottom:none; }
        .receipts-main { min-width:0; }
        .receipts-title { font-weight:700; color:#1f2937; margin-bottom:3px; }
        .receipts-meta { font-size:.88rem; color:#64748b; }
        .receipts-amt { font-weight:800; color:#c0392b; margin-left:10px; }
        .receipts-btn { display:inline-flex; align-items:center; justify-content:center; padding:8px 14px; border-radius:999px; background:#eef3ff; border:1px solid #d4ddf7; color:#2f4b93; text-decoration:none; font-weight:700; white-space:nowrap; }
        .receipts-btn:hover { background:#e5edff; color:#233d80; }
        .receipts-empty { padding:26px 18px; text-align:center; color:#94a3b8; }
        @media (max-width: 700px) {
            .receipts-row { flex-direction:column; align-items:flex-start; }
            .receipts-btn { width:100%; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="receipts-wrap">
    <div class="receipts-head">
        <h1>ใบเสร็จอิเล็กทรอนิกส์ทั้งหมด</h1>
    </div>
    <div class="receipts-card">
        <?php if (empty($rows)): ?>
            <div class="receipts-empty">ยังไม่มีรายการบริจาคที่ออกใบเสร็จ</div>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <?php
                    $title = 'บริจาค';
                    if (drawdream_donate_cat_label_is_active($r['child_donate'] ?? null) && trim((string)($r['child_name_by_target'] ?? '')) !== '') {
                        $title = 'อุปการะเด็ก — ' . (string)$r['child_name_by_target'];
                    } elseif (drawdream_donate_cat_label_is_active($r['project_donate'] ?? null) && trim((string)($r['project_name_by_target'] ?? '')) !== '') {
                        $title = 'บริจาคโครงการ — ' . (string)$r['project_name_by_target'];
                    } elseif (drawdream_donate_cat_label_is_active($r['needitem_donate'] ?? null) && trim((string)($r['foundation_name_by_target'] ?? '')) !== '') {
                        $title = 'บริจาครายการสิ่งของ — ' . (string)$r['foundation_name_by_target'];
                    }
                ?>
                <div class="receipts-row">
                    <div class="receipts-main">
                        <div class="receipts-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="receipts-meta">
                            <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$r['transfer_datetime'])), ENT_QUOTES, 'UTF-8') ?>
                            <?php if (trim((string)($r['omise_charge_id'] ?? '')) !== ''): ?>
                                · <?= htmlspecialchars((string)$r['omise_charge_id'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                            <span class="receipts-amt"><?= number_format((float)($r['amount'] ?? 0), 2) ?> บาท</span>
                        </div>
                    </div>
                    <a class="receipts-btn" href="donation_receipt.php?donate_id=<?= (int)($r['donate_id'] ?? 0) ?>">ดูใบเสร็จอิเล็กทรอนิกส์</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
