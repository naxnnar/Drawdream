<?php
// includes/donation_stats_panel.php — ตัวเลขสำหรับแผง donation-stats-panel (ผู้อุปการะ / สะสม / เดือนปฏิทิน / ทุนการศึกษา)
// สรุปสั้น: คำนวณยอดสถิติบริจาคเพื่อแสดงในการ์ดสรุปหน้าเด็ก/โปรไฟล์
declare(strict_types=1);

/**
 * @return array{donor_count:int,total_amount:float,month_amount:float,education_fund:float}
 */
function drawdream_donation_stats_panel_values(mysqli $conn, int $categoryId, int $targetId): array
{
    $empty = ['donor_count' => 0, 'total_amount' => 0.0, 'month_amount' => 0.0, 'education_fund' => 0.0];
    if ($categoryId <= 0 || $targetId <= 0) {
        return $empty;
    }

    $st = $conn->prepare(
        "SELECT COUNT(DISTINCT donor_id) AS donor_count, COALESCE(SUM(amount), 0) AS total_amount
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'"
    );
    if (!$st) {
        return $empty;
    }
    $st->bind_param('ii', $categoryId, $targetId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $donorCount = (int)($r['donor_count'] ?? 0);
    $totalAmount = (float)($r['total_amount'] ?? 0);

    $tz = new DateTimeZone('Asia/Bangkok');
    $now = new DateTimeImmutable('now', $tz);
    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $monthEnd = $monthStart->modify('+1 month');
    $ms = $monthStart->format('Y-m-d H:i:s');
    $me = $monthEnd->format('Y-m-d H:i:s');

    $st2 = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS t
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'
           AND transfer_datetime >= ? AND transfer_datetime < ?"
    );
    $monthAmount = 0.0;
    if ($st2) {
        $st2->bind_param('iiss', $categoryId, $targetId, $ms, $me);
        $st2->execute();
        $r2 = $st2->get_result()->fetch_assoc();
        $monthAmount = (float)($r2['t'] ?? 0);
    }

    $st3 = $conn->prepare(
        "SELECT COALESCE(SUM(CASE WHEN amount > 700 THEN amount - 700 ELSE 0 END), 0) AS t
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'
           AND COALESCE(donate_type, '') NOT IN ('child_subscription', 'child_subscription_charge')"
    );
    $educationFund = 0.0;
    if ($st3) {
        $st3->bind_param('ii', $categoryId, $targetId);
        $st3->execute();
        $r3 = $st3->get_result()->fetch_assoc();
        $educationFund = (float)($r3['t'] ?? 0);
    }

    return [
        'donor_count' => $donorCount,
        'total_amount' => $totalAmount,
        'month_amount' => $monthAmount,
        'education_fund' => $educationFund,
    ];
}

/**
 * @param array{donor_count:int,total_amount:float,month_amount:float,education_fund:float} $vals
 */
function drawdream_render_donation_stats_panel(array $vals, string $ariaLabel = 'สรุปตัวเลขการบริจาค', ?string $panelDomId = null): void
{
    $dc = (int)($vals['donor_count'] ?? 0);
    $tot = (float)($vals['total_amount'] ?? 0);
    $mon = (float)($vals['month_amount'] ?? 0);
    $edu = (float)($vals['education_fund'] ?? 0);
    $idAttr = ($panelDomId !== null && $panelDomId !== '')
        ? ' id="' . htmlspecialchars($panelDomId, ENT_QUOTES, 'UTF-8') . '"'
        : '';
    $aria = htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8');
    ?>
                    <div class="donation-stats-panel"<?= $idAttr ?> aria-label="<?= $aria ?>" style="margin-top:14px;">
                        <div class="stats-row">
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-heart-fill"></i></div>
                                <div class="stat-num"><?= $dc ?></div>
                                <div class="stat-label">ผู้อุปการะทั้งหมด</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-piggy-bank-fill"></i></div>
                                <div class="stat-num"><?= number_format($tot, 0, '.', ',') ?></div>
                                <div class="stat-label">ยอดสะสม (บาท)</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-stars"></i></div>
                                <div class="stat-num"><?= number_format($mon, 0, '.', ',') ?></div>
                                <div class="stat-label">เดือนนี้ (ปฏิทิน, บาท)</div>
                            </div>
                            <div class="stat-box stat-box--education-fund">
                                <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
                                <div class="stat-num"><?= number_format($edu, 0, '.', ',') ?></div>
                                <div class="stat-label">ทุนการศึกษา (ส่วนเกิน 700 บ. / ครั้ง รายวัน)</div>
                            </div>
                        </div>
                    </div>
    <?php
}
