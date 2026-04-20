<?php
// includes/foundation_analytics_report_html.php — HTML เนื้อหารายงานเชิงวิเคราะห์มูลนิธิ (ใช้ร่วมแอดมิน PDF / หน้าดู / มูลนิธิ)
declare(strict_types=1);

require_once __DIR__ . '/foundation_analytics.php';

function drawdream_foundation_analytics_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * คืน HTML fragment (เนื้อหาใน &lt;div class="sheet"&gt; หรือใน PDF) หรือ null ถ้าไม่มีมูลนิธิ
 */
function drawdream_foundation_analytics_report_html_fragment(mysqli $conn, int $foundationId): ?string
{
    if ($foundationId <= 0) {
        return null;
    }

    $fp = drawdream_foundation_analytics_profile($conn, $foundationId);
    if (!$fp) {
        return null;
    }

    $foundationName = trim((string)($fp['foundation_name'] ?? ''));
    $verified = (int)($fp['account_verified'] ?? 0) === 1;
    $verifiedLabel = $verified ? 'ยืนยันบัญชีแล้ว' : 'รออนุมัติบัญชี';

    $totals = drawdream_foundation_analytics_totals($conn, $foundationId, $foundationName);
    $childCat = (int)($totals['childCat'] ?? 0);
    $popular = drawdream_foundation_analytics_popular_categories($conn, $foundationId, $foundationName);
    $sponsorship = drawdream_foundation_analytics_sponsorship($conn, $foundationId, $childCat);

    $m = $sponsorship['monthly'];
    $allp = $sponsorship['all_plans'];
    $monthlyPct = $m['cancel_pct'];
    $allPct = $allp['cancel_pct'];

    $genDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('d/m/Y H:i');

    $htmlBody = '';
    $htmlBody .= '<style>
h1{margin:0;font-size:18pt;color:#4A5BA8;}
h2{margin:12px 0 6px;font-size:12pt;color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:4px;}
.kpi-wrap{width:100%;margin-top:10px;}
.kpi-cell{border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#fafafa;}
.kpi-val{font-size:16pt;font-weight:bold;color:#4A5BA8;}
.kpi-lab{font-size:9pt;color:#6b7280;}
.big-stat{font-size:22pt;font-weight:bold;color:#4A5BA8;}
.note{font-size:8.5pt;color:#6b7280;}
.bar-row{margin:4px 0;}
.bar-bg{background:#f3f4f6;border-radius:4px;height:14px;width:100%;}
.bar-fill{background:#4A5BA8;height:14px;border-radius:4px;}
table.meta{width:100%;font-size:9pt;color:#4b5563;margin-bottom:8px;}
</style>';

    $htmlBody .= '<table class="header-bar" cellpadding="10" cellspacing="0" style="width:100%;background-color:#4A5BA8;color:#ffffff;border-radius:6px;"><tr><td>';
    $htmlBody .= '<strong style="font-size:14pt;">รายงานเชิงวิเคราะห์ · ' . drawdream_foundation_analytics_h($foundationName) . '</strong><br>';
    $htmlBody .= '<span style="font-size:10pt;opacity:.95;">' . drawdream_foundation_analytics_h($verifiedLabel) . ' · พิมพ์เมื่อ ' . drawdream_foundation_analytics_h($genDate) . ' น.</span>';
    $htmlBody .= '</td></tr></table>';

    $htmlBody .= '<table class="meta"><tr><td>เอกสารนี้สรุปยอดบริจาคที่บันทึกเป็นสำเร็จ (completed) เข้ามูลนิธิ และสถานะอุปการะเด็กจากแถว subscription ในฐานข้อมูล</td></tr></table>';

    $htmlBody .= '<table class="kpi-wrap" cellspacing="6" cellpadding="0"><tr>';
    $htmlBody .= '<td class="kpi-cell" width="25%"><div class="kpi-val">' . drawdream_foundation_analytics_h(number_format($totals['sumTotal'], 2)) . '</div><div class="kpi-lab">ยอดรวมทั้งหมด (บาท)</div></td>';
    $htmlBody .= '<td class="kpi-cell" width="25%"><div class="kpi-val">' . (int)$totals['donationRowCount'] . '</div><div class="kpi-lab">จำนวนรายการบริจาค (completed)</div></td>';
    $htmlBody .= '<td class="kpi-cell" width="25%"><div class="kpi-val">' . (int)$totals['cntChildProfiles'] . '</div><div class="kpi-lab">เด็กในระบบ (คน)</div></td>';
    $htmlBody .= '<td class="kpi-cell" width="25%"><div class="kpi-val">' . (int)$totals['cntProjects'] . '</div><div class="kpi-lab">โครงการ (รายการ)</div></td>';
    $htmlBody .= '</tr><tr>';
    $htmlBody .= '<td class="kpi-cell"><div class="kpi-val">' . drawdream_foundation_analytics_h(number_format($totals['sumChild'], 2)) . '</div><div class="kpi-lab">ยอดเด็ก (บาท)</div></td>';
    $htmlBody .= '<td class="kpi-cell"><div class="kpi-val">' . drawdream_foundation_analytics_h(number_format($totals['sumProject'], 2)) . '</div><div class="kpi-lab">ยอดโครงการ (บาท)</div></td>';
    $htmlBody .= '<td class="kpi-cell"><div class="kpi-val">' . drawdream_foundation_analytics_h(number_format($totals['sumNeed'], 2)) . '</div><div class="kpi-lab">ยอดสิ่งของ (บาท)</div></td>';
    $htmlBody .= '<td class="kpi-cell"><div class="kpi-val">' . (int)$totals['needItemCnt'] . '</div><div class="kpi-lab">รายการสิ่งของ (รายการ)</div></td>';
    $htmlBody .= '</tr></table>';

    $htmlBody .= '<h2>Retention Rate — อุปการะเด็กแบบรายเดือน (Sponsorship)</h2>';
    $htmlBody .= '<p class="note">คำนวณจากแถว <strong>donation.donate_type = child_subscription</strong> ที่เชื่อมกับเด็กของมูลนิธินี้ และ <strong>recurring_plan_code = monthly</strong><br>';
    $htmlBody .= 'อัตราการยกเลิก = จำนวนที่สถานะ <code>cancelled</code> ÷ (cancelled + active + paused) × 100</p>';

    if ($monthlyPct === null) {
        $htmlBody .= '<p class="big-stat">—</p><p class="note">ยังไม่มีข้อมูลแผนรายเดือน (monthly) สำหรับมูลนิธินี้</p>';
    } else {
        $htmlBody .= '<p class="big-stat">' . drawdream_foundation_analytics_h((string)$monthlyPct) . '%</p>';
        $htmlBody .= '<p class="note">ยกเลิก ' . (int)$m['cancelled'] . ' ฉบับ · ยังดำเนินอยู่ (active) ' . (int)$m['active'] . ' · พักชั่วคราว (paused) ' . (int)$m['paused'];
        if ((int)$m['other'] > 0) {
            $htmlBody .= ' · สถานะอื่น ' . (int)$m['other'];
        }
        $htmlBody .= ' · รวมในสูตร ' . (int)$m['denom'] . ' ฉบับ</p>';
    }

    $htmlBody .= '<h2>อุปการะทุกแผน (อ้างอิง)</h2>';
    if ($allPct === null) {
        $htmlBody .= '<p class="note">ไม่มีแถว subscription ที่เข้าเงื่อนไข</p>';
    } else {
        $htmlBody .= '<p><strong>อัตราการยกเลิกรวมทุกแผน:</strong> ' . drawdream_foundation_analytics_h((string)$allPct) . '% ';
        $htmlBody .= '(ยกเลิก ' . (int)$allp['cancelled'] . ' / รวม ' . (int)$allp['denom'] . ' ฉบับ)</p>';
    }

    $htmlBody .= '<h2>Popular Categories (ยอดบริจาคตามหมวดหมู่)</h2>';
    $htmlBody .= '<p class="note">จัดเรียงตามยอดเงิน — ใช้จัดลำดับความสำคัญเนื้อหาหน้าแรก (เด็ก / โครงการ / สิ่งของ)</p>';

    if ($popular === []) {
        $htmlBody .= '<p class="note">ยังไม่มียอดบริจาค completed ในช่วงข้อมูลนี้</p>';
    } else {
        $maxAmt = max(array_column($popular, 'amount')) ?: 1.0;
        $htmlBody .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:9pt;">';
        $htmlBody .= '<thead><tr style="background-color:#4A5BA8;color:#fff;"><th>หมวดหมู่</th><th align="right">ยอด (บาท)</th><th align="right">%</th><th align="right">จำนวนรายการ</th><th>สัดส่วน (แถบ)</th></tr></thead><tbody>';
        foreach ($popular as $row) {
            $w = (int) round(((float)$row['amount'] / $maxAmt) * 100);
            $htmlBody .= '<tr>';
            $htmlBody .= '<td>' . drawdream_foundation_analytics_h((string)$row['label']) . '</td>';
            $htmlBody .= '<td align="right">' . drawdream_foundation_analytics_h(number_format((float)$row['amount'], 2)) . '</td>';
            $htmlBody .= '<td align="right">' . drawdream_foundation_analytics_h((string)$row['pct']) . '</td>';
            $htmlBody .= '<td align="right">' . (int)$row['count'] . '</td>';
            $rest = max(0, 100 - $w);
            $htmlBody .= '<td><table cellpadding="0" cellspacing="0" style="width:100%;height:12px;"><tr>';
            $htmlBody .= '<td style="background-color:#4A5BA8;width:' . $w . '%;"></td>';
            $htmlBody .= '<td style="width:' . $rest . '%;background-color:#f3f4f6;"></td>';
            $htmlBody .= '</tr></table></td>';
            $htmlBody .= '</tr>';
        }
        $htmlBody .= '</tbody></table>';
    }

    $htmlBody .= '<p style="margin-top:18px;font-size:8pt;color:#9ca3af;">DrawDream Admin · รหัสมูลนิธิ ' . $foundationId . '</p>';

    return $htmlBody;
}
